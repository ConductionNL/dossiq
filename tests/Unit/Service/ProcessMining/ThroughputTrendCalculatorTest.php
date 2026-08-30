<?php

/**
 * ThroughputTrendCalculator Unit Tests
 *
 * Direct tests for the weekly closed-case throughput trend. These exercise
 * the calculator as the unit under test rather than through
 * ProcessMiningService, so the coverage they produce is attributed to this
 * class instead of being discarded as a `@uses` collaborator.
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
use OCA\Dossiq\Service\ProcessMining\ThroughputTrendCalculator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\ProcessMining\ThroughputTrendCalculator
 */
class ThroughputTrendCalculatorTest extends TestCase {

	private ThroughputTrendCalculator $calculator;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->calculator = new ThroughputTrendCalculator();

	}//end setUp()

	/**
	 * Every ISO week in range is seeded, so a week with no closure is an
	 * explicit zero rather than a gap the leaf would have to infer.
	 *
	 * @return void
	 */
	public function testEveryWeekInRangeIsSeededToZero(): void {
		$from = new DateTimeImmutable('2026-01-05');
		$to = new DateTimeImmutable('2026-01-25');

		$trend = $this->calculator->computeThroughputTrend([], $from, $to);

		// Buckets start on the Monday of `from`'s week and step a week at a
		// time while the cursor is still <= `to`: 01-05, 01-12, 01-19.
		self::assertCount(3, $trend);
		foreach ($trend as $bucket) {
			self::assertSame(0, $bucket['count']);
		}

		self::assertSame('2026-W02', $trend[0]['week']);
		self::assertSame('2026-W04', $trend[2]['week']);

	}//end testEveryWeekInRangeIsSeededToZero()

	/**
	 * A closure lands in the ISO week of its endDate.
	 *
	 * @return void
	 */
	public function testClosuresAreCountedIntoTheirIsoWeek(): void {
		$from = new DateTimeImmutable('2026-01-05');
		$to = new DateTimeImmutable('2026-01-25');

		$cases = [
			'case-1' => ['endDate' => '2026-01-06T09:00:00+00:00'],
			'case-2' => ['endDate' => '2026-01-08T17:30:00+00:00'],
			'case-3' => ['endDate' => '2026-01-20T09:00:00+00:00'],
		];

		$trend = $this->calculator->computeThroughputTrend($cases, $from, $to);

		$byWeek = [];
		foreach ($trend as $bucket) {
			$byWeek[$bucket['week']] = $bucket['count'];
		}

		// 2026-01-06 and 2026-01-08 are both in ISO week 2026-W02.
		self::assertSame(2, $byWeek['2026-W02']);
		self::assertSame(1, $byWeek['2026-W04']);
		self::assertSame(0, $byWeek['2026-W03']);

	}//end testClosuresAreCountedIntoTheirIsoWeek()

	/**
	 * A case closed outside `[from, to]` is not counted.
	 *
	 * @return void
	 */
	public function testClosuresOutsideThePeriodAreIgnored(): void {
		$from = new DateTimeImmutable('2026-01-05');
		$to = new DateTimeImmutable('2026-01-25');

		$cases = [
			'before' => ['endDate' => '2025-12-30T09:00:00+00:00'],
			'after' => ['endDate' => '2026-03-01T09:00:00+00:00'],
		];

		$trend = $this->calculator->computeThroughputTrend($cases, $from, $to);

		$total = 0;
		foreach ($trend as $bucket) {
			$total += $bucket['count'];
		}

		self::assertSame(0, $total);

	}//end testClosuresOutsideThePeriodAreIgnored()

	/**
	 * A missing, empty, non-string or unparseable endDate is skipped rather
	 * than throwing or counting as a closure.
	 *
	 * @return void
	 */
	public function testUnusableEndDatesAreSkipped(): void {
		$from = new DateTimeImmutable('2026-01-05');
		$to = new DateTimeImmutable('2026-01-25');

		$cases = [
			'missing' => [],
			'null' => ['endDate' => null],
			'empty' => ['endDate' => ''],
			'not-a-string' => ['endDate' => 12345],
			'garbage' => ['endDate' => 'not-a-date'],
			'good' => ['endDate' => '2026-01-06T09:00:00+00:00'],
		];

		$trend = $this->calculator->computeThroughputTrend($cases, $from, $to);

		$total = 0;
		foreach ($trend as $bucket) {
			$total += $bucket['count'];
		}

		self::assertSame(1, $total);

	}//end testUnusableEndDatesAreSkipped()

	/**
	 * The series is returned in ascending ISO-week order.
	 *
	 * @return void
	 */
	public function testSeriesIsSortedAscendingByWeek(): void {
		$from = new DateTimeImmutable('2026-01-05');
		$to = new DateTimeImmutable('2026-02-15');

		$trend = $this->calculator->computeThroughputTrend([], $from, $to);

		$weeks = array_column($trend, 'week');
		$sorted = $weeks;
		sort($sorted);

		self::assertSame($sorted, $weeks);

	}//end testSeriesIsSortedAscendingByWeek()

	/**
	 * A period shorter than one week still yields the single week that
	 * contains it.
	 *
	 * @return void
	 */
	public function testSingleDayPeriodYieldsOneBucket(): void {
		// `to` is compared against the parsed endDate as-is — this calculator
		// does not widen it to end-of-day the way the dwell analyser does — so
		// a same-day window must be spelled out to its last second.
		$from = new DateTimeImmutable('2026-01-07 00:00:00');
		$to = new DateTimeImmutable('2026-01-07 23:59:59');

		$trend = $this->calculator->computeThroughputTrend(
			['case-1' => ['endDate' => '2026-01-07T12:00:00+00:00']],
			$from,
			$to
		);

		self::assertCount(1, $trend);
		self::assertSame('2026-W02', $trend[0]['week']);
		self::assertSame(1, $trend[0]['count']);

	}//end testSingleDayPeriodYieldsOneBucket()

}//end class
