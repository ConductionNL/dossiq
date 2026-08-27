<?php

/**
 * DwellTimeAnalyzer Unit Tests
 *
 * Direct tests for dwell-interval extraction, per-status aggregation and
 * bottleneck ranking. These exercise the analyser as the unit under test
 * rather than through ProcessMiningService, so the coverage they produce is
 * attributed to this class instead of being discarded as a `@uses`
 * collaborator.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\ProcessMining
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T04
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\ProcessMining;

use DateTimeImmutable;
use OCA\Dossiq\Service\ProcessMining\DwellTimeAnalyzer;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\ProcessMining\DwellTimeAnalyzer
 */
class DwellTimeAnalyzerTest extends TestCase {

	private DwellTimeAnalyzer $analyzer;

	private DateTimeImmutable $now;

	private DateTimeImmutable $from;

	private DateTimeImmutable $to;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->analyzer = new DwellTimeAnalyzer();
		$this->now = new DateTimeImmutable('2026-07-01T00:00:00+00:00');
		$this->from = new DateTimeImmutable('2026-01-01');
		$this->to = new DateTimeImmutable('2026-12-31');

	}//end setUp()

	/**
	 * Helper: run computeDwellIntervals with the shared window.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $records Status records keyed by case id.
	 * @param array<string, array<string, mixed>> $cases Case rows keyed by case id.
	 *
	 * @return array<int, array{caseId: string, statusId: string, hours: float}>
	 */
	private function intervals(array $records, array $cases): array {
		return $this->analyzer->computeDwellIntervals(
			$records,
			$cases,
			$this->now,
			$this->from,
			$this->to
		);

	}//end intervals()

	/**
	 * A case with no status records contributes nothing.
	 *
	 * @return void
	 */
	public function testCaseWithNoRecordsIsSkipped(): void {
		self::assertSame([], $this->intervals(['case-1' => []], ['case-1' => []]));

	}//end testCaseWithNoRecordsIsSkipped()

	/**
	 * The exit boundary of a non-final visit is the next record's timestamp.
	 *
	 * @return void
	 */
	public function testIntermediateVisitExitsAtTheNextRecord(): void {
		$intervals = $this->intervals(
			[
				'case-1' => [
					['statusType' => 'intake', 'createdAt' => '2026-06-01T09:00:00+00:00'],
					['statusType' => 'review', 'createdAt' => '2026-06-02T09:00:00+00:00'],
				],
			],
			['case-1' => ['endDate' => '2026-06-05T09:00:00+00:00']]
		);

		self::assertCount(2, $intervals);
		self::assertSame('case-1', $intervals[0]['caseId']);
		self::assertEqualsWithDelta(24.0, $intervals[0]['hours'], 0.01);
		// Final visit of a closed case runs to endDate: 06-02 09:00 -> 06-05 09:00.
		self::assertEqualsWithDelta(72.0, $intervals[1]['hours'], 0.01);

	}//end testIntermediateVisitExitsAtTheNextRecord()

	/**
	 * An open case's final visit runs to "now", not to a close moment.
	 *
	 * @return void
	 */
	public function testOpenCaseFinalVisitRunsToNow(): void {
		$intervals = $this->intervals(
			['case-1' => [['statusType' => 'intake', 'createdAt' => '2026-06-29T00:00:00+00:00']]],
			['case-1' => ['endDate' => null]]
		);

		self::assertCount(1, $intervals);
		self::assertEqualsWithDelta(48.0, $intervals[0]['hours'], 0.01);

	}//end testOpenCaseFinalVisitRunsToNow()

	/**
	 * A record with no usable statusType or no parsable timestamp is skipped.
	 *
	 * @return void
	 */
	public function testUnusableRecordsAreSkipped(): void {
		$intervals = $this->intervals(
			[
				'case-1' => [
					['statusType' => '', 'createdAt' => '2026-06-01T09:00:00+00:00'],
					['statusType' => 'no-timestamp'],
					['statusType' => 'bad-timestamp', 'createdAt' => 'not-a-date'],
					['statusType' => 'empty-timestamp', 'createdAt' => ''],
				],
			],
			['case-1' => []]
		);

		self::assertSame([], $intervals);

	}//end testUnusableRecordsAreSkipped()

	/**
	 * A visit entered outside the reporting window is not counted, even
	 * though the case itself is in scope.
	 *
	 * @return void
	 */
	public function testVisitsEnteredOutsideTheWindowAreExcluded(): void {
		$intervals = $this->analyzer->computeDwellIntervals(
			[
				'case-1' => [
					['statusType' => 'too-early', 'createdAt' => '2026-05-31T23:00:00+00:00'],
					['statusType' => 'in-window', 'createdAt' => '2026-06-15T09:00:00+00:00'],
					['statusType' => 'too-late', 'createdAt' => '2026-07-01T09:00:00+00:00'],
				],
			],
			['case-1' => ['endDate' => null]],
			new DateTimeImmutable('2026-08-01T00:00:00+00:00'),
			new DateTimeImmutable('2026-06-01'),
			new DateTimeImmutable('2026-06-30')
		);

		self::assertCount(1, $intervals);
		self::assertSame('in-window', $intervals[0]['statusId']);

	}//end testVisitsEnteredOutsideTheWindowAreExcluded()

	/**
	 * A timestamp also resolves from OpenRegister's `@self` metadata block
	 * when the flattened `createdAt` key is absent.
	 *
	 * @return void
	 */
	public function testTimestampFallsBackToSelfMetadata(): void {
		$viaCreated = $this->intervals(
			['case-1' => [['statusType' => 'intake', '@self' => ['created' => '2026-06-29T00:00:00+00:00']]]],
			['case-1' => []]
		);
		self::assertCount(1, $viaCreated);
		self::assertEqualsWithDelta(48.0, $viaCreated[0]['hours'], 0.01);

		$viaCreatedAt = $this->intervals(
			['case-1' => [['statusType' => 'intake', '@self' => ['createdAt' => '2026-06-29T00:00:00+00:00']]]],
			['case-1' => []]
		);
		self::assertCount(1, $viaCreatedAt);
		self::assertEqualsWithDelta(48.0, $viaCreatedAt[0]['hours'], 0.01);

	}//end testTimestampFallsBackToSelfMetadata()

	/**
	 * An out-of-order history would give a negative duration; it is clamped
	 * to zero rather than reported as negative dwell time.
	 *
	 * @return void
	 */
	public function testNegativeDurationIsClampedToZero(): void {
		$intervals = $this->intervals(
			[
				'case-1' => [
					['statusType' => 'later', 'createdAt' => '2026-06-10T09:00:00+00:00'],
					['statusType' => 'earlier', 'createdAt' => '2026-06-01T09:00:00+00:00'],
				],
			],
			['case-1' => ['endDate' => '2026-06-20T09:00:00+00:00']]
		);

		self::assertCount(2, $intervals);
		self::assertSame(0.0, $intervals[0]['hours']);

	}//end testNegativeDurationIsClampedToZero()

	/**
	 * An unparseable endDate falls back to "now" rather than dropping the
	 * case.
	 *
	 * @return void
	 */
	public function testUnparseableEndDateFallsBackToNow(): void {
		$intervals = $this->intervals(
			['case-1' => [['statusType' => 'intake', 'createdAt' => '2026-06-29T00:00:00+00:00']]],
			['case-1' => ['endDate' => 'not-a-date']]
		);

		self::assertCount(1, $intervals);
		self::assertEqualsWithDelta(48.0, $intervals[0]['hours'], 0.01);

	}//end testUnparseableEndDateFallsBackToNow()

	/**
	 * Intervals group by status into median / p90 / mean, and the status id
	 * resolves to a human label.
	 *
	 * @return void
	 */
	public function testAggregateComputesMedianP90AndMean(): void {
		$stats = $this->analyzer->aggregateDwellStats(
			[
				['caseId' => 'c1', 'statusId' => 'review', 'hours' => 10.0],
				['caseId' => 'c2', 'statusId' => 'review', 'hours' => 20.0],
				['caseId' => 'c3', 'statusId' => 'review', 'hours' => 30.0],
				['caseId' => 'c4', 'statusId' => 'review', 'hours' => 40.0],
			],
			['review' => ['name' => 'Under Review']]
		);

		self::assertCount(1, $stats);
		self::assertSame('Under Review', $stats[0]['statusName']);
		self::assertSame(4, $stats[0]['visitCount']);
		// Nearest-rank: p50 of 4 items is rank 2 (20), p90 is rank 4 (40).
		self::assertSame(20.0, $stats[0]['medianHours']);
		self::assertSame(40.0, $stats[0]['p90Hours']);
		self::assertSame(25.0, $stats[0]['meanHours']);

	}//end testAggregateComputesMedianP90AndMean()

	/**
	 * A single observation is its own median and p90.
	 *
	 * @return void
	 */
	public function testAggregateHandlesASingleObservation(): void {
		$stats = $this->analyzer->aggregateDwellStats(
			[['caseId' => 'c1', 'statusId' => 'intake', 'hours' => 7.0]],
			[]
		);

		self::assertSame(7.0, $stats[0]['medianHours']);
		self::assertSame(7.0, $stats[0]['p90Hours']);
		self::assertSame(7.0, $stats[0]['meanHours']);
		// No index entry, so the label is the raw id.
		self::assertSame('intake', $stats[0]['statusName']);

	}//end testAggregateHandlesASingleObservation()

	/**
	 * Bottleneck score is median dwell x visit volume, ranked highest first.
	 *
	 * @return void
	 */
	public function testBottlenecksAreRankedByMedianTimesVolume(): void {
		$ranked = $this->analyzer->rankBottlenecks(
			[
				[
					'statusId' => 'slow-but-rare',
					'statusName' => 'Slow',
					'visitCount' => 1,
					'medianHours' => 100.0,
					'p90Hours' => 100.0,
					'meanHours' => 100.0,
				],
				[
					'statusId' => 'quick-but-common',
					'statusName' => 'Quick',
					'visitCount' => 50,
					'medianHours' => 10.0,
					'p90Hours' => 12.0,
					'meanHours' => 11.0,
				],
			]
		);

		self::assertCount(2, $ranked);
		self::assertSame('quick-but-common', $ranked[0]['statusId']);
		self::assertSame(500.0, $ranked[0]['score']);
		self::assertSame(100.0, $ranked[1]['score']);

	}//end testBottlenecksAreRankedByMedianTimesVolume()

	/**
	 * Ranking an empty stats list is an empty list, not an error.
	 *
	 * @return void
	 */
	public function testRankingEmptyStatsYieldsEmptyList(): void {
		self::assertSame([], $this->analyzer->rankBottlenecks([]));

	}//end testRankingEmptyStatsYieldsEmptyList()

}//end class
