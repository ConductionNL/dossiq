<?php

/**
 * How the lifecycle service and the case journal read the activity journal of a case.
 *
 * Pins CaseLifecycleService::readJournal() and CaseJournal::entries() before
 * they read through the shared row-list reading: a stored array is read as
 * it is; anything else is read as a JSON string; the rows are kept, in
 * order, and everything else dropped. An object value still fails the
 * string conversion.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Lifecycle
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

namespace OCA\Dossiq\Tests\Unit\Service\Lifecycle;

use Error;
use OCA\Dossiq\Service\CaseLifecycleService;
use OCA\Dossiq\Service\Lifecycle\CaseJournal;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use stdClass;

/**
 * Both read the same journal from the same case.
 *
 * @covers \OCA\Dossiq\Service\CaseLifecycleService
 * @covers \OCA\Dossiq\Service\Lifecycle\CaseJournal
 *
 * @spec openspec/specs/case-management/spec.md
 * @uses \OCA\Dossiq\Service\Support\RowList
 */
class CaseJournalReadingCopiesTest extends TestCase {
	/**
	 * Both readers, crossed with every kind of stored journal.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: array<int, array<mixed>>}>
	 */
	public static function cases(): array {
		$journals = [
			'a JSON list' => [['activity' => '[{"a":1},"x",{"b":2}]'], [['a' => 1], ['b' => 2]]],
			'a stored array, re-indexed' => [['activity' => ['k' => ['a' => 1], 'j' => 5]], [['a' => 1]]],
			'invalid JSON' => [['activity' => '{nope'], []],
			'no journal' => [[], []],
			'a null journal' => [['activity' => null], []],
			'a number' => [['activity' => 12], []],
			'true' => [['activity' => true], []],
		];
		$cases = [];
		foreach ([CaseLifecycleService::class . '::readJournal', CaseJournal::class . '::entries'] as $reader) {
			foreach ($journals as $label => [$case, $expected]) {
				$cases[$reader . ' ' . $label] = [$reader, $case, $expected];
			}
		}

		return $cases;
	}//end cases()

	/**
	 * Both readers.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function readers(): array {
		return [
			'lifecycle service' => [CaseLifecycleService::class . '::readJournal'],
			'case journal' => [CaseJournal::class . '::entries'],
		];
	}//end readers()

	/**
	 * The reader answers the expected entries.
	 *
	 * @param string                   $reader   The reader, as Class::method.
	 * @param array<string, mixed>     $case     The case.
	 * @param array<int, array<mixed>> $expected The entries.
	 *
	 * @dataProvider cases
	 *
	 * @return void
	 */
	public function testTheJournalGivesTheExpectedEntries(string $reader, array $case, array $expected): void {
		self::assertSame(expected: $expected, actual: $this->read(reader: $reader, case: $case));
	}//end testTheJournalGivesTheExpectedEntries()

	/**
	 * An object journal still fails the string conversion.
	 *
	 * @param string $reader The reader, as Class::method.
	 *
	 * @dataProvider readers
	 *
	 * @return void
	 */
	public function testAnObjectJournalFailsTheStringConversion(string $reader): void {
		$this->expectException(exception: Error::class);
		$this->read(reader: $reader, case: ['activity' => new stdClass()]);
	}//end testAnObjectJournalFailsTheStringConversion()

	/**
	 * Run one reader on a case.
	 *
	 * @param string               $reader The reader, as Class::method.
	 * @param array<string, mixed> $case   The case.
	 *
	 * @return mixed What the reader answered.
	 */
	private function read(string $reader, array $case): mixed {
		[$class, $method] = explode(separator: '::', string: $reader);
		$subject = (new ReflectionClass($class))->newInstanceWithoutConstructor();

		return (new ReflectionMethod($subject, $method))->invoke($subject, $case);
	}//end read()
}//end class
