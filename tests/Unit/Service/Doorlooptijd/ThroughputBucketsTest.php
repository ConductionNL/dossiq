<?php

/**
 * Throughput Buckets Test
 *
 * Pins the names of the throughput summary groups (month, quarter, year and
 * ISO week) and the empty groups a window opens, from its first day up to and
 * including today's group.
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

use DateTimeImmutable;
use OCA\Dossiq\Service\Doorlooptijd\ThroughputBuckets;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ThroughputBuckets.
 */
#[CoversClass(ThroughputBuckets::class)]
final class ThroughputBucketsTest extends TestCase {
	/**
	 * A day names its month, quarter, year and ISO-week group.
	 *
	 * @return void
	 */
	public function testBucketOfNamesEachGranularity(): void {
		$buckets = new ThroughputBuckets();
		$day     = new DateTimeImmutable('2026-08-18');

		self::assertSame('2026-08', $buckets->bucketOf(day: $day, granularity: 'month'));
		self::assertSame('2026-Q3', $buckets->bucketOf(day: $day, granularity: 'quarter'));
		self::assertSame('2026', $buckets->bucketOf(day: $day, granularity: 'year'));
		self::assertSame('2026-W34', $buckets->bucketOf(day: $day, granularity: 'week'));
	}//end testBucketOfNamesEachGranularity()

	/**
	 * Quarter boundaries: March is Q1, April Q2, December Q4.
	 *
	 * @return void
	 */
	public function testQuarterBoundaries(): void {
		$buckets = new ThroughputBuckets();

		self::assertSame('2026-Q1', $buckets->bucketOf(day: new DateTimeImmutable('2026-03-31'), granularity: 'quarter'));
		self::assertSame('2026-Q2', $buckets->bucketOf(day: new DateTimeImmutable('2026-04-01'), granularity: 'quarter'));
		self::assertSame('2026-Q4', $buckets->bucketOf(day: new DateTimeImmutable('2026-12-31'), granularity: 'quarter'));
	}//end testQuarterBoundaries()

	/**
	 * An unknown granularity falls back to the month group.
	 *
	 * @return void
	 */
	public function testUnknownGranularityFallsBackToMonth(): void {
		$buckets = new ThroughputBuckets();

		self::assertSame('2026-01', $buckets->bucketOf(day: new DateTimeImmutable('2026-01-05'), granularity: 'decade'));
	}//end testUnknownGranularityFallsBackToMonth()

	/**
	 * The ISO week year differs from the calendar year around New Year.
	 *
	 * @return void
	 */
	public function testWeekUsesTheIsoWeekYear(): void {
		$buckets = new ThroughputBuckets();

		self::assertSame('2026-W53', $buckets->bucketOf(day: new DateTimeImmutable('2027-01-01'), granularity: 'week'));
	}//end testWeekUsesTheIsoWeekYear()

	/**
	 * A month window opens one empty group per month, in order, ending at today's month.
	 *
	 * @return void
	 */
	public function testEmptyBucketsPerMonth(): void {
		$buckets = (new ThroughputBuckets())->emptyBuckets(
			start: new DateTimeImmutable('2026-01-01'),
			today: new DateTimeImmutable('2026-03-15'),
			granularity: 'month'
		);

		self::assertSame(['2026-01' => [], '2026-02' => [], '2026-03' => []], $buckets);
	}//end testEmptyBucketsPerMonth()

	/**
	 * A quarter window steps per month but collapses months into their quarter.
	 *
	 * @return void
	 */
	public function testEmptyBucketsPerQuarterCollapseMonths(): void {
		$buckets = (new ThroughputBuckets())->emptyBuckets(
			start: new DateTimeImmutable('2026-01-01'),
			today: new DateTimeImmutable('2026-07-02'),
			granularity: 'quarter'
		);

		self::assertSame(['2026-Q1', '2026-Q2', '2026-Q3'], array_keys($buckets));
	}//end testEmptyBucketsPerQuarterCollapseMonths()

	/**
	 * A week window steps per week and always includes today's week.
	 *
	 * @return void
	 */
	public function testEmptyBucketsPerWeekIncludeToday(): void {
		$buckets = (new ThroughputBuckets())->emptyBuckets(
			start: new DateTimeImmutable('2026-08-03'),
			today: new DateTimeImmutable('2026-08-20'),
			granularity: 'week'
		);

		self::assertSame(['2026-W32', '2026-W33', '2026-W34'], array_keys($buckets));
	}//end testEmptyBucketsPerWeekIncludeToday()

	/**
	 * A start after today still yields today's group, and only that.
	 *
	 * @return void
	 */
	public function testStartAfterTodayYieldsOnlyTodaysGroup(): void {
		$buckets = (new ThroughputBuckets())->emptyBuckets(
			start: new DateTimeImmutable('2026-09-01'),
			today: new DateTimeImmutable('2026-08-20'),
			granularity: 'year'
		);

		self::assertSame(['2026' => []], $buckets);
	}//end testStartAfterTodayYieldsOnlyTodaysGroup()
}//end class
