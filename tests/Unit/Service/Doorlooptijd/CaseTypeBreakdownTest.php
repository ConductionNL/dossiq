<?php

/**
 * Case Type Breakdown Test
 *
 * Pins the per-case-type throughput breakdown: only closed cases with a
 * throughput and a case type count, the average calendar days and working
 * hours per type, the title resolved from the case type (a language map
 * included), the slowest type first, and which clock the working hours
 * were read on.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Doorlooptijd
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Doorlooptijd;

use OCA\Dossiq\Service\Doorlooptijd\CaseTypeThroughputCalculator;
use OCA\Dossiq\Service\ProcessMining\WorkingClock;
use OCA\Dossiq\Service\Support\LanguageMapText;
use OCA\Dossiq\Service\WorkingDayCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests for CaseTypeThroughputCalculator's breakdown and clock.
 */
#[CoversClass(CaseTypeThroughputCalculator::class)]
#[UsesClass(LanguageMapText::class)]
#[UsesClass(WorkingClock::class)]
#[UsesClass(WorkingDayCalculator::class)]
final class CaseTypeBreakdownTest extends TestCase {
	/**
	 * A case row as the enricher hands it over.
	 *
	 * @param string      $caseType The case type id.
	 * @param int|null    $days     The throughput in calendar days.
	 * @param bool        $isOpen   Whether the case is still open.
	 * @param string|null $start    The start date.
	 * @param string|null $end      The end date.
	 *
	 * @return array<string,mixed>
	 */
	private static function caseRow(string $caseType, ?int $days, bool $isOpen = false, ?string $start = null, ?string $end = null): array {
		return [
			'caseType' => $caseType,
			'_throughputDays' => $days,
			'_isOpen' => $isOpen,
			'_startDate' => $start,
			'_endDate' => $end,
		];
	}//end caseRow()

	/**
	 * A working clock with no engine calendar, so it counts working days times eight.
	 *
	 * @return WorkingClock
	 */
	private function degradedClock(): WorkingClock {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('no engine'));

		return new WorkingClock(container: $container, days: new WorkingDayCalculator());
	}//end degradedClock()

	/**
	 * Without a clock the report says it read the wall clock; with one it names that clock.
	 *
	 * @return void
	 */
	public function testClockNamesWhatTheHoursWereReadOn(): void {
		self::assertSame(expected: WorkingClock::CLOCK_WALL, actual: (new CaseTypeThroughputCalculator())->clock());
		self::assertSame(
			expected: WorkingClock::CLOCK_DAYS_TIMES_EIGHT,
			actual: (new CaseTypeThroughputCalculator(clock: $this->degradedClock()))->clock()
		);
	}//end testClockNamesWhatTheHoursWereReadOn()

	/**
	 * Open cases, cases without throughput and cases without a type are skipped;
	 * the rest average per type, slowest first, titled from the case type.
	 *
	 * @return void
	 */
	public function testBreakdownAveragesClosedCasesPerTypeSlowestFirst(): void {
		$cases = [
			self::caseRow(caseType: 'ct-fast', days: 2),
			self::caseRow(caseType: 'ct-fast', days: 3),
			self::caseRow(caseType: 'ct-slow', days: 40),
			self::caseRow(caseType: 'ct-slow', days: 99, isOpen: true),
			self::caseRow(caseType: 'ct-slow', days: null),
			self::caseRow(caseType: '', days: 500),
			self::caseRow(caseType: 'ct-untitled', days: 10),
		];
		$caseTypes = [
			['id' => 'ct-fast', 'title' => ['en' => 'Parking permit', 'nl' => ' Parkeervergunning ']],
			['id' => 'ct-slow', 'title' => 'Environmental permit'],
			['title' => 'A type without id'],
		];

		$rows = (new CaseTypeThroughputCalculator())->computeCaseTypeBreakdown(cases: $cases, caseTypes: $caseTypes);

		self::assertSame(
			expected: [
				['id' => 'ct-slow', 'title' => 'Environmental permit', 'avgDays' => 40, 'avgWorkingHours' => 320.0, 'count' => 1],
				['id' => 'ct-untitled', 'title' => 'ct-untitled', 'avgDays' => 10, 'avgWorkingHours' => 80.0, 'count' => 1],
				['id' => 'ct-fast', 'title' => 'Parkeervergunning', 'avgDays' => 3, 'avgWorkingHours' => 20.0, 'count' => 2],
			],
			actual: $rows
		);
	}//end testBreakdownAveragesClosedCasesPerTypeSlowestFirst()

	/**
	 * With a clock, working hours are measured between the dates; a case without
	 * dates falls back to days times eight and unreadable dates count zero hours.
	 *
	 * @return void
	 */
	public function testBreakdownMeasuresWorkingHoursOnTheClock(): void {
		$cases = [
			self::caseRow(caseType: 'ct-1', days: 4, start: '2026-09-07', end: '2026-09-11'),
			self::caseRow(caseType: 'ct-1', days: 3, start: '2026-09-11', end: '2026-09-14'),
			self::caseRow(caseType: 'ct-2', days: 5),
			self::caseRow(caseType: 'ct-3', days: 1, start: 'not a date', end: '2026-09-14'),
		];

		$rows = (new CaseTypeThroughputCalculator(clock: $this->degradedClock()))
			->computeCaseTypeBreakdown(cases: $cases, caseTypes: []);
		$byId = array_column($rows, null, 'id');

		self::assertSame(expected: 28.0, actual: $byId['ct-1']['avgWorkingHours']);
		self::assertSame(expected: 4, actual: $byId['ct-1']['avgDays']);
		self::assertSame(expected: 40.0, actual: $byId['ct-2']['avgWorkingHours']);
		self::assertSame(expected: 0.0, actual: $byId['ct-3']['avgWorkingHours']);
	}//end testBreakdownMeasuresWorkingHoursOnTheClock()

	/**
	 * A closed case without a case type does not enter the within-norm shares.
	 *
	 * @return void
	 */
	public function testWithinNormSkipsCasesWithoutType(): void {
		$cases = [
			['caseType' => '', '_isOpen' => false, '_endDate' => '2026-09-01', '_deadline' => '2026-09-10'],
			['caseType' => 'ct-1', '_isOpen' => false, '_endDate' => '2026-09-01', '_deadline' => '2026-09-10'],
		];

		$result = (new CaseTypeThroughputCalculator())->computeWithinNormByCaseType(cases: $cases, caseTypes: []);

		self::assertSame(expected: ['ct-1'], actual: $result['labels']);
	}//end testWithinNormSkipsCasesWithoutType()
}//end class
