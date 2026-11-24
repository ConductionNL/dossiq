<?php

/**
 * How far a year's supervision plan has come, counted from its inspections.
 *
 * The plan stores what is planned per category and nothing else. Completed and
 * cancelled are read off the field inspections that name the category in
 * `planCategory`: an inspection with `completedAt` is completed, one with
 * `cancelledAt` is cancelled, any other is still to do. So an inspection
 * completed in the field counts at once, and no figure can drift from the
 * records it summarises.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Inspection
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
 * @spec openspec/changes/supervision-plan-page/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Inspection;

/**
 * Count a plan's inspections per category and per quarter.
 *
 * @spec openspec/changes/supervision-plan-page/tasks.md#task-1
 */
final class SupervisionPlanProgress {

	/**
	 * The figures of one plan.
	 *
	 * @param array<string, mixed>             $plan        The supervisionPlan record.
	 * @param list<array<string, mixed>>       $categories  Its supervisionPlanCategory rows.
	 * @param list<array<string, mixed>>       $inspections The field inspections naming one of those categories.
	 *
	 * @return array<string, mixed> Totals, the completion share, capacity and demand, per category and per quarter.
	 *
	 * @spec openspec/changes/supervision-plan-page/tasks.md#task-1
	 */
	public function measure(array $plan, array $categories, array $inspections): array {
		$byCategory = $this->groupByCategory(inspections: $inspections);
		$rows       = [];
		foreach ($this->ordered(categories: $categories) as $category) {
			$rows[] = $this->categoryRow(category: $category, inspections: ($byCategory[$this->idOf(record: $category)] ?? []));
		}

		$scheduled = array_sum(array_column($rows, 'planned'));
		$completed = array_sum(array_column($rows, 'completed'));
		$cancelled = array_sum(array_column($rows, 'cancelled'));

		return [
			'year'          => (int)($plan['year'] ?? 0),
			'scheduled'     => $scheduled,
			'completed'     => $completed,
			'cancelled'     => $cancelled,
			'remaining'     => max(0, $scheduled - $completed - $cancelled),
			'completion'    => self::percent(part: $completed, whole: $scheduled),
			'capacityHours' => (float)($plan['capacityHours'] ?? 0),
			'demandHours'   => array_sum(array_column($rows, 'demandHours')),
			'categories'    => $rows,
			'quarters'      => $this->quarters(inspections: $this->planned(byCategory: $byCategory, rows: $rows)),
		];
	}//end measure()

	/**
	 * The record's uuid, from its top level or its `@self` block.
	 *
	 * @param array<string, mixed> $record The record.
	 *
	 * @return string The uuid, '' when it has none.
	 *
	 * @spec openspec/changes/supervision-plan-page/tasks.md#task-1
	 */
	public function idOf(array $record): string {
		$self = $record['@self'] ?? [];
		if (is_array($self) === false) {
			$self = [];
		}

		return (string)($record['id'] ?? ($self['id'] ?? ($record['uuid'] ?? '')));
	}//end idOf()

	/**
	 * The inspections a category demands in a year: objects times visits, rounded up per risk profile.
	 *
	 * @param array<string, mixed> $category The supervisionPlanCategory row.
	 *
	 * @return int The demand; the planned count when the category names no risk frequency.
	 *
	 * @spec openspec/changes/supervision-plan-page/tasks.md#task-1
	 */
	public function demandOf(array $category): int {
		$rows = $category['riskFrequency'] ?? [];
		if (is_array($rows) === false || $rows === []) {
			return max(0, (int)($category['planned'] ?? 0));
		}

		$demand = 0;
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$demand += (int)ceil(max(0, (int)($row['objects'] ?? 0)) * max(0.0, (float)($row['visitsPerYear'] ?? 0)));
			}
		}

		return $demand;
	}//end demandOf()

	/**
	 * The inspections per category uuid; one without a category is left out.
	 *
	 * @param list<array<string, mixed>> $inspections The inspections.
	 *
	 * @return array<string, list<array<string, mixed>>> The inspections per category.
	 */
	private function groupByCategory(array $inspections): array {
		$grouped = [];
		foreach ($inspections as $inspection) {
			$category = (string)($inspection['planCategory'] ?? '');
			if ($category !== '') {
				$grouped[$category][] = $inspection;
			}
		}

		return $grouped;
	}//end groupByCategory()

	/**
	 * The categories in their declared order, then by name.
	 *
	 * @param list<array<string, mixed>> $categories The categories.
	 *
	 * @return list<array<string, mixed>> The same rows, ordered.
	 */
	private function ordered(array $categories): array {
		usort(
			$categories,
			static fn (array $left, array $right): int => [(int)($left['order'] ?? PHP_INT_MAX), (string)($left['name'] ?? '')]
				<=> [(int)($right['order'] ?? PHP_INT_MAX), (string)($right['name'] ?? '')]
		);

		return $categories;
	}//end ordered()

	/**
	 * One category's planned, completed, cancelled and remaining, with its demand.
	 *
	 * @param array<string, mixed>       $category    The category row.
	 * @param list<array<string, mixed>> $inspections Its inspections.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function categoryRow(array $category, array $inspections): array {
		$planned   = max(0, (int)($category['planned'] ?? 0));
		$completed = count(array_filter($inspections, static fn (array $row): bool => self::stateOf(inspection: $row) === 'completed'));
		$cancelled = count(array_filter($inspections, static fn (array $row): bool => self::stateOf(inspection: $row) === 'cancelled'));
		$demand    = $this->demandOf(category: $category);

		return [
			'id'          => $this->idOf(record: $category),
			'name'        => (string)($category['name'] ?? ''),
			'planned'     => $planned,
			'completed'   => $completed,
			'cancelled'   => $cancelled,
			'remaining'   => max(0, $planned - $completed - $cancelled),
			'completion'  => self::percent(part: $completed, whole: $planned),
			'label'       => $completed.' of '.$planned,
			'demand'      => $demand,
			'demandHours' => $demand * max(0.0, (float)($category['hoursPerInspection'] ?? 0)),
		];
	}//end categoryRow()

	/**
	 * The inspections of the plan's own categories, in one list.
	 *
	 * @param array<string, list<array<string, mixed>>> $byCategory The inspections per category.
	 * @param list<array<string, mixed>>                $rows       The category rows.
	 *
	 * @return list<array<string, mixed>> The inspections.
	 */
	private function planned(array $byCategory, array $rows): array {
		$inspections = [];
		foreach ($rows as $row) {
			$inspections = array_merge($inspections, ($byCategory[$row['id']] ?? []));
		}

		return $inspections;
	}//end planned()

	/**
	 * The four quarters: scheduled, completed, cancelled and remaining, with a forecast.
	 *
	 * An inspection falls in the quarter of its `scheduledAt`; one with no date is
	 * left out of the quarters but still counts in its category. The forecast is
	 * what the quarter will have completed if everything still open is done.
	 *
	 * @param list<array<string, mixed>> $inspections The inspections.
	 *
	 * @return list<array<string, int>> One row per quarter.
	 */
	private function quarters(array $inspections): array {
		$quarters = [];
		foreach ([1, 2, 3, 4] as $quarter) {
			$quarters[$quarter] = ['quarter' => $quarter, 'scheduled' => 0, 'completed' => 0, 'cancelled' => 0, 'remaining' => 0, 'forecast' => 0];
		}

		foreach ($inspections as $inspection) {
			$quarter = self::quarterOf(date: (string)($inspection['scheduledAt'] ?? ''));
			if ($quarter === null) {
				continue;
			}

			$state = self::stateOf(inspection: $inspection);
			$quarters[$quarter]['scheduled']++;
			$quarters[$quarter][$state]++;
		}

		foreach ($quarters as $quarter => $row) {
			$quarters[$quarter]['forecast'] = $row['completed'] + $row['remaining'];
		}

		return array_values($quarters);
	}//end quarters()

	/**
	 * Completed, cancelled or still to do.
	 *
	 * @param array<string, mixed> $inspection The inspection.
	 *
	 * @return string `completed`, `cancelled` or `remaining`.
	 */
	private static function stateOf(array $inspection): string {
		if (trim((string)($inspection['cancelledAt'] ?? '')) !== '') {
			return 'cancelled';
		}

		if (trim((string)($inspection['completedAt'] ?? '')) !== '') {
			return 'completed';
		}

		return 'remaining';
	}//end stateOf()

	/**
	 * The quarter (1 to 4) of an ISO date, null when it is not one.
	 *
	 * @param string $date The date.
	 *
	 * @return int|null The quarter.
	 */
	private static function quarterOf(string $date): ?int {
		if (preg_match('/^\d{4}-(\d{2})/', $date, $match) !== 1) {
			return null;
		}

		$month = (int)$match[1];
		if ($month < 1 || $month > 12) {
			return null;
		}

		return intdiv($month - 1, 3) + 1;
	}//end quarterOf()

	/**
	 * A whole percentage, 0 when there is nothing to divide by.
	 *
	 * @param int $part  The part.
	 * @param int $whole The whole.
	 *
	 * @return int The percentage.
	 */
	private static function percent(int $part, int $whole): int {
		if ($whole <= 0) {
			return 0;
		}

		return (int)round(100 * $part / $whole);
	}//end percent()
}//end class
