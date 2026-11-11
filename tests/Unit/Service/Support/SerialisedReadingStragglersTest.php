<?php

/**
 * The last copies of the two serialised readings of an OpenRegister result.
 *
 * Pins each copy before it delegates to the shared helper. The array
 * readings (CaseTypeStore::asRow(), SubCaseDeriver::asArray(),
 * UnmatchedMailIntake::normaliseObjectRow()) keep an array, use an entity's
 * jsonSerialize() array, and answer an empty array for anything else. The
 * row readings (CaseRelationStore::normalizeCaseObject(),
 * CaseObjectReader::toArray(), ReceiptMoment::serialised(),
 * EventObjectPayload::fromValue()) do the same but answer null.
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
use OCA\Dossiq\Listener\Support\EventObjectPayload;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\Deelzaak\CaseObjectReader;
use OCA\Dossiq\Service\Deelzaak\SubCaseDeriver;
use OCA\Dossiq\Service\Email\UnmatchedMailIntake;
use OCA\Dossiq\Service\Intake\ReceiptMoment;
use OCA\Dossiq\Service\Relation\CaseRelationStore;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use stdClass;

/**
 * Every copy answers what its shared reading answers.
 *
 * @covers \OCA\Dossiq\Listener\Support\EventObjectPayload
 * @covers \OCA\Dossiq\Service\CaseTypeStore
 * @covers \OCA\Dossiq\Service\Deelzaak\CaseObjectReader
 * @covers \OCA\Dossiq\Service\Deelzaak\SubCaseDeriver
 * @covers \OCA\Dossiq\Service\Email\UnmatchedMailIntake
 * @covers \OCA\Dossiq\Service\Intake\ReceiptMoment
 * @covers \OCA\Dossiq\Service\Relation\CaseRelationStore
 *
 * @spec openspec/specs/case-management/spec.md
 */
class SerialisedReadingStragglersTest extends TestCase {
	/**
	 * Every copy, crossed with every kind of result; the empty answer is [] or null by reading.
	 *
	 * @return array<string, array{0: string, 1: mixed, 2: array<mixed>|null}>
	 */
	public static function cases(): array {
		$copies = [
			CaseTypeStore::class . '::asRow' => [],
			SubCaseDeriver::class . '::asArray' => [],
			UnmatchedMailIntake::class . '::normaliseObjectRow' => [],
			CaseRelationStore::class . '::normalizeCaseObject' => null,
			CaseObjectReader::class . '::toArray' => null,
			ReceiptMoment::class . '::serialised' => null,
			EventObjectPayload::class . '::fromValue' => null,
		];
		$cases = [];
		foreach ($copies as $copy => $empty) {
			foreach (self::results() as $kind => [$value, $row]) {
				$cases[$copy . ' ' . $kind] = [$copy, $value, ($row ?? $empty)];
			}
		}

		return $cases;
	}//end cases()

	/**
	 * Each kind of result with the row it becomes, null where the copy answers its empty value.
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
	 * The copy turns the result into the expected row or empty value.
	 *
	 * @param string            $copy     The copy, as Class::method.
	 * @param mixed             $value    The result.
	 * @param array<mixed>|null $expected What it becomes.
	 *
	 * @dataProvider cases
	 *
	 * @return void
	 */
	public function testTheResultBecomesWhatItsReadingAnswers(string $copy, mixed $value, ?array $expected): void {
		[$class, $method] = explode(separator: '::', string: $copy);
		$subject = (new ReflectionClass($class))->newInstanceWithoutConstructor();

		self::assertSame(expected: $expected, actual: (new ReflectionMethod($subject, $method))->invoke($subject, $value));
	}//end testTheResultBecomesWhatItsReadingAnswers()
}//end class
