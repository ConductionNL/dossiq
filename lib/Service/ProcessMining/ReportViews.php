<?php

/**
 * The display blocks of the process-mining report.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\ProcessMining;

use OCP\IL10N;

/**
 * Shapes the per-type report into the blocks a dashboard widget reads
 * through `responsePath`:
 *
 * - `summary`: records analysed, record types, the transition-weighted rework
 *   rate, the top bottleneck and, at 20% rework or more, the notice sentence;
 * - `dwell`: dwell per status of the focused type (the filtered one, else the
 *   busiest), on the headline clock;
 * - `throughput`: records closed per ISO week;
 * - `bottlenecks`: every type's bottlenecks, highest score first, top ten;
 * - `byAssignee`: dwell per holder of the focused type, unattributed time named;
 * - `labels`: the two duration column titles. The headline title says which
 *   clock the numbers are on, because the header is the only place that can
 *   say a number is an approximation.
 *
 * @spec openspec/specs/process-mining-bottlenecks/spec.md
 */
class ReportViews {

	/**
	 * The blocks a `view` may name.
	 *
	 * @var string[]
	 */
	public const VIEWS = ['summary', 'dwell', 'throughput', 'bottlenecks', 'byAssignee'];

	/**
	 * Rows the bottleneck ranking keeps.
	 *
	 * @var int
	 */
	public const BOTTLENECK_LIMIT = 10;

	/**
	 * The rework rate at or above which the summary carries its notice.
	 *
	 * @var float
	 */
	public const REWORK_NOTICE_PERCENT = 20.0;

	/**
	 * The rework notice, as the translation source text.
	 *
	 * @var string
	 */
	private const REWORK_NOTICE = '{percent}% of recorded transitions revisit a status the case had already left. '
		.'A high rework rate usually means guard conditions or handler routing need a closer look.';

	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n Words the labels in the reader's language.
	 */
	public function __construct(
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The display blocks of a report, all or the one a view names.
	 *
	 * @param array<string, mixed> $report The report from {@see \OCA\Dossiq\Service\ProcessMiningService::getReport()}.
	 * @param string|null          $view   One of {@see self::VIEWS}, or null for all.
	 *
	 * @return array<string, mixed> The blocks, always with `labels`.
	 *
	 * @spec openspec/specs/process-mining-bottlenecks/spec.md
	 */
	public function shape(array $report, ?string $view): array {
		$caseTypes = ($report['caseTypes'] ?? []);
		$focused   = $this->focusedType(caseTypes: $caseTypes, filter: ($report['caseTypeFilter'] ?? null));
		$blocks    = ['labels' => $this->labels(clock: (string)($report['clock'] ?? WorkingClock::CLOCK_WALL))];

		foreach (self::VIEWS as $name) {
			if ($view !== null && $view !== $name) {
				continue;
			}

			$blocks[$name] = match ($name) {
				'summary' => $this->summary(caseTypes: $caseTypes),
				'dwell' => $this->dwell(rows: ($focused['dwellTime'] ?? [])),
				'throughput' => array_values(($report['throughputTrend'] ?? [])),
				'bottlenecks' => $this->bottlenecks(caseTypes: $caseTypes, limit: self::BOTTLENECK_LIMIT),
				default => $this->byAssignee(rows: ($focused['dwellByAssignee'] ?? [])),
			};
		}

		return $blocks;
	}//end shape()

	/**
	 * The two duration column titles for a clock.
	 *
	 * @param string $clock One of the {@see WorkingClock} clock names.
	 *
	 * @return array{workingHours: string, wallHours: string}
	 *
	 * @spec openspec/specs/process-mining-bottlenecks/spec.md
	 */
	public function labels(string $clock): array {
		$headline = match ($clock) {
			WorkingClock::CLOCK_CALENDAR => $this->l10n->t('Working hours'),
			WorkingClock::CLOCK_DAYS_TIMES_EIGHT => $this->l10n->t('Working hours (working days x 8)'),
			default => $this->l10n->t('Hours, wall clock'),
		};

		return ['workingHours' => $headline, 'wallHours' => $this->l10n->t('Wall-clock hours')];
	}//end labels()

	/**
	 * The headline duration of one aggregated row: working hours when the row
	 * carries them, the wall clock otherwise. The column title says which.
	 *
	 * @param array<string, mixed> $row One dwell or bottleneck row.
	 *
	 * @return float|int The headline hours.
	 *
	 * @spec openspec/specs/process-mining-bottlenecks/spec.md
	 */
	public function headlineHours(array $row): float|int {
		$working = ($row['medianWorkingHours'] ?? null);
		if (is_int($working) === true || is_float($working) === true) {
			return $working;
		}

		$wall = ($row['medianHours'] ?? 0);
		if (is_int($wall) === true || is_float($wall) === true) {
			return $wall;
		}

		return 0;
	}//end headlineHours()

	/**
	 * The headline figures across every type.
	 *
	 * @param array<int, array<string, mixed>> $caseTypes The per-type reports.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/process-mining-bottlenecks/spec.md
	 */
	public function summary(array $caseTypes): array {
		$totalCases  = 0;
		$weighted    = 0.0;
		$transitions = 0;
		foreach ($caseTypes as $caseType) {
			$count        = (int)($caseType['transitionCount'] ?? 0);
			$totalCases  += (int)($caseType['caseVolume'] ?? 0);
			$weighted    += ((float)($caseType['reworkPercent'] ?? 0) * $count);
			$transitions += $count;
		}

		$rework = 0.0;
		if ($transitions > 0) {
			$rework = (round(($weighted / $transitions) * 10) / 10);
		}

		$top   = ($this->bottlenecks(caseTypes: $caseTypes, limit: 1)[0] ?? null);
		$label = '—';
		if ($top !== null) {
			$label = $top['statusName'].' ('.$top['medianHours'].'h)';
		}

		$notice = null;
		if ($rework >= self::REWORK_NOTICE_PERCENT) {
			$notice = str_replace(
				'{percent}',
				(string)$rework,
				$this->l10n->t(self::REWORK_NOTICE)
			);
		}

		return [
			'totalCases'           => $totalCases,
			'caseTypeCount'        => count($caseTypes),
			'overallReworkPercent' => $rework,
			'topBottleneck'        => $top,
			'topBottleneckLabel'   => $label,
			'reworkNotice'         => $notice,
		];
	}//end summary()

	/**
	 * Every type's bottlenecks in one ranking, highest score first.
	 *
	 * @param array<int, array<string, mixed>> $caseTypes The per-type reports.
	 * @param int                              $limit     Rows to keep.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/process-mining-bottlenecks/spec.md
	 */
	public function bottlenecks(array $caseTypes, int $limit): array {
		$rows = [];
		foreach ($caseTypes as $caseType) {
			foreach (($caseType['bottlenecks'] ?? []) as $bottleneck) {
				$rows[] = [
					'caseTypeTitle'      => (string)($caseType['title'] ?? ''),
					'statusName'         => (string)($bottleneck['statusName'] ?? ''),
					'medianHours'        => ($bottleneck['medianHours'] ?? 0),
					'medianWorkingHours' => $this->headlineHours(row: $bottleneck),
					'visitCount'         => (int)($bottleneck['visitCount'] ?? 0),
					'score'              => ($bottleneck['score'] ?? 0),
				];
			}
		}

		usort($rows, static fn (array $left, array $right): int => ($right['score'] <=> $left['score']));

		return array_slice($rows, 0, $limit);
	}//end bottlenecks()

	/**
	 * Dwell per status on the headline clock.
	 *
	 * @param array<int, array<string, mixed>> $rows The focused type's `dwellTime`.
	 *
	 * @return array<int, array{statusName: string, hours: float|int}>
	 *
	 * @spec openspec/specs/process-mining-bottlenecks/spec.md
	 */
	public function dwell(array $rows): array {
		$shaped = [];
		foreach ($rows as $row) {
			$shaped[] = ['statusName' => (string)($row['statusName'] ?? ''), 'hours' => $this->headlineHours(row: $row)];
		}

		return $shaped;
	}//end dwell()

	/**
	 * Dwell per holder; time no status record attributed keeps its row and is
	 * named, since dropping it would show less work than was done.
	 *
	 * @param array<int, array<string, mixed>> $rows The focused type's `dwellByAssignee`.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/process-mining-bottlenecks/spec.md
	 */
	public function byAssignee(array $rows): array {
		$shaped = [];
		foreach ($rows as $row) {
			$actor = trim((string)($row['actor'] ?? ''));
			$label = $actor;
			if ($actor === '') {
				$label = $this->l10n->t('Not recorded');
			}

			$shaped[] = [
				'actor'        => $actor,
				'actorLabel'   => $label,
				'workingHours' => $this->headlineHours(row: $row),
				'wallHours'    => ($row['medianHours'] ?? 0),
				'visitCount'   => (int)($row['visitCount'] ?? 0),
			];
		}

		return $shaped;
	}//end byAssignee()

	/**
	 * The type the per-type blocks describe: the filtered one, else the first
	 * (the report lists the busiest first).
	 *
	 * @param array<int, array<string, mixed>> $caseTypes The per-type reports.
	 * @param mixed                            $filter    The type filter of the report.
	 *
	 * @return array<string, mixed>|null The type report, or null.
	 */
	private function focusedType(array $caseTypes, mixed $filter): ?array {
		if (is_string($filter) === true && $filter !== '') {
			foreach ($caseTypes as $caseType) {
				if (($caseType['id'] ?? null) === $filter) {
					return $caseType;
				}
			}

			return null;
		}

		return ($caseTypes[0] ?? null);
	}//end focusedType()
}//end class
