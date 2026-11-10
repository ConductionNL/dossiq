<?php

/**
 * Dossiq ClosedCaseTally.
 *
 * The closed cases a throughput summary averages: the day counts of the last
 * twelve months, the count closed this year and the day counts per group.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Doorlooptijd
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Doorlooptijd;

use DateTimeImmutable;

/**
 * Tallies closed cases into the figures of one throughput summary.
 *
 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
 */
final class ClosedCaseTally {
	/**
	 * The day the figures are read on.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $today;

	/**
	 * The first end date (`Y-m-d`) that counts towards the twelve-month average.
	 *
	 * @var string
	 */
	private string $cutoff;

	/**
	 * The calendar year (`Y`) the closed count is taken for.
	 *
	 * @var string
	 */
	private string $year;

	/**
	 * `month`, `quarter`, `year` or `week`.
	 *
	 * @var string
	 */
	private string $granularity;

	/**
	 * Names and lists the groups.
	 *
	 * @var ThroughputBuckets
	 */
	private ThroughputBuckets $grouping;

	/**
	 * The day counts per group, oldest group first.
	 *
	 * @var array<string, array<int, int>>
	 */
	private array $buckets = [];

	/**
	 * The day counts of the cases closed in the last twelve months.
	 *
	 * @var array<int, int>
	 */
	private array $recent = [];

	/**
	 * How many cases closed this calendar year.
	 *
	 * @var integer
	 */
	private int $closedThisYear = 0;

	/**
	 * Start a tally read on a day, grouped one way.
	 *
	 * @param DateTimeImmutable $today       The day the figures are read on.
	 * @param string            $granularity `month`, `quarter`, `year` or `week`.
	 */
	public function __construct(DateTimeImmutable $today, string $granularity) {
		$this->today = $today;
		$this->cutoff = $today->modify('-12 months')->format('Y-m-d');
		$this->year = $today->format('Y');
		$this->granularity = $granularity;
		$this->grouping = new ThroughputBuckets();
	}//end __construct()

	/**
	 * Open the empty groups of a window of months that ends this month.
	 *
	 * @param int $months The window's length in months.
	 *
	 * @return void
	 */
	public function openWindow(int $months): void {
		$this->buckets = $this->grouping->emptyBuckets(
			start: $this->today->modify('first day of this month')->modify('-' . ($months - 1) . ' month'),
			today: $this->today,
			granularity: $this->granularity
		);
	}//end openWindow()

	/**
	 * Count one enriched case; open cases and cases without an end date or a
	 * day count are left out.
	 *
	 * @param array<string, mixed> $caseData An enriched case.
	 *
	 * @return void
	 */
	public function add(array $caseData): void {
		$end = $caseData['_endDate'];
		$days = $caseData['_throughputDays'];
		if ($caseData['_isOpen'] === true || $end === null || $days === null) {
			return;
		}

		$this->countClosed(end: $end, days: $days);
	}//end add()

	/**
	 * Count a closed case towards the year, the twelve months and its group.
	 *
	 * @param string $end  The case's end date.
	 * @param int    $days The days the case took.
	 *
	 * @return void
	 */
	private function countClosed(mixed $end, mixed $days): void {
		if (str_starts_with($end, $this->year) === true) {
			$this->closedThisYear++;
		}

		if ($end >= $this->cutoff) {
			$this->recent[] = $days;
		}

		$bucket = $this->grouping->bucketOf(day: new DateTimeImmutable(substr($end, 0, 10)), granularity: $this->granularity);
		if (isset($this->buckets[$bucket]) === true) {
			$this->buckets[$bucket][] = $days;
		}
	}//end countClosed()

	/**
	 * The day counts per group, oldest group first.
	 *
	 * @return array<string, array<int, int>>
	 */
	public function buckets(): array {
		return $this->buckets;
	}//end buckets()

	/**
	 * The day counts of the cases closed in the last twelve months.
	 *
	 * @return array<int, int>
	 */
	public function recentDays(): array {
		return $this->recent;
	}//end recentDays()

	/**
	 * How many cases closed this calendar year.
	 *
	 * @return int
	 */
	public function closedThisYear(): int {
		return $this->closedThisYear;
	}//end closedThisYear()

	/**
	 * The grouping the tally uses.
	 *
	 * @return string
	 */
	public function granularity(): string {
		return $this->granularity;
	}//end granularity()
}//end class
