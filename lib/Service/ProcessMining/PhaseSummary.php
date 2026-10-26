<?php

/**
 * Dossiq PhaseSummary.
 *
 * The two figures the process analysis page leads with, read off the per case
 * type reports the service already built: the days a case spends per phase,
 * as a matrix of case types by phases, and where cases stick, as one ranked
 * list across case types.
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
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\ProcessMining;

/**
 * Shapes the per case type dwell statistics into the page's matrix and list.
 *
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
 */
class PhaseSummary {

	/**
	 * Hours in a day, for turning dwell hours into days.
	 *
	 * @var float
	 */
	private const HOURS_PER_DAY = 24.0;

	/**
	 * The cell of a phase a case type never passes.
	 *
	 * @var string
	 */
	private const NONE = 'none';

	/**
	 * What the page reads: the days per phase (mean and median) and where cases stick.
	 *
	 * @param array<int, array<string, mixed>> $caseTypes The per case type reports.
	 *
	 * @return array{phaseDays: array{mean: array<string, mixed>, median: array<string, mixed>}, stuck: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
	 */
	public function forPage(array $caseTypes): array {
		return [
			'phaseDays' => [
				'mean' => $this->matrix(caseTypes: $caseTypes, measure: 'meanHours'),
				'median' => $this->matrix(caseTypes: $caseTypes, measure: 'medianHours'),
			],
			'stuck' => $this->stuck(caseTypes: $caseTypes),
		];
	}//end forPage()

	/**
	 * The days per phase per case type, as columns and rows a matrix reads.
	 *
	 * Columns are the named phases in the order they first appear, rows the
	 * case types that passed at least one of them; a case type that
	 * never passes a phase has the word `none` in that cell, which the page
	 * shows translated rather than as an empty cell.
	 *
	 * @param array<int, array<string, mixed>> $caseTypes The per case type reports.
	 * @param string                           $measure   `meanHours` or `medianHours`.
	 *
	 * @return array{columns: array<int, string>, rows: array<int, array{label: string, values: array<int, int|string>}>}
	 *
	 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
	 */
	public function matrix(array $caseTypes, string $measure='meanHours'): array {
		$columns = [];
		$rows = [];
		foreach ($caseTypes as $report) {
			$hours = [];
			foreach ($this->columnsOf(stats: $this->namedStats(report: $report)) as $column => $stats) {
				$hours[$column] = array_sum(array_map(static fn (array $stat): float => (float)($stat[$measure] ?? 0), $stats));
				if (in_array($column, $columns, true) === false) {
					$columns[] = $column;
				}
			}

			if ($hours !== []) {
				$byName = array_map(static fn (float $sum): int => (int)round($sum / self::HOURS_PER_DAY), $hours);
				$rows[] = ['label' => (string)($report['title'] ?? ''), 'byName' => $byName];
			}
		}

		return [
			'columns' => $columns,
			'rows' => array_map(
				static fn (array $row): array => [
					'label' => $row['label'],
					'values' => array_map(static fn (string $name): int|string => ($row['byName'][$name] ?? self::NONE), $columns),
				],
				$rows
			),
		];
	}//end matrix()

	/**
	 * A case type's statistics per matrix column. A status that names a
	 * phase goes under its phase (the days of a phase are the days of its
	 * statuses together), any other under its own name. When the case type
	 * uses phases, its final statuses are no column: the case has left the
	 * process there.
	 *
	 * @param array<int, array<string, mixed>> $stats The named dwell statistics.
	 *
	 * @return array<string, array<int, array<string, mixed>>> Column => statistics.
	 *
	 * @spec openspec/changes/status-type-phase/specs/process-mining/spec.md#requirement-days-per-phase-groups-statuses-by-their-phase
	 */
	private function columnsOf(array $stats): array {
		$phased = array_filter($stats, static fn (array $stat): bool => (string)($stat['statusPhase'] ?? '') !== '') !== [];

		$columns = [];
		foreach ($stats as $stat) {
			if ($phased === true && ($stat['statusFinal'] ?? false) === true) {
				continue;
			}

			$column = (string)($stat['statusPhase'] ?? '');
			if ($column === '') {
				$column = (string)$stat['statusName'];
			}

			$columns[$column][] = $stat;
		}

		return $columns;
	}//end columnsOf()

	/**
	 * The dwell statistics of a report whose status resolved to a name. A
	 * status the index could not name carries its id as its name, and an id
	 * is no phase a reader recognises, so it is left out.
	 *
	 * @param array<string, mixed> $report One case type's report.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
	 */
	private function namedStats(array $report): array {
		return array_values(
			array_filter(
				(array)($report['dwellTime'] ?? []),
				static function (array $stat): bool {
					$name = (string)($stat['statusName'] ?? '');
					return $name !== '' && $name !== (string)($stat['statusId'] ?? '');
				}
			)
		);
	}//end namedStats()

	/**
	 * Where cases stick: the highest ranked bottleneck of every case type,
	 * ranked across case types, at most `$limit` of them.
	 *
	 * The first is marked `bottleneck`, the rest `watch`.
	 *
	 * @param array<int, array<string, mixed>> $caseTypes The per case type reports.
	 * @param int                              $limit     How many to return.
	 *
	 * @return array<int, array{id: string, caseType: string, phase: string, days: int, visits: int, level: string}>
	 *
	 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
	 */
	public function stuck(array $caseTypes, int $limit=5): array {
		$candidates = [];
		foreach ($caseTypes as $report) {
			$top = ($report['bottlenecks'][0] ?? null);
			if (is_array($top) === false) {
				continue;
			}

			$candidates[] = [
				'id' => (string)($report['id'] ?? '') . ':' . (string)($top['statusId'] ?? ''),
				'caseType' => (string)($report['title'] ?? ''),
				'phase' => (string)($top['statusName'] ?? ''),
				'days' => (int)round(((float)($top['medianHours'] ?? 0)) / self::HOURS_PER_DAY),
				'visits' => (int)($top['visitCount'] ?? 0),
				'score' => (float)($top['score'] ?? 0),
			];
		}

		usort($candidates, static fn (array $left, array $right): int => ($right['score'] <=> $left['score']));

		$out = [];
		foreach (array_slice($candidates, 0, $limit) as $index => $row) {
			unset($row['score']);
			$row['level'] = 'watch';
			if ($index === 0) {
				$row['level'] = 'bottleneck';
			}

			$out[] = $row;
		}

		return $out;
	}//end stuck()
}//end class
