<?php

/**
 * Locks the processing-time arithmetic the dashboard draws.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Reporting;

use DateTimeImmutable;
use OCA\Dossiq\Service\Reporting\ProcessingTimeCalculator;
use PHPUnit\Framework\TestCase;

/**
 * The figures behind the processing-time charts and tables.
 *
 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
 */
class ProcessingTimeCalculatorTest extends TestCase {

	private ProcessingTimeCalculator $calculator;

	private DateTimeImmutable $today;

	/**
	 * A fresh calculator and a fixed day.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->calculator = new ProcessingTimeCalculator();
		$this->today      = new DateTimeImmutable('2026-06-15');
	}//end setUp()

	/**
	 * A prepared type.
	 *
	 * @param string   $id     The id.
	 * @param int|null $target The target in days.
	 *
	 * @return array<string, mixed>
	 */
	private function type(string $id, ?int $target): array {
		return ['id' => $id, 'slug' => $id, 'name' => ucfirst($id), 'targetDays' => $target];
	}//end type()

	/**
	 * A prepared record.
	 *
	 * @param string      $type  The type id.
	 * @param string|null $start The start date.
	 * @param string|null $end   The end date.
	 * @param string      $id    The id.
	 *
	 * @return array<string, mixed>
	 */
	private function record(string $type, ?string $start, ?string $end = null, string $id = 'r'): array {
		return [
			'id'         => $id,
			'title'      => 'Record '.$id,
			'identifier' => 'ID-'.$id,
			'caseType'   => $type,
			'startDate'  => $start,
			'endDate'    => $end,
			'deadline'   => null,
		];
	}//end record()

	/**
	 * A start date that many days before the fixed day.
	 *
	 * @param int $days Days back.
	 *
	 * @return string The date.
	 */
	private function daysAgo(int $days): string {
		return $this->today->modify('-'.$days.' days')->format('Y-m-d');
	}//end daysAgo()

	/**
	 * Durations read as day counts, with months as 30 and years as 365.
	 *
	 * @return void
	 */
	public function testDurationsReadAsDays(): void {
		self::assertSame(30, $this->calculator->durationToDays(duration: 'P30D'));
		self::assertSame(42, $this->calculator->durationToDays(duration: 'P6W'));
		self::assertSame(60, $this->calculator->durationToDays(duration: 'P2M'));
		self::assertSame(365, $this->calculator->durationToDays(duration: 'P1Y'));
		self::assertSame(428, $this->calculator->durationToDays(duration: 'P1Y2M3D'));
		self::assertSame(75, $this->calculator->durationToDays(duration: 'P2M15D'));
		self::assertSame(30, $this->calculator->durationToDays(duration: 'p30d'));
	}//end testDurationsReadAsDays()

	/**
	 * Empty, non-string, unreadable and zero durations have no day count.
	 *
	 * @return void
	 */
	public function testUnreadableDurationsAreNull(): void {
		foreach (['', null, 42, '30 days', 'P0D'] as $duration) {
			self::assertNull($this->calculator->durationToDays(duration: $duration));
		}
	}//end testUnreadableDurationsAreNull()

	/**
	 * Processing days are whole days, floored at zero, null without both dates.
	 *
	 * @return void
	 */
	public function testProcessingDays(): void {
		self::assertSame(30, $this->calculator->processingDays(record: $this->record('a', '2026-01-01', '2026-01-31')));
		self::assertSame(0, $this->calculator->processingDays(record: $this->record('a', '2026-03-01', '2026-03-01')));
		self::assertSame(0, $this->calculator->processingDays(record: $this->record('a', '2026-02-10', '2026-02-01')));
		self::assertNull($this->calculator->processingDays(record: $this->record('a', '2026-01-01')));
		self::assertNull($this->calculator->processingDays(record: $this->record('a', null, '2026-01-01')));
	}//end testProcessingDays()

	/**
	 * Compliance counts records with a target and both dates, per type.
	 *
	 * @return void
	 */
	public function testSlaCompliance(): void {
		$types = ['permit' => $this->type('permit', 30), 'objection' => $this->type('objection', 10)];
		$completed = [
			$this->record('permit', '2026-01-01', '2026-01-21'),
			$this->record('permit', '2026-01-01', '2026-02-10'),
			$this->record('objection', '2026-01-01', '2026-01-06'),
			$this->record('none', '2026-01-01', '2026-01-05'),
			$this->record('permit', '2026-01-01'),
		];

		$result = $this->calculator->slaCompliance(completed: $completed, types: $types);

		self::assertSame(['overallRate' => 67, 'withinSla' => 2, 'total' => 3, 'excluded' => 2], $result['summary']);
		self::assertSame(
			['id' => 'permit', 'name' => 'Permit', 'total' => 2, 'withinSla' => 1, 'rate' => 50, 'avgActual' => 30, 'targetDays' => 30],
			$result['byType'][0]
		);
		self::assertSame(100, $result['byType'][1]['rate']);
		self::assertSame(5, $result['byType'][1]['avgActual']);
	}//end testSlaCompliance()

	/**
	 * Without qualifying records the rate is null and no type is listed,
	 * which is what leaves the donut empty.
	 *
	 * @return void
	 */
	public function testSlaComplianceWithoutRecords(): void {
		$result = $this->calculator->slaCompliance(completed: [], types: ['permit' => $this->type('permit', 30)]);

		self::assertNull($result['summary']['overallRate']);
		self::assertSame(0, $result['summary']['total']);
		self::assertSame([], $result['byType']);
	}//end testSlaComplianceWithoutRecords()

	/**
	 * Bin edges are inclusive and the last bin is open-ended.
	 *
	 * @return void
	 */
	public function testDistributionBinsAreInclusive(): void {
		$types = ['permit' => $this->type('permit', 14)];
		$completed = [
			$this->record('permit', '2026-01-01', '2026-01-01'),
			$this->record('permit', '2026-01-01', '2026-01-08'),
			$this->record('permit', '2026-01-01', '2026-01-09'),
			$this->record('permit', '2026-01-01', '2026-02-27'),
		];

		$result = $this->calculator->distribution(completed: $completed, types: $types);
		$counts = array_column($result['bins'], 'count', 'label');

		self::assertSame(['0-7' => 2, '8-14' => 1, '15-21' => 0, '22-28' => 0, '29-42' => 0, '43-56' => 0, '57+' => 1], $counts);
	}//end testDistributionBinsAreInclusive()

	/**
	 * The target line is given only when every record shares one target, with
	 * the bin it falls in.
	 *
	 * @return void
	 */
	public function testDistributionTargetOnlyForOneTarget(): void {
		$types = ['a' => $this->type('a', 14), 'b' => $this->type('b', 30), 'c' => $this->type('c', 90)];

		$single = $this->calculator->distribution(completed: [$this->record('a', '2026-01-01', '2026-01-05')], types: $types);
		self::assertSame(14, $single['slaTargetDays']);
		self::assertSame('8-14', $single['targetBin']);

		$beyond = $this->calculator->distribution(completed: [$this->record('c', '2026-01-01', '2026-01-05')], types: $types);
		self::assertSame('57+', $beyond['targetBin']);

		$mixed = $this->calculator->distribution(
			completed: [$this->record('a', '2026-01-01', '2026-01-05'), $this->record('b', '2026-01-01', '2026-01-05')],
			types: $types
		);
		self::assertNull($mixed['slaTargetDays']);
		self::assertNull($mixed['targetBin']);
	}//end testDistributionTargetOnlyForOneTarget()

	/**
	 * One bucket per month ending this month; empty months have a null rate.
	 *
	 * @return void
	 */
	public function testMonthlyTrend(): void {
		$types = ['permit' => $this->type('permit', 10)];
		$completed = [
			$this->record('permit', '2026-06-01', '2026-06-06'),
			$this->record('permit', '2026-06-01', '2026-06-20'),
			$this->record('permit', '2025-01-01', '2025-01-05'),
		];

		$trend = $this->calculator->monthlyTrend(completed: $completed, types: $types, months: 3, today: $this->today);

		self::assertSame(['2026-04', '2026-05', '2026-06'], array_column($trend, 'month'));
		self::assertSame(['month' => '2026-06', 'rate' => 50, 'withinSla' => 1, 'total' => 2], $trend[2]);
		self::assertSame(0, $trend[0]['total']);
		self::assertNull($trend[0]['rate']);
	}//end testMonthlyTrend()

	/**
	 * Weekly throughput ends at the latest completion and labels ISO weeks.
	 *
	 * @return void
	 */
	public function testWeeklyThroughput(): void {
		$completed = [
			$this->record('a', '2026-01-01', '2026-06-10'),
			$this->record('a', '2026-01-01', '2026-06-12'),
			$this->record('a', '2026-01-01', '2026-06-01'),
			$this->record('a', '2026-01-01', '2025-01-01'),
		];

		$weeks = $this->calculator->weeklyThroughput(completed: $completed, weeks: 3);

		self::assertSame(
			[
				['weekLabel' => 'W22 2026', 'week' => '2026-W22', 'count' => 0],
				['weekLabel' => 'W23 2026', 'week' => '2026-W23', 'count' => 1],
				['weekLabel' => 'W24 2026', 'week' => '2026-W24', 'count' => 2],
			],
			$weeks
		);
		self::assertSame([], $this->calculator->weeklyThroughput(completed: []));
	}//end testWeeklyThroughput()

	/**
	 * ISO week numbering follows the Thursday, so early January can belong to
	 * the previous year.
	 *
	 * @return void
	 */
	public function testWeeklyThroughputUsesIsoWeekYear(): void {
		$weeks = $this->calculator->weeklyThroughput(completed: [$this->record('a', null, '2027-01-01')], weeks: 1);

		self::assertSame([['weekLabel' => 'W53 2026', 'week' => '2026-W53', 'count' => 1]], $weeks);
	}//end testWeeklyThroughputUsesIsoWeekYear()
}//end class
