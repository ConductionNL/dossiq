<?php

/**
 * Records closed per ISO week.
 *
 * The throughput chart of the processing-time dashboard: one bucket per ISO
 * week over the trailing weeks, ending at the week of the most recent
 * completion, each counting the records that ended in it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/dashboard/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Reporting;

use DateInterval;
use DateTimeImmutable;

/**
 * Pure arithmetic: the end dates of prepared records counted per ISO week.
 *
 * @spec openspec/specs/dashboard/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class WeeklyThroughput {

	/**
	 * Records closed per ISO week over the trailing weeks ending at the most
	 * recent completion.
	 *
	 * @param array<int, array<string, mixed>> $completed Completed records.
	 * @param int                              $weeks     Weeks to cover.
	 *
	 * @return array<int, array{weekLabel: string, week: string, count: int}> Oldest week first, or [] without completions.
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function rows(array $completed, int $weeks = 12): array {
		$ends = $this->endDatesOf(completed: $completed);

		if ($ends === []) {
			return [];
		}

		$anchor  = $this->mondayOf(date: max($ends));
		$buckets = $this->emptyWeeks(anchor: $anchor, weeks: $weeks);
		$buckets = $this->countedWeeks(buckets: $buckets, ends: $ends);

		return array_values($buckets);
	}//end rows()

	/**
	 * The end dates of the completed records that carry one.
	 *
	 * @param array<int, array<string, mixed>> $completed Completed records.
	 *
	 * @return array<int, string> The end dates.
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function endDatesOf(array $completed): array {
		$ends = [];
		foreach ($completed as $record) {
			$end = ($record['endDate'] ?? null);
			if (is_string($end) === true && $end !== '') {
				$ends[] = $end;
			}
		}

		return $ends;
	}//end endDatesOf()

	/**
	 * One zero bucket per ISO week, keyed by its Monday, oldest first, ending at the anchor week.
	 *
	 * @param DateTimeImmutable $anchor The Monday of the most recent week.
	 * @param int               $weeks  Weeks to cover.
	 *
	 * @return array<string, array{weekLabel: string, week: string, count: int}> The buckets.
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function emptyWeeks(DateTimeImmutable $anchor, int $weeks): array {
		$buckets = [];
		for ($offset = ($weeks - 1); $offset >= 0; $offset--) {
			$monday = $anchor->sub(interval: new DateInterval('P'.($offset * 7).'D'));
			$buckets[$monday->format('Y-m-d')] = [
				'weekLabel' => 'W'.(int)$monday->format('W').' '.$monday->format('o'),
				// The ISO week key a chart writes as "w40".
				'week'      => $monday->format('o-\\WW'),
				'count'     => 0,
			];
		}

		return $buckets;
	}//end emptyWeeks()

	/**
	 * The buckets with each end date counted in its week.
	 *
	 * @param array<string, array{weekLabel: string, week: string, count: int}> $buckets The buckets.
	 * @param array<int, string>                                               $ends    The end dates.
	 *
	 * @return array<string, array{weekLabel: string, week: string, count: int}> The counted buckets.
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function countedWeeks(array $buckets, array $ends): array {
		foreach ($ends as $end) {
			$key = $this->mondayOf(date: $end)->format('Y-m-d');
			if (isset($buckets[$key]) === true) {
				$buckets[$key]['count']++;
			}
		}

		return $buckets;
	}//end countedWeeks()

	/**
	 * The Monday of the week a date falls in.
	 *
	 * @param string $date A `Y-m-d` date.
	 *
	 * @return DateTimeImmutable Midnight on that Monday.
	 */
	private function mondayOf(string $date): DateTimeImmutable {
		$day = new DateTimeImmutable(substr($date, 0, 10));

		return $day->sub(interval: new DateInterval('P'.((int)$day->format('N') - 1).'D'));
	}//end mondayOf()
}//end class
