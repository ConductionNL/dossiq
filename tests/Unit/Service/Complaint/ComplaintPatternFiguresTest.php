<?php

/**
 * Unit tests for ComplaintPatternFigures: a quarter's complaints are counted
 * by category, department and channel with sharp rises against the previous
 * quarter flagged, by month over the trailing year, and by how they were
 * handled against the Awb term.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Complaint;

use OCA\Dossiq\Service\Complaint\ComplaintPatternFigures;
use OCA\Dossiq\Service\Complaint\QuarterPeriod;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Complaint\ComplaintPatternFigures
 *
 * @uses \OCA\Dossiq\Service\Complaint\QuarterPeriod
 * @uses \OCA\Dossiq\Service\Term\DayCount
 */
class ComplaintPatternFiguresTest extends TestCase {

	/**
	 * @return list<array<string, string>>
	 */
	private function complaints(): array {
		return [
			['id' => '1', 'receiptDate' => '2026-07-05', 'category' => 'wachttijd', 'involvedDepartment' => 'Burgerzaken', 'receiptChannel' => 'web'],
			['id' => '2', 'receiptDate' => '2026-07-20', 'category' => 'wachttijd', 'involvedDepartment' => 'Burgerzaken', 'receiptChannel' => 'telefoon', 'afhandelDeadline' => '2026-09-01'],
			['id' => '3', 'receiptDate' => '2026-08-10', 'category' => 'bejegening', 'involvedDepartment' => 'Vergunningen', 'receiptChannel' => 'web'],
			['id' => '4', 'receiptDate' => '2026-09-30', 'category' => 'wachttijd', 'involvedDepartment' => ' ', 'receiptChannel' => 'web'],
			['id' => '5', 'receiptDate' => '2026-04-15', 'category' => 'wachttijd'],
			['id' => '6', 'receiptDate' => '2026-05-01', 'category' => 'bejegening'],
			['id' => '7', 'receiptDate' => '2025-12-01', 'category' => 'wachttijd'],
			['id' => '8', 'receiptDate' => '2024-01-01', 'category' => 'wachttijd'],
		];
	}//end complaints()

	/**
	 * @return array<string, array<string, string>>
	 */
	private function dispositions(): array {
		return [
			'1' => ['closureDate' => '2026-07-15', 'opinion' => 'upheld'],
			'2' => ['closureDate' => '2026-09-15', 'opinion' => 'not_upheld'],
			'3' => ['closureDate' => '2026-08-12T10:00:00Z', 'opinion' => 'partly_upheld'],
		];
	}//end dispositions()

	private function figures(): array {
		return (new ComplaintPatternFigures())->compute(new QuarterPeriod(2026, 3), $this->complaints(), $this->dispositions());
	}//end figures()

	public function testOnlyTheQuartersComplaintsAreCounted(): void {
		$this->assertSame(4, $this->figures()['count']);
	}//end testOnlyTheQuartersComplaintsAreCounted()

	public function testCategoriesAreRankedWithSharpRisesFlagged(): void {
		$this->assertSame(
			['labels' => ['wachttijd', 'bejegening'], 'counts' => [3, 1], 'risePercent' => [200, null], 'previousCounts' => [1, 1]],
			$this->figures()['byCategory']
		);
	}//end testCategoriesAreRankedWithSharpRisesFlagged()

	public function testDepartmentsSkipBlanksAndARiseFromNothingIsNotFlagged(): void {
		$this->assertSame(
			['labels' => ['Burgerzaken', 'Vergunningen'], 'counts' => [2, 1], 'risePercent' => [null, null], 'previousCounts' => [0, 0]],
			$this->figures()['byDepartment']
		);
	}//end testDepartmentsSkipBlanksAndARiseFromNothingIsNotFlagged()

	public function testChannelsCarryNoComparison(): void {
		$this->assertSame(['labels' => ['web', 'telefoon'], 'counts' => [3, 1]], $this->figures()['byChannel']);
	}//end testChannelsCarryNoComparison()

	public function testMonthsCoverTheTrailingYear(): void {
		$this->assertSame(
			[
				'labels' => ['2025-10', '2025-11', '2025-12', '2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09'],
				'counts' => [0, 0, 1, 0, 0, 0, 1, 1, 0, 2, 1, 1],
			],
			$this->figures()['byMonth']
		);
	}//end testMonthsCoverTheTrailingYear()

	public function testHandlingIsMeasuredOnTheClosedComplaints(): void {
		$this->assertSame(
			[
				'handled' => 3,
				'withinAwbPercent' => 67,
				'upheldPercent' => 67,
				'averageDays' => 23,
				'resolutionByCategory' => [
					['category' => 'wachttijd', 'count' => 3, 'averageDays' => 34, 'upheldPercent' => 50],
					['category' => 'bejegening', 'count' => 1, 'averageDays' => 2, 'upheldPercent' => 100],
				],
			],
			$this->figures()['handling']
		);
	}//end testHandlingIsMeasuredOnTheClosedComplaints()

	public function testARiseOfExactlyFiftyPercentIsNotFlagged(): void {
		$complaints = [
			['id' => 'a', 'receiptDate' => '2026-07-01', 'category' => 'x'],
			['id' => 'b', 'receiptDate' => '2026-07-02', 'category' => 'x'],
			['id' => 'c', 'receiptDate' => '2026-07-03', 'category' => 'x'],
			['id' => 'd', 'receiptDate' => '2026-04-01', 'category' => 'x'],
			['id' => 'e', 'receiptDate' => '2026-04-02', 'category' => 'x'],
		];

		$this->assertSame([null], (new ComplaintPatternFigures())->compute(new QuarterPeriod(2026, 3), $complaints, [])['byCategory']['risePercent']);
	}//end testARiseOfExactlyFiftyPercentIsNotFlagged()

	public function testAQuarterWithoutComplaintsHasNoMeasures(): void {
		$figures = (new ComplaintPatternFigures())->compute(new QuarterPeriod(2026, 3), [], []);

		$this->assertSame(0, $figures['count']);
		$this->assertSame(['labels' => [], 'counts' => [], 'risePercent' => [], 'previousCounts' => []], $figures['byCategory']);
		$this->assertSame(
			['handled' => 0, 'withinAwbPercent' => null, 'upheldPercent' => null, 'averageDays' => null, 'resolutionByCategory' => []],
			$figures['handling']
		);
	}//end testAQuarterWithoutComplaintsHasNoMeasures()
}//end class
