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
	 * Columns are the phases in the order they first appear; a case type that
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
		foreach ($caseTypes as $report) {
			foreach (($report['dwellTime'] ?? []) as $stat) {
				$name = (string)($stat['statusName'] ?? '');
				if ($name !== '' && in_array($name, $columns, true) === false) {
					$columns[] = $name;
				}
			}
		}

		$rows = [];
		foreach ($caseTypes as $report) {
			$byName = [];
			foreach (($report['dwellTime'] ?? []) as $stat) {
				$byName[(string)($stat['statusName'] ?? '')] = (int)round(((float)($stat[$measure] ?? 0)) / self::HOURS_PER_DAY);
			}

			$rows[] = [
				'label' => (string)($report['title'] ?? ''),
				'values' => array_map(static fn (string $name): int|string => ($byName[$name] ?? self::NONE), $columns),
			];
		}

		return ['columns' => $columns, 'rows' => $rows];
	}//end matrix()

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
