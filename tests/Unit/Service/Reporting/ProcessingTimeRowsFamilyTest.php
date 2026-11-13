<?php

/**
 * Case type versions of one family share one performance row, and a case's own deadline sets its term.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Reporting
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Reporting;

use DateTimeImmutable;
use OCA\Dossiq\Service\Reporting\ProcessingTimeRows;
use PHPUnit\Framework\TestCase;

/**
 * Family merging in the performance table, and the running term of an open case.
 *
 * @covers \OCA\Dossiq\Service\Reporting\ProcessingTimeRows
 * @uses \OCA\Dossiq\Service\Reporting\ProcessingTimeCalculator
 * @uses \OCA\Dossiq\Service\Reporting\AtRiskScan
 * @uses \OCA\Dossiq\Service\Reporting\RiskMeasure
 * @uses \OCA\Dossiq\Service\Term\DayCount
 */
class ProcessingTimeRowsFamilyTest extends TestCase {

	private ProcessingTimeRows $rows;

	private DateTimeImmutable $today;

	/**
	 * A fresh row builder and a fixed day.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->rows  = new ProcessingTimeRows();
		$this->today = new DateTimeImmutable('2026-06-15');
	}//end setUp()

	/**
	 * A completed record of a type, started on 1 January.
	 *
	 * @param string $type The case type id.
	 * @param int    $days Days between start and end.
	 *
	 * @return array<string, mixed>
	 */
	private function completed(string $type, int $days): array {
		$start = new DateTimeImmutable('2026-01-01');

		return ['id' => $type.'-'.$days, 'caseType' => $type, 'startDate' => $start->format('Y-m-d'), 'endDate' => $start->modify('+'.$days.' days')->format('Y-m-d')];
	}//end completed()

	/**
	 * Two versions of one family become one row, named after the version with most cases.
	 *
	 * @return void
	 */
	public function testVersionsOfOneFamilyShareTheRowOfTheBusiestVersion(): void {
		$types = [
			'permit-v1' => ['name' => 'Permit (old)', 'targetDays' => 10, 'family' => 'permit'],
			'permit-v2' => ['name' => 'Permit', 'targetDays' => 10, 'family' => 'permit'],
			'objection' => ['name' => 'Objection', 'targetDays' => 20],
		];
		$completed = [
			$this->completed('permit-v1', 4),
			$this->completed('permit-v2', 8),
			$this->completed('permit-v2', 12),
			$this->completed('objection', 30),
		];

		$rows = $this->rows->performance(completed: $completed, types: $types);

		$this->assertSame(
			[
				['id' => 'permit-v2', 'name' => 'Permit', 'targetDays' => 10, 'avgActualDays' => 8, 'complianceRate' => 67, 'total' => 3, 'withinSla' => 2, 'status' => 'critical'],
				['id' => 'objection', 'name' => 'Objection', 'targetDays' => 20, 'avgActualDays' => 30, 'complianceRate' => 0, 'total' => 1, 'withinSla' => 0, 'status' => 'critical'],
			],
			$rows
		);
	}//end testVersionsOfOneFamilyShareTheRowOfTheBusiestVersion()

	/**
	 * On a tie the first listed version leads; records of unknown types and without an end are left out.
	 *
	 * @return void
	 */
	public function testOnATieTheFirstVersionLeadsAndStrangersAreIgnored(): void {
		$types = [
			'a1' => ['name' => 'A one', 'targetDays' => 5, 'family' => 'a'],
			'a2' => ['name' => 'A two', 'targetDays' => 5, 'family' => 'a'],
		];
		$completed = [
			$this->completed('a2', 5),
			$this->completed('a1', 3),
			$this->completed('stranger', 1),
			['caseType' => 'a1', 'startDate' => '2026-01-01', 'endDate' => null],
		];

		$rows = $this->rows->performance(completed: $completed, types: $types);

		$this->assertCount(1, $rows);
		$this->assertSame('a1', $rows[0]['id']);
		$this->assertSame('A one', $rows[0]['name']);
		$this->assertSame(2, $rows[0]['total']);
		$this->assertSame(100, $rows[0]['complianceRate']);
		$this->assertSame('good', $rows[0]['status']);
	}//end testOnATieTheFirstVersionLeadsAndStrangersAreIgnored()

	/**
	 * The status bands sit at 90 and 70 percent, inclusive.
	 *
	 * @return void
	 */
	public function testTheStatusBandEdgesAreInclusive(): void {
		$statusFor = function (int $within, int $late): string {
			$completed = array_merge(
				array_fill(0, $within, $this->completed('t', 10)),
				array_fill(0, $late, $this->completed('t', 11))
			);
			return $this->rows->performance(completed: $completed, types: ['t' => ['name' => 'T', 'targetDays' => 10]])[0]['status'];
		};

		$this->assertSame('good', $statusFor(9, 1));
		$this->assertSame('warning', $statusFor(17, 3));
		$this->assertSame('warning', $statusFor(7, 3));
		$this->assertSame('critical', $statusFor(69, 31));
	}//end testTheStatusBandEdgesAreInclusive()

	/**
	 * A deadline on or before the start date is ignored: the case type's target is the term.
	 *
	 * @return void
	 */
	public function testADeadlineNotAfterTheStartFallsBackToTheTarget(): void {
		$types = ['permit' => ['name' => 'Permit', 'targetDays' => 20]];
		$open  = [
			['id' => 'same-day', 'caseType' => 'permit', 'startDate' => '2026-06-01', 'deadline' => '2026-06-01'],
			['id' => 'before', 'caseType' => 'permit', 'startDate' => '2026-06-01', 'deadline' => '2026-05-01'],
			['id' => 'empty', 'caseType' => 'permit', 'startDate' => '2026-06-01', 'deadline' => ''],
		];

		$rows = $this->rows->atRisk(open: $open, types: $types, threshold: 0.5, today: $this->today);

		// 14 of 20 days used: 6 left, 30 percent of the term, within the 50 percent threshold.
		$this->assertSame(['same-day', 'before', 'empty'], array_column($rows, 'id'));
		foreach ($rows as $row) {
			$this->assertSame(14, $row['elapsedDays']);
			$this->assertSame(6, $row['remainingDays']);
			$this->assertSame(70, $row['percentUsedDisplay']);
			$this->assertSame('critical', $row['severity']);
			$this->assertFalse($row['isOverdue']);
		}
	}//end testADeadlineNotAfterTheStartFallsBackToTheTarget()

	/**
	 * A case with more of its term left than the threshold is not listed.
	 *
	 * @return void
	 */
	public function testACaseWithEnoughTermLeftIsNotAtRisk(): void {
		$types = ['permit' => ['name' => 'Permit', 'targetDays' => 100]];
		$open  = [['id' => 'fresh', 'caseType' => 'permit', 'startDate' => '2026-06-05']];

		$this->assertSame([], $this->rows->atRisk(open: $open, types: $types, threshold: 0.25, today: $this->today));
		$this->assertCount(1, $this->rows->atRisk(open: $open, types: $types, threshold: 0.9, today: $this->today));
	}//end testACaseWithEnoughTermLeftIsNotAtRisk()
}//end class
