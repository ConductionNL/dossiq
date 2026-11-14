<?php

/**
 * How the intake, permit, capacity, advice, case-type and mail readers turn a result into an array.
 *
 * Pins each copy (normalize(), normalizeToArray(), arrayOf(), asArray(),
 * objectAsArray(), asRow(), normaliseObjectRow()) before they read through
 * SearchesObjects: an array is
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
use OCA\Dossiq\Service\Advice\AdviceRepository;
use OCA\Dossiq\Service\Bulk\CaseTypeVersionGuard;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\Deelzaak\SubCaseDeriver;
use OCA\Dossiq\Service\DsoCaseService;
use OCA\Dossiq\Service\Email\UnmatchedMailIntake;
use OCA\Dossiq\Service\FormsIntakeService;
use OCA\Dossiq\Service\Intake\CaseIntakeFiling;
use OCA\Dossiq\Service\Intake\ChannelIntake;
use OCA\Dossiq\Service\Transitions\CapacityGuard;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use stdClass;

/**
 * Every copy answers the same array for the same result.
 *
 * @covers \OCA\Dossiq\Service\Advice\AdviceRepository
 * @covers \OCA\Dossiq\Service\Bulk\CaseTypeVersionGuard
 * @covers \OCA\Dossiq\Service\CaseTypeStore
 * @covers \OCA\Dossiq\Service\Deelzaak\SubCaseDeriver
 * @covers \OCA\Dossiq\Service\Email\UnmatchedMailIntake
 * @covers \OCA\Dossiq\Service\DsoCaseService
 * @covers \OCA\Dossiq\Service\FormsIntakeService
 * @covers \OCA\Dossiq\Service\Intake\CaseIntakeFiling
 * @covers \OCA\Dossiq\Service\Intake\ChannelIntake
 * @covers \OCA\Dossiq\Service\Transitions\CapacityGuard
 *
 * @spec openspec/specs/case-management/spec.md
 * @uses \OCA\Dossiq\Service\Support\SerialisedResult
 */
class ResultArrayIntakeCopiesTest extends TestCase {
	/**
	 * Every class carrying a copy, crossed with every kind of result.
	 *
	 * @return array<string, array{0: string, 1: mixed, 2: array<mixed>}>
	 */
	public static function cases(): array {
		$classes = [
			AdviceRepository::class => 'normalize',
			DsoCaseService::class => 'normalizeToArray',
			FormsIntakeService::class => 'arrayOf',
			CaseIntakeFiling::class => 'asArray',
			ChannelIntake::class => 'objectAsArray',
			CapacityGuard::class => 'arrayOf',
			CaseTypeStore::class => 'asRow',
			SubCaseDeriver::class => 'asArray',
			UnmatchedMailIntake::class => 'normaliseObjectRow',
			CaseTypeVersionGuard::class => 'asArray',
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
	 * @return array<string, array{0: mixed, 1: array<mixed>}>
	 */
	private static function results(): array {
		$object = new stdClass();
		$object->id = 'plain';

		return [
			'array' => [['id' => 'a'], ['id' => 'a']],
			'empty array' => [[], []],
			'serialisable to an array' => [self::serialisable(value: ['id' => 'b']), ['id' => 'b']],
			'serialisable to a string' => [self::serialisable(value: 'b'), []],
			'object without jsonSerialize' => [$object, []],
			'array object without jsonSerialize' => [new ArrayObject(['id' => 'c']), []],
			'string' => ['abc', []],
			'integer' => [7, []],
			'null' => [null, []],
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
	 * The copy turns the result into the expected array.
	 *
	 * @param string       $copy     The copy, as Class::method.
	 * @param mixed        $value    The result.
	 * @param array<mixed> $expected The array it becomes.
	 *
	 * @dataProvider cases
	 *
	 * @return void
	 */
	public function testTheResultBecomesTheExpectedArray(string $copy, mixed $value, array $expected): void {
		[$class, $method] = explode(separator: '::', string: $copy);
		$subject = (new ReflectionClass($class))->newInstanceWithoutConstructor();

		self::assertSame(expected: $expected, actual: (new ReflectionMethod($subject, $method))->invoke($subject, $value));
	}//end testTheResultBecomesTheExpectedArray()

	/**
	 * The version guard reads an entity's getObject() array first, and nothing else when it has one.
	 *
	 * @return void
	 */
	public function testTheVersionGuardPrefersGetObject(): void {
		$subject = (new ReflectionClass(CaseTypeVersionGuard::class))->newInstanceWithoutConstructor();
		$read = new ReflectionMethod($subject, 'asArray');
		$entity = new class {
			/**
			 * The stored object.
			 *
			 * @return array<string, string> The object.
			 */
			public function getObject(): array {
				return ['id' => 'from-get-object'];
			}//end getObject()

			/**
			 * A serialisation the guard must not reach.
			 *
			 * @return array<string, string> The serialisation.
			 */
			public function jsonSerialize(): array {
				return ['id' => 'from-json'];
			}//end jsonSerialize()
		};
		$notAnArray = new class {
			/**
			 * A stored object that is not an array.
			 *
			 * @return string The object.
			 */
			public function getObject(): string {
				return 'x';
			}//end getObject()

			/**
			 * A serialisation the guard must not reach.
			 *
			 * @return array<string, string> The serialisation.
			 */
			public function jsonSerialize(): array {
				return ['id' => 'from-json'];
			}//end jsonSerialize()
		};

		self::assertSame(expected: [['id' => 'from-get-object'], []], actual: [$read->invoke($subject, $entity), $read->invoke($subject, $notAnArray)]);
	}//end testTheVersionGuardPrefersGetObject()
}//end class
