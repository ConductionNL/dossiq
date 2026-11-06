<?php

/**
 * CaseEnricher unit tests.
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
use OCA\Dossiq\Service\Doorlooptijd\CaseEnricher;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The day counts the throughput dashboard derives for a case.
 *
 * Written against the counts as they were before the day arithmetic moved
 * into one place, so the move can be shown to change none of them: the days
 * left carry a sign, and the throughput of a closed case never goes below
 * zero.
 *
 * @covers \OCA\Dossiq\Service\Doorlooptijd\CaseEnricher
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses \OCA\Dossiq\Service\Support\LanguageMapText
 * @uses \OCA\Dossiq\Service\Term\DayCount
 */
class CaseEnricherTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The enricher over the wall clock, which is the clock it reads.
	 *
	 * @return CaseEnricher
	 */
	private function enricher(): CaseEnricher {
		return new CaseEnricher(
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			dates: $this->caseDates(),
		);
	}//end enricher()

	/**
	 * A date this many days from today, as `Y-m-d`.
	 *
	 * @param int $days Days ahead, negative for the past.
	 *
	 * @return string
	 */
	private function day(int $days): string {
		return (new DateTimeImmutable('today'))->modify(sprintf('%+d days', $days))->format('Y-m-d');
	}//end day()

	/**
	 * Days left are positive ahead of the deadline, zero on it and negative after.
	 *
	 * @return void
	 */
	public function testDaysRemainingCarryTheirSign(): void {
		$rows = $this->enricher()->enrichCases(
			cases: [
				['id' => 'ahead', 'startDate' => $this->day(days: -10), 'deadline' => $this->day(days: 5)],
				['id' => 'today', 'startDate' => $this->day(days: -10), 'deadline' => $this->day(days: 0)],
				['id' => 'late', 'startDate' => $this->day(days: -10), 'deadline' => $this->day(days: -3)],
				['id' => 'far', 'startDate' => $this->day(days: -10), 'deadline' => $this->day(days: 400)],
			],
			caseTypes: [],
		);

		$this->assertSame(expected: [5, 0, -3, 400], actual: array_column($rows, '_daysRemaining'));
		$this->assertSame(expected: [true, true, true, true], actual: array_column($rows, '_isOpen'));
		$this->assertSame(expected: [null, null, null, null], actual: array_map(static fn (array $row): ?int => $row['_throughputDays'], $rows));
	}//end testDaysRemainingCarryTheirSign()

	/**
	 * A case without its own deadline counts down to the one its case type gives.
	 *
	 * @return void
	 */
	public function testDaysRemainingFollowTheCaseTypeTerm(): void {
		$rows = $this->enricher()->enrichCases(
			cases: [
				['id' => 'derived', 'startDate' => $this->day(days: -6), 'caseType' => 'ct-1'],
				['id' => 'by-slug', 'startDate' => $this->day(days: -60), 'caseType' => 'permit'],
				['id' => 'no-term', 'startDate' => $this->day(days: -6), 'caseType' => 'unknown'],
			],
			caseTypes: [['id' => 'ct-1', 'slug' => 'permit', 'processingDeadline' => 'P8W']],
		);

		$this->assertSame(expected: $this->day(days: 50), actual: $rows[0]['_deadline']);
		$this->assertSame(expected: 50, actual: $rows[0]['_daysRemaining']);
		$this->assertSame(expected: -4, actual: $rows[1]['_daysRemaining']);
		$this->assertNull($rows[2]['_deadline']);
		$this->assertNull($rows[2]['_daysRemaining']);
	}//end testDaysRemainingFollowTheCaseTypeTerm()

	/**
	 * A closed case reports how long it took and no days left.
	 *
	 * @return void
	 */
	public function testThroughputIsCountedForAClosedCase(): void {
		$rows = $this->enricher()->enrichCases(
			cases: [
				['id' => 'closed', 'startDate' => '2026-03-02', 'endDate' => '2026-03-12', 'deadline' => '2026-03-30'],
				['id' => 'same-day', 'startDate' => '2026-03-02', 'endDate' => '2026-03-02'],
				// Ended before it started: a typo in one of the two dates, and
				// reported as zero rather than as a negative lead time.
				['id' => 'backwards', 'startDate' => '2026-03-12', 'endDate' => '2026-03-02'],
				['id' => 'over-a-year', 'startDate' => '2024-02-28', 'endDate' => '2025-03-01'],
				['id' => 'no-start', 'endDate' => '2026-03-02'],
			],
			caseTypes: [],
		);

		$this->assertSame(expected: [10, 0, 0, 367, null], actual: array_map(static fn (array $row): ?int => $row['_throughputDays'], $rows));
		$this->assertSame(expected: [null, null, null, null, null], actual: array_map(static fn (array $row): ?int => $row['_daysRemaining'], $rows));
		$this->assertSame(expected: [false, false, false, false, false], actual: array_column($rows, '_isOpen'));
	}//end testThroughputIsCountedForAClosedCase()

	/**
	 * The term a case had, start to deadline in days: the norm the
	 * processing-time chart draws (DqDoorlooptijd "norm 28 dagen").
	 *
	 * @return void
	 */
	public function testNormDaysRunFromStartToDeadline(): void {
		$rows = $this->enricher()->enrichCases(
			cases: [
				['id' => 'own', 'startDate' => '2026-03-02', 'endDate' => '2026-03-12', 'deadline' => '2026-03-30'],
				['id' => 'from-type', 'startDate' => '2026-03-02', 'endDate' => '2026-03-12', 'caseType' => 'ct-1'],
				['id' => 'none', 'startDate' => '2026-03-02', 'endDate' => '2026-03-12'],
			],
			caseTypes: [['id' => 'ct-1', 'processingDeadline' => 'P8W']],
		);

		$this->assertSame(expected: [28, 56, null], actual: array_column($rows, '_normDays'));
	}//end testNormDaysRunFromStartToDeadline()
}//end class
