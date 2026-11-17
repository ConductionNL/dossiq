<?php

/**
 * Dwell time as a heatmap: statuses against weeks, each cell the median.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\ProcessMining
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
 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/process-mining-bottlenecks/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\ProcessMining;

use DateTimeImmutable;
use Exception;

/**
 * Lays the dwell intervals out as a status-by-week grid of medians
 * (REQ-CRS-36, row 10.4).
 *
 * A visit counts in the ISO week it was ENTERED, the same moment the report
 * window admits it on, so a cell and the dwell table beside it count the same
 * visits. The hours are the headline clock: working hours when the interval
 * carries them, the wall clock otherwise, as {@see ReportViews::headlineHours()}
 * reads the aggregated rows. A week a status had no visit in has no value
 * (null), not 0: zero hours is a measurement, an empty cell is not.
 *
 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/process-mining-bottlenecks/spec.md
 */
final class DwellHeatmap {
	/**
	 * The grid: week columns in time order, a row per status in the order the
	 * dwell stats list them, each value the median rounded to one decimal.
	 *
	 * @param array<int, array<string, mixed>> $intervals  The dwell intervals, each with
	 *                                                     `statusId`, `enteredAt` and hours.
	 * @param array<int, array<string, mixed>> $dwellStats The per-status stats, for the
	 *                                                     row order and the status names.
	 *
	 * @return array{columns: array<int, string>, rows: array<int, array{statusId: string, label: string, values: array<int, float|null>}>}
	 *
	 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/process-mining-bottlenecks/spec.md
	 */
	public function grid(array $intervals, array $dwellStats): array {
		$hoursByStatusWeek = [];
		$weeks = [];
		foreach ($intervals as $interval) {
			$week = $this->weekOf(raw: ($interval['enteredAt'] ?? null));
			$statusId = (string)($interval['statusId'] ?? '');
			$hours = $this->hoursOf(interval: $interval);
			if ($week === null || $statusId === '' || $hours === null) {
				continue;
			}

			$weeks[$week] = true;
			$hoursByStatusWeek[$statusId][$week][] = $hours;
		}

		$columns = array_keys($weeks);
		sort($columns, SORT_STRING);

		$rows = [];
		foreach ($this->statusOrder(dwellStats: $dwellStats, seen: array_keys($hoursByStatusWeek)) as $statusId => $label) {
			$values = [];
			foreach ($columns as $week) {
				$values[] = $this->median(values: ($hoursByStatusWeek[$statusId][$week] ?? []));
			}

			$rows[] = ['statusId' => $statusId, 'label' => $label, 'values' => $values];
		}

		return ['columns' => $columns, 'rows' => $rows];
	}//end grid()

	/**
	 * The ISO week of a moment, as `2026-W12`, or null when it does not parse.
	 *
	 * @param mixed $raw The moment.
	 *
	 * @return string|null The week.
	 */
	private function weekOf(mixed $raw): ?string {
		if (is_string($raw) === false || $raw === '') {
			return null;
		}

		try {
			return (new DateTimeImmutable($raw))->format('o-\WW');
		} catch (Exception) {
			return null;
		}
	}//end weekOf()

	/**
	 * The headline hours of one interval, or null when it carries none.
	 *
	 * @param array<string, mixed> $interval The interval.
	 *
	 * @return float|null The hours.
	 */
	private function hoursOf(array $interval): ?float {
		foreach (['workingHours', 'hours'] as $key) {
			$value = ($interval[$key] ?? null);
			if (is_int($value) === true || is_float($value) === true) {
				return (float)$value;
			}
		}

		return null;
	}//end hoursOf()

	/**
	 * The statuses in the order the dwell stats give them, named as they name
	 * them; a status only the intervals know follows, under its id.
	 *
	 * @param array<int, array<string, mixed>> $dwellStats The per-status stats.
	 * @param array<int, int|string>           $seen       The status ids the intervals hold.
	 *
	 * @return array<string, string> Name by status id.
	 */
	private function statusOrder(array $dwellStats, array $seen): array {
		$order = [];
		foreach ($dwellStats as $row) {
			$statusId = (string)($row['statusId'] ?? '');
			if ($statusId !== '' && in_array($statusId, array_map('strval', $seen), true) === true) {
				$order[$statusId] = (string)($row['statusName'] ?? $statusId);
			}
		}

		foreach ($seen as $statusId) {
			$order[(string)$statusId] ??= (string)$statusId;
		}

		return $order;
	}//end statusOrder()

	/**
	 * The median of a list, rounded to one decimal, or null for an empty one.
	 *
	 * @param array<int, float> $values The values.
	 *
	 * @return float|null The median.
	 */
	private function median(array $values): ?float {
		$count = count($values);
		if ($count === 0) {
			return null;
		}

		sort($values);
		$middle = intdiv($count, 2);
		$median = $values[$middle];
		if ($count % 2 === 0) {
			$median = (($values[$middle - 1] + $values[$middle]) / 2);
		}

		return round($median, 1);
	}//end median()
}//end class
