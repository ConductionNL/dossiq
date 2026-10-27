<?php

/**
 * Locks the processing-time table rows: records at risk and the breakdown.
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
use OCA\Dossiq\Service\Reporting\ProcessingTimeRows;
use PHPUnit\Framework\TestCase;

/**
 * The rows behind the at-risk and breakdown tables.
 *
 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
 */
class ProcessingTimeRowsTest extends TestCase {

	private ProcessingTimeRows $calculator;

	private DateTimeImmutable $today;

	/**
	 * Fresh rows and a fixed day.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->calculator = new ProcessingTimeRows();
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
	 * Overdue and near-target records are listed, healthy ones are not.
	 *
	 * @return void
	 */
	public function testAtRiskSelection(): void {
		$types = ['permit' => $this->type('permit', 10)];
		$open = [
			$this->record('permit', $this->daysAgo(12), null, 'a'),
			$this->record('permit', $this->daysAgo(8), null, 'b'),
			$this->record('permit', $this->daysAgo(2), null, 'c'),
		];

		$rows = $this->calculator->atRisk(open: $open, types: $types, threshold: 0.25, today: $this->today);

		self::assertSame(['a', 'b'], array_column($rows, 'id'));
	}//end testAtRiskSelection()

	/**
	 * A case's running statutory term decides: a Woo request 24 days into its
	 * 28-day term, 4 days before its deadline, is at risk even when its case
	 * type's target says 56 days (DqDoorlooptijd 2026-4864, r33). The days
	 * left are counted to the deadline.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/at-risk-reads-the-running-term/specs/at-risk-reads-the-running-term/spec.md#requirement-at-risk-reads-the-cases-running-term
	 */
	public function testAtRiskReadsTheCasesRunningTerm(): void {
		$types = ['woo' => $this->type(id: 'woo', target: 56)];
		$record = $this->record(type: 'woo', start: $this->daysAgo(days: 24), end: null, id: 'woo-1');
		$record['deadline'] = $this->today->modify('+4 days')->format('Y-m-d');

		$rows = $this->calculator->atRisk(open: [$record], types: $types, threshold: 0.5, today: $this->today);

		self::assertSame(expected: ['woo-1'], actual: array_column($rows, 'id'));
		self::assertSame(expected: 4, actual: $rows[0]['remainingDays']);
		self::assertSame(expected: 'critical', actual: $rows[0]['severity']);
	}//end testAtRiskReadsTheCasesRunningTerm()

	/**
	 * The most overdue comes first, then the least time left.
	 *
	 * @return void
	 */
	public function testAtRiskOrder(): void {
		$types = ['permit' => $this->type('permit', 10)];
		$open = [
			$this->record('permit', $this->daysAgo(9), null, 'near'),
			$this->record('permit', $this->daysAgo(20), null, 'veryOverdue'),
			$this->record('permit', $this->daysAgo(12), null, 'slightOverdue'),
		];

		$rows = $this->calculator->atRisk(open: $open, types: $types, threshold: 0.25, today: $this->today);

		self::assertSame(['veryOverdue', 'slightOverdue', 'near'], array_column($rows, 'id'));
		self::assertTrue($rows[0]['isOverdue']);
		self::assertFalse($rows[2]['isOverdue']);
	}//end testAtRiskOrder()

	/**
	 * Elapsed, remaining and the share used, capped at 150%.
	 *
	 * @return void
	 */
	public function testAtRiskFigures(): void {
		$types = ['permit' => $this->type('permit', 10)];
		$rows = $this->calculator->atRisk(
			open: [$this->record('permit', $this->daysAgo(30), null, 'x')],
			types: $types,
			threshold: 0.25,
			today: $this->today
		);

		self::assertSame(30, $rows[0]['elapsedDays']);
		self::assertSame(-20, $rows[0]['remainingDays']);
		self::assertSame(1.5, $rows[0]['percentUsed']);
		self::assertSame(150, $rows[0]['percentUsedDisplay']);
		self::assertSame('overdue', $rows[0]['severity']);
		self::assertSame('Permit', $rows[0]['caseTypeName']);
	}//end testAtRiskFigures()

	/**
	 * Records without a target or a start are never listed.
	 *
	 * @return void
	 */
	public function testAtRiskSkipsRecordsWithoutTargetOrStart(): void {
		$types = ['permit' => $this->type('permit', 10)];
		$rows = $this->calculator->atRisk(
			open: [$this->record('permit', null, null, 'noStart'), $this->record('other', $this->daysAgo(50), null, 'noTarget')],
			types: $types,
			threshold: 0.25,
			today: $this->today
		);

		self::assertSame([], $rows);
	}//end testAtRiskSkipsRecordsWithoutTargetOrStart()

	/**
	 * A threshold of 1 lists every open record with a target, and names the
	 * severity band of each.
	 *
	 * @return void
	 */
	public function testAtRiskSeverityBands(): void {
		$types = ['request' => $this->type('request', 60)];
		$open = [
			$this->record('request', $this->daysAgo(61), null, 'overdue'),
			$this->record('request', $this->daysAgo(55), null, 'critical'),
			$this->record('request', $this->daysAgo(46), null, 'warning'),
			$this->record('request', $this->daysAgo(30), null, 'ok'),
		];

		$rows = $this->calculator->atRisk(open: $open, types: $types, threshold: 1.0, today: $this->today);

		self::assertSame(array_column($rows, 'id'), array_column($rows, 'severity'));
	}//end testAtRiskSeverityBands()

	/**
	 * Fourteen days or fewer is at risk even on a long term, where that is
	 * well inside the last quarter.
	 *
	 * @return void
	 */
	public function testAtRiskWithinFourteenDaysOnALongTerm(): void {
		$types = ['permit' => $this->type('permit', 365)];
		$rows = $this->calculator->atRisk(
			open: [$this->record('permit', $this->daysAgo(351), null, 'x')],
			types: $types,
			threshold: 0.25,
			today: $this->today
		);

		self::assertSame('warning', $rows[0]['severity']);
		self::assertSame(14, $rows[0]['remainingDays']);
	}//end testAtRiskWithinFourteenDaysOnALongTerm()

	/**
	 * The performance band follows the compliance rate; a type without a
	 * target is still listed.
	 *
	 * @return void
	 */
	public function testPerformanceBands(): void {
		$types = [
			'good' => $this->type('good', 30),
			'warn' => $this->type('warn', 30),
			'crit' => $this->type('crit', 30),
			'none' => $this->type('none', null),
		];
		$completed = [
			...array_fill(0, 10, $this->record('good', '2026-01-01', '2026-01-11')),
			...array_fill(0, 8, $this->record('warn', '2026-01-01', '2026-01-11')),
			...array_fill(0, 2, $this->record('warn', '2026-01-01', '2026-03-01')),
			$this->record('crit', '2026-01-01', '2026-01-11'),
			...array_fill(0, 4, $this->record('crit', '2026-01-01', '2026-03-01')),
		];

		$rows = array_column($this->calculator->performance(completed: $completed, types: $types), null, 'id');

		self::assertSame(100, $rows['good']['complianceRate']);
		self::assertSame('good', $rows['good']['status']);
		self::assertSame(10, $rows['good']['avgActualDays']);
		self::assertSame(30, $rows['good']['targetDays']);
		self::assertSame(80, $rows['warn']['complianceRate']);
		self::assertSame('warning', $rows['warn']['status']);
		self::assertSame(20, $rows['crit']['complianceRate']);
		self::assertSame('critical', $rows['crit']['status']);
		self::assertSame('no-target', $rows['none']['status']);
		self::assertNull($rows['none']['complianceRate']);
		self::assertNull($rows['none']['targetDays']);
	}//end testPerformanceBands()

	/**
	 * Types without completed records are listed with null averages.
	 *
	 * @return void
	 */
	public function testPerformanceListsEmptyTypes(): void {
		$rows = $this->calculator->performance(
			completed: [],
			types: ['a' => $this->type('a', 30), 'b' => $this->type('b', null)]
		);

		self::assertCount(2, $rows);
		self::assertSame([0, 0], array_column($rows, 'total'));
		self::assertSame([null, null], array_column($rows, 'avgActualDays'));
		self::assertSame(['no-target', 'no-target'], array_column($rows, 'status'));
	}//end testPerformanceListsEmptyTypes()
}//end class
