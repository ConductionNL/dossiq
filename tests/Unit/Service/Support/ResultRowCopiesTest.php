<?php

/**
 * How the dossier, objection, starter, workflow, survivor, relation, sub-case, receipt and event readers turn a result into a row or nothing.
 *
 * Pins each copy (normalizeToArray(), arrayOf(), asArray(), objectAsArray(),
 * normalize()) before they share one helper: an array is
 * kept, an object's jsonSerialize() array is used, and anything else (an
 * object without it, a serialisation that is not an array, a scalar, null)
 * becomes an empty array. The copies hold no state, so they are reached by
 * reflection on an instance built without its constructor.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use ArrayObject;
use JsonSerializable;
use OCA\Dossiq\Controller\PublicCaseSurvivorController;
use OCA\Dossiq\Listener\LinkMarkerListener;
use OCA\Dossiq\Listener\Support\EventObjectPayload;
use OCA\Dossiq\Service\Bezwaar\BezwaarCreationHook;
use OCA\Dossiq\Service\Deelzaak\CaseObjectReader;
use OCA\Dossiq\Service\DossierCompiler;
use OCA\Dossiq\Service\Intake\ReceiptMoment;
use OCA\Dossiq\Service\Relation\CaseRelationStore;
use OCA\Dossiq\Service\Starter\StarterStore;
use OCA\Dossiq\Service\Workflow\WorkflowDefinitionRepository;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use stdClass;

/**
 * Every copy answers the same row for the same result.
 *
 * @covers \OCA\Dossiq\Controller\PublicCaseSurvivorController
 * @covers \OCA\Dossiq\Service\Bezwaar\BezwaarCreationHook
 * @covers \OCA\Dossiq\Service\DossierCompiler
 * @covers \OCA\Dossiq\Service\Starter\StarterStore
 * @covers \OCA\Dossiq\Listener\LinkMarkerListener
 * @covers \OCA\Dossiq\Listener\Support\EventObjectPayload
 * @covers \OCA\Dossiq\Service\Deelzaak\CaseObjectReader
 * @covers \OCA\Dossiq\Service\Intake\ReceiptMoment
 * @covers \OCA\Dossiq\Service\Relation\CaseRelationStore
 * @covers \OCA\Dossiq\Service\Workflow\WorkflowDefinitionRepository
 *
 * @spec openspec/specs/case-management/spec.md
 */
class ResultRowCopiesTest extends TestCase {
	/**
	 * Every class carrying a copy, crossed with every kind of result.
	 *
	 * @return array<string, array{0: string, 1: mixed, 2: array<mixed>|null}>
	 */
	public static function cases(): array {
		$classes = [
			PublicCaseSurvivorController::class => 'asArrayOrNull',
			BezwaarCreationHook::class => 'toArray',
			DossierCompiler::class => 'toArray',
			StarterStore::class => 'asArray',
			CaseRelationStore::class => 'normalizeCaseObject',
			CaseObjectReader::class => 'toArray',
			ReceiptMoment::class => 'serialised',
			EventObjectPayload::class => 'fromValue',
			WorkflowDefinitionRepository::class => 'normalize',
		];
		$cases = [];
		foreach ($classes as $class => $method) {
			foreach (self::results() as $kind => [$value, $expected]) {
				$cases[$class . ' ' . $kind] = [$class . '::' . $method, $value, $expected];
			}
		}

		return $cases;
	}//end cases()

	/**
	 * Each kind of result with the array it becomes.
	 *
	 * @return array<string, array{0: mixed, 1: array<mixed>|null}>
	 */
	private static function results(): array {
		$object = new stdClass();
		$object->id = 'plain';

		return [
			'array' => [['id' => 'a'], ['id' => 'a']],
			'empty array' => [[], []],
			'serialisable to an array' => [self::serialisable(value: ['id' => 'b']), ['id' => 'b']],
			'serialisable to a string' => [self::serialisable(value: 'b'), null],
			'object without jsonSerialize' => [$object, null],
			'array object without jsonSerialize' => [new ArrayObject(['id' => 'c']), null],
			'string' => ['abc', null],
			'integer' => [7, null],
			'null' => [null, null],
		];
	}//end results()

	/**
	 * A result whose jsonSerialize() answers the given value.
	 *
	 * @param mixed $value What jsonSerialize() answers.
	 *
	 * @return JsonSerializable The result.
	 */
	private static function serialisable(mixed $value): JsonSerializable {
		return new class ($value) implements JsonSerializable {
			/**
			 * Constructor.
			 *
			 * @param mixed $value What jsonSerialize() answers.
			 */
			public function __construct(private readonly mixed $value) {
			}//end __construct()

			/**
			 * The configured value.
			 *
			 * @return mixed The value.
			 */
			public function jsonSerialize(): mixed {
				return $this->value;
			}//end jsonSerialize()
		};
	}//end serialisable()

	/**
	 * The copy turns the result into the expected row, or null.
	 *
	 * @param string       $copy     The copy, as Class::method.
	 * @param mixed        $value    The result.
	 * @param array<mixed>|null $expected The row it becomes, or null.
	 *
	 * @dataProvider cases
	 *
	 * @return void
	 */
	public function testTheResultBecomesTheExpectedRow(string $copy, mixed $value, ?array $expected): void {
		[$class, $method] = explode(separator: '::', string: $copy);
		$subject = (new ReflectionClass($class))->newInstanceWithoutConstructor();

		self::assertSame(expected: $expected, actual: (new ReflectionMethod($subject, $method))->invoke($subject, $value));
	}//end testTheResultBecomesTheExpectedRow()

	/**
	 * The link listener takes the first event object that reads as a row, new object before old.
	 *
	 * @return void
	 */
	public function testTheLinkListenerTakesTheFirstReadableEventObject(): void {
		$subject = (new ReflectionClass(LinkMarkerListener::class))->newInstanceWithoutConstructor();
		$read = new ReflectionMethod($subject, 'objectOf');

		self::assertSame(
			expected: [['id' => 'new'], ['id' => 'old'], ['id' => 'b'], null],
			actual: [
				$read->invoke($subject, $this->event(new: ['id' => 'new'], old: ['id' => 'old'])),
				$read->invoke($subject, $this->event(new: 'not a row', old: ['id' => 'old'])),
				$read->invoke($subject, $this->event(new: self::serialisable(value: ['id' => 'b']), old: null)),
				$read->invoke($subject, $this->event(new: self::serialisable(value: 'x'), old: 7)),
			]
		);
	}//end testTheLinkListenerTakesTheFirstReadableEventObject()

	/**
	 * An event answering the given new and old object.
	 *
	 * @param mixed $new What getNewObject() answers.
	 * @param mixed $old What getObject() answers.
	 *
	 * @return Event The event.
	 */
	private function event(mixed $new, mixed $old): Event {
		return new class ($new, $old) extends Event {
			/**
			 * Constructor.
			 *
			 * @param mixed $new What getNewObject() answers.
			 * @param mixed $old What getObject() answers.
			 */
			public function __construct(private readonly mixed $new, private readonly mixed $old) {
				parent::__construct();
			}//end __construct()

			/**
			 * The new object.
			 *
			 * @return mixed The value.
			 */
			public function getNewObject(): mixed {
				return $this->new;
			}//end getNewObject()

			/**
			 * The old object.
			 *
			 * @return mixed The value.
			 */
			public function getObject(): mixed {
				return $this->old;
			}//end getObject()
		};
	}//end event()
}//end class
