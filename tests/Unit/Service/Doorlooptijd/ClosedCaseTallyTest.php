<?php

/**
 * Closed Case Tally Test
 *
 * Pins how closed cases are counted for the throughput summary: open and
 * incomplete cases are skipped, a closed case counts towards this calendar
 * year, the last twelve months and its bucket in the window, and a case
 * outside the window lands in no bucket.
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
use OCA\Dossiq\Service\Doorlooptijd\ClosedCaseTally;
use OCA\Dossiq\Service\Doorlooptijd\ThroughputBuckets;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ClosedCaseTally.
 */
#[CoversClass(ClosedCaseTally::class)]
#[UsesClass(ThroughputBuckets::class)]
final class ClosedCaseTallyTest extends TestCase {
	/**
	 * A case row as the calculators hand it over.
	 *
	 * @param string|null $end    The end date.
	 * @param int|null    $days   The throughput in days.
	 * @param bool        $isOpen Whether the case is open.
	 *
	 * @return array<string,mixed>
	 */
	private static function closedCase(?string $end, ?int $days, bool $isOpen = false): array {
		return ['_endDate' => $end, '_throughputDays' => $days, '_isOpen' => $isOpen];
	}//end closedCase()

	/**
	 * A three-month window per month has one empty bucket per month, ending at today's month.
	 *
	 * @return void
	 */
	public function testWindowOpensOneEmptyBucketPerMonth(): void {
		$tally = new ClosedCaseTally(today: new DateTimeImmutable('2026-03-15'), granularity: 'month');

		$tally->openWindow(months: 3);

		self::assertSame(expected: ['2026-01' => [], '2026-02' => [], '2026-03' => []], actual: $tally->buckets());
		self::assertSame(expected: 'month', actual: $tally->granularity());
	}//end testWindowOpensOneEmptyBucketPerMonth()

	/**
	 * A closed case counts towards its bucket, the last twelve months and this year.
	 *
	 * @return void
	 */
	public function testClosedCaseIsCountedEverywhere(): void {
		$tally = new ClosedCaseTally(today: new DateTimeImmutable('2026-03-15'), granularity: 'month');
		$tally->openWindow(months: 3);

		$tally->add(caseData: self::closedCase(end: '2026-02-10', days: 12));
		$tally->add(caseData: self::closedCase(end: '2026-02-20T09:00:00+01:00', days: 30));

		self::assertSame(expected: ['2026-01' => [], '2026-02' => [12, 30], '2026-03' => []], actual: $tally->buckets());
		self::assertSame(expected: [12, 30], actual: $tally->recentDays());
		self::assertSame(expected: 2, actual: $tally->closedThisYear());
	}//end testClosedCaseIsCountedEverywhere()

	/**
	 * Open cases, and cases without an end date or a throughput, are not counted.
	 *
	 * @return void
	 */
	public function testOpenAndIncompleteCasesAreSkipped(): void {
		$tally = new ClosedCaseTally(today: new DateTimeImmutable('2026-03-15'), granularity: 'month');
		$tally->openWindow(months: 1);

		$tally->add(caseData: self::closedCase(end: '2026-03-01', days: 5, isOpen: true));
		$tally->add(caseData: self::closedCase(end: null, days: 5));
		$tally->add(caseData: self::closedCase(end: '2026-03-01', days: null));

		self::assertSame(expected: ['2026-03' => []], actual: $tally->buckets());
		self::assertSame(expected: [], actual: $tally->recentDays());
		self::assertSame(expected: 0, actual: $tally->closedThisYear());
	}//end testOpenAndIncompleteCasesAreSkipped()

	/**
	 * A case closed last year within twelve months counts as recent, not as this year, and
	 * lands in no bucket outside the window; one closed before the cutoff is not recent.
	 *
	 * @return void
	 */
	public function testYearCutoffAndWindowAreSeparate(): void {
		$tally = new ClosedCaseTally(today: new DateTimeImmutable('2026-03-15'), granularity: 'month');
		$tally->openWindow(months: 2);

		$tally->add(caseData: self::closedCase(end: '2025-12-01', days: 40));
		$tally->add(caseData: self::closedCase(end: '2025-03-15', days: 50));
		$tally->add(caseData: self::closedCase(end: '2025-03-14', days: 60));

		self::assertSame(expected: ['2026-02' => [], '2026-03' => []], actual: $tally->buckets());
		self::assertSame(expected: [40, 50], actual: $tally->recentDays());
		self::assertSame(expected: 0, actual: $tally->closedThisYear());
	}//end testYearCutoffAndWindowAreSeparate()

	/**
	 * Per quarter, cases are grouped into the quarter of their end date.
	 *
	 * @return void
	 */
	public function testQuarterGranularityGroupsPerQuarter(): void {
		$tally = new ClosedCaseTally(today: new DateTimeImmutable('2026-05-10'), granularity: 'quarter');
		$tally->openWindow(months: 6);

		$tally->add(caseData: self::closedCase(end: '2026-01-05', days: 3));
		$tally->add(caseData: self::closedCase(end: '2026-04-30', days: 7));

		self::assertSame(expected: ['2025-Q4' => [], '2026-Q1' => [3], '2026-Q2' => [7]], actual: $tally->buckets());
		self::assertSame(expected: 'quarter', actual: $tally->granularity());
	}//end testQuarterGranularityGroupsPerQuarter()

	/**
	 * Without an opened window cases are still counted for the year and as recent, but in no bucket.
	 *
	 * @return void
	 */
	public function testWithoutWindowNoBucketIsFilled(): void {
		$tally = new ClosedCaseTally(today: new DateTimeImmutable('2026-03-15'), granularity: 'month');

		$tally->add(caseData: self::closedCase(end: '2026-03-01', days: 9));

		self::assertSame(expected: [], actual: $tally->buckets());
		self::assertSame(expected: [9], actual: $tally->recentDays());
		self::assertSame(expected: 1, actual: $tally->closedThisYear());
	}//end testWithoutWindowNoBucketIsFilled()
}//end class
