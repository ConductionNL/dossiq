<?php

/**
 * Dossiq ThroughputTrendCalculator.
 *
 * The throughput metric family of the process-mining report: how many cases
 * were closed (by `endDate`) per ISO week within the reporting period. Split
 * out of ProcessMiningService so that service keeps only the orchestration.
 *
 * Every ISO week in range is seeded to zero first, so a week with no closures
 * renders as an explicit zero rather than a gap in the series — the leaf that
 * draws the trend line must not have to infer missing buckets.
 *
 * Pure computation: every input is passed in, nothing is read from
 * OpenRegister.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\ProcessMining
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
 * @spec openspec/changes/archive/2026-07-14-process-mining-bottlenecks/tasks.md#T01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\ProcessMining;

use DateTimeImmutable;
use OCA\Dossiq\Service\CaseDateNormaliser;

/**
 * Computes the weekly closed-case throughput trend.
 *
 * @spec openspec/changes/archive/2026-07-14-process-mining-bottlenecks/tasks.md#T01
 */
class ThroughputTrendCalculator {
	/**
	 * Constructor.
	 *
	 * @param CaseDateNormaliser $dates The one date write path.
	 */
	public function __construct(
		private readonly CaseDateNormaliser $dates,
	) {
	}//end __construct()

	/**
	 * Weekly throughput trend: cases closed (by `endDate`) per ISO week
	 * within `[from, to]`.
	 *
	 * @param array<string, array<string, mixed>> $cases Case rows, keyed by id.
	 * @param DateTimeImmutable $from Inclusive period start.
	 * @param DateTimeImmutable $to Inclusive period end.
	 *
	 * @return array<int, array{week: string, count: int}>
	 *
	 * @spec openspec/changes/archive/2026-07-14-process-mining-bottlenecks/tasks.md#T01
	 */
	public function computeThroughputTrend(array $cases, DateTimeImmutable $from, DateTimeImmutable $to): array {
		// Seed every ISO week in range so gaps render as zero, not "missing".
		$buckets = $this->seedWeekBuckets(from: $from, to: $to);

		foreach ($cases as $caseData) {
			$week = $this->closedWeek(caseData: $caseData, from: $from, to: $to);
			if ($week === '') {
				continue;
			}

			if (isset($buckets[$week]) === false) {
				$buckets[$week] = 0;
			}

			$buckets[$week]++;
		}//end foreach

		return $this->sortedWeeks(buckets: $buckets);
	}//end computeThroughputTrend()

	/**
	 * The ISO week a case closed in, when it closed within `[from, to]`.
	 *
	 * @param array<string, mixed> $caseData A case row.
	 * @param DateTimeImmutable    $from     Inclusive period start.
	 * @param DateTimeImmutable    $to       Inclusive period end.
	 *
	 * @return string The ISO week ("o-\WW"), or '' when it did not close in the period.
	 */
	private function closedWeek(array $caseData, DateTimeImmutable $from, DateTimeImmutable $to): string {
		$closedAt = $this->closedAt(caseData: $caseData);
		if ($closedAt === null || $closedAt < $from || $closedAt > $to) {
			return '';
		}

		return $closedAt->format('o-\WW');
	}//end closedWeek()

	/**
	 * The moment a case closed, by its `endDate`.
	 *
	 * @param array<string, mixed> $caseData A case row.
	 *
	 * @return DateTimeImmutable|null The moment, or null when it has none or it does not parse.
	 */
	private function closedAt(array $caseData): DateTimeImmutable|null {
		$endDate = ($caseData['endDate'] ?? null);
		if (is_string($endDate) === false || $endDate === '') {
			return null;
		}

		return $this->dates->tryParse($endDate);
	}//end closedAt()

	/**
	 * The week buckets as rows, in week order.
	 *
	 * @param array<string, int> $buckets The counts, keyed by ISO week.
	 *
	 * @return array<int, array{week: string, count: int}>
	 */
	private function sortedWeeks(array $buckets): array {
		$out = [];
		foreach ($buckets as $week => $count) {
			$out[] = ['week' => $week, 'count' => $count];
		}

		// `$out` is built with `$out[] =`, so its keys are already 0..n and
		// the ksort() that stood here sorted nothing. The usort below is what
		// puts the weeks in order.
		usort($out, static fn (array $left, array $right): int => strcmp($left['week'], $right['week']));

		return $out;
	}//end sortedWeeks()

	/**
	 * Seed a zero-valued bucket for every ISO week that starts within `[from, to]`.
	 *
	 * @param DateTimeImmutable $from Inclusive period start.
	 * @param DateTimeImmutable $to Inclusive period end.
	 *
	 * @return array<string, int> Zero-valued buckets, keyed by ISO week ("o-\WW").
	 *
	 * @spec openspec/changes/archive/2026-07-14-process-mining-bottlenecks/tasks.md#T01
	 */
	private function seedWeekBuckets(DateTimeImmutable $from, DateTimeImmutable $to): array {
		$buckets = [];
		$cursor = $from->modify('monday this week');
		while ($cursor <= $to) {
			$buckets[$cursor->format('o-\WW')] = 0;
			$cursor = $cursor->modify('+1 week');
		}

		return $buckets;
	}//end seedWeekBuckets()

}//end class
