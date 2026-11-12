<?php

/**
 * Deadline compliance unit tests: the KPI, the monthly compliance and the case list.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Doorlooptijd
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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

use DateTimeImmutable;
use OCA\Dossiq\Service\Doorlooptijd\DeadlineComplianceCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Characterisation tests for the deadline compliance figures.
 *
 * Dates are relative to the first day of the current month, so no date is
 * pinned to the clock.
 *
 * @covers \OCA\Dossiq\Service\Doorlooptijd\DeadlineComplianceCalculator
 */
class DeadlineComplianceTest extends TestCase {

	/**
	 * A date a number of months before the first day of this month.
	 *
	 * @param integer $monthsAgo The months back.
	 * @param integer $day       The day of that month.
	 *
	 * @return string The date, Y-m-d.
	 */
	private function day(int $monthsAgo, int $day=1): string {
		$first = new DateTimeImmutable('first day of this month');
		return $first->modify('-'.$monthsAgo.' month')->format('Y-m-').sprintf('%02d', $day);
	}//end day()

	/**
	 * A case as the enricher leaves it.
	 *
	 * @param array<string, mixed> $fields The enriched fields.
	 *
	 * @return array<string, mixed> The case.
	 */
	private function enriched(array $fields): array {
		return array_merge(
			[
				'_isOpen' => false,
				'_startDate' => null,
				'_endDate' => null,
				'_deadline' => null,
				'_daysRemaining' => null,
			],
			$fields
		);
	}//end enriched()

	/**
	 * The bands count open cases only, and the on-time percent closed ones in the last year.
	 *
	 * @return void
	 */
	public function testTheKpiCountsBandsAndTheOnTimePercent(): void {
		$cases = [
			$this->enriched(['_isOpen' => true, '_daysRemaining' => -1]),
			$this->enriched(['_isOpen' => true, '_daysRemaining' => 0]),
			$this->enriched(['_isOpen' => true, '_daysRemaining' => 5]),
			$this->enriched(['_isOpen' => true, '_daysRemaining' => 6]),
			$this->enriched(['_isOpen' => true, '_daysRemaining' => null]),
			$this->enriched(['_isOpen' => false, '_daysRemaining' => -9]),
			$this->enriched(['_endDate' => $this->day(1, 10), '_deadline' => $this->day(1, 10)]),
			$this->enriched(['_endDate' => $this->day(2, 10), '_deadline' => $this->day(2, 11)]),
			$this->enriched(['_endDate' => $this->day(3, 12), '_deadline' => $this->day(3, 11)]),
			$this->enriched(['_endDate' => $this->day(1, 12), '_deadline' => null]),
			$this->enriched(['_endDate' => $this->day(14), '_deadline' => $this->day(15)]),
			$this->enriched(['_isOpen' => true, '_endDate' => $this->day(1), '_deadline' => $this->day(2)]),
		];

		self::assertSame(
			['open' => 6, 'atRisk' => 2, 'overdue' => 1, 'onTimePercent' => 67],
			(new DeadlineComplianceCalculator())->computeKpi($cases, 5)
		);
	}//end testTheKpiCountsBandsAndTheOnTimePercent()

	/**
	 * Nothing closed in the last year reads as 100 percent on time.
	 *
	 * @return void
	 */
	public function testTheOnTimePercentIsHundredWhenNothingClosed(): void {
		$kpi = (new DeadlineComplianceCalculator())->computeKpi(
			[$this->enriched(['_endDate' => $this->day(14), '_deadline' => $this->day(13)])],
			5
		);

		self::assertSame(['open' => 0, 'atRisk' => 0, 'overdue' => 0, 'onTimePercent' => 100], $kpi);
	}//end testTheOnTimePercentIsHundredWhenNothingClosed()

	/**
	 * Each month in the period gets a row, oldest first, and only dated cases count.
	 *
	 * @return void
	 */
	public function testTheMonthlyComplianceBucketsPerMonth(): void {
		$cases = [
			$this->enriched(['_endDate' => $this->day(0, 2), '_deadline' => $this->day(0, 3)]),
			$this->enriched(['_endDate' => $this->day(0, 4), '_deadline' => $this->day(0, 3)]),
			$this->enriched(['_endDate' => $this->day(0, 5), '_deadline' => $this->day(0, 7)]),
			$this->enriched(['_endDate' => $this->day(2, 5), '_deadline' => $this->day(2, 4)]),
			$this->enriched(['_endDate' => $this->day(2, 5), '_deadline' => null]),
			$this->enriched(['_endDate' => null, '_deadline' => $this->day(2, 4)]),
			$this->enriched(['_endDate' => $this->day(5, 5), '_deadline' => $this->day(5, 9)]),
			$this->enriched(['_isOpen' => true, '_endDate' => $this->day(1, 5), '_deadline' => $this->day(1, 9)]),
		];

		$month = fn (int $ago): string => substr($this->day($ago), 0, 7);
		self::assertSame(
			[
				['month' => $month(2), 'onTime' => 0, 'late' => 1, 'percent' => 0],
				['month' => $month(1), 'onTime' => 1, 'late' => 0, 'percent' => 100],
				['month' => $month(0), 'onTime' => 2, 'late' => 1, 'percent' => 67],
			],
			(new DeadlineComplianceCalculator())->computeMonthlyCompliance($cases, '3m')
		);
	}//end testTheMonthlyComplianceBucketsPerMonth()

	/**
	 * An unreadable or out-of-range period falls back to twelve months.
	 *
	 * @return void
	 */
	public function testTheMonthlyComplianceFallsBackToTwelveMonths(): void {
		$calculator = new DeadlineComplianceCalculator();

		self::assertCount(12, $calculator->computeMonthlyCompliance([], 'week'));
		self::assertCount(12, $calculator->computeMonthlyCompliance([], '37m'));
		self::assertCount(1, $calculator->computeMonthlyCompliance([], '1m'));
		self::assertSame(
			['month' => substr($this->day(0), 0, 7), 'onTime' => 0, 'late' => 0, 'percent' => 100],
			$calculator->computeMonthlyCompliance([], '1m')[0]
		);
	}//end testTheMonthlyComplianceFallsBackToTwelveMonths()

	/**
	 * The case list holds open cases with a RAG status, the least time left first.
	 *
	 * @return void
	 */
	public function testTheCaseListRatesAndSortsOpenCases(): void {
		$cases = [
			$this->enriched(['_isOpen' => true, '_daysRemaining' => null, 'id' => 1]),
			$this->enriched(['_isOpen' => true, '_daysRemaining' => 6, 'id' => 2, 'identifier' => 'Z-2', 'title' => 'Twee', '_caseTypeTitle' => 'Melding', '_startDate' => '2026-01-01', '_deadline' => '2026-02-01']),
			$this->enriched(['_isOpen' => true, '_daysRemaining' => 5, 'id' => 3]),
			$this->enriched(['_isOpen' => true, '_daysRemaining' => -2, 'id' => 4]),
			$this->enriched(['_isOpen' => false, '_daysRemaining' => -9, 'id' => 5]),
		];

		$rows = (new DeadlineComplianceCalculator())->buildCaseList($cases, 5);

		self::assertSame(['4', '3', '2', '1'], array_column($rows, 'id'));
		self::assertSame(['overdue', 'at-risk', 'on-time', 'on-time'], array_column($rows, 'ragStatus'));
		self::assertSame(
			[
				'id' => '2',
				'identifier' => 'Z-2',
				'title' => 'Twee',
				'caseTypeTitle' => 'Melding',
				'startDate' => '2026-01-01',
				'deadline' => '2026-02-01',
				'daysRemaining' => 6,
				'ragStatus' => 'on-time',
			],
			$rows[2]
		);
		self::assertSame(['id' => '1', 'identifier' => '', 'title' => '', 'caseTypeTitle' => ''], array_slice($rows[3], 0, 4));
	}//end testTheCaseListRatesAndSortsOpenCases()
}//end class
