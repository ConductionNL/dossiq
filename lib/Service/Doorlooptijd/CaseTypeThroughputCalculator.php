<?php

/**
 * Dossiq CaseTypeThroughputCalculator.
 *
 * The case-type breakdown of the throughput-time dashboard: the average
 * realised throughput (in days) of *closed* cases, grouped per caseType and
 * ranked slowest-first, so a coordinator can see which case type is dragging
 * the overall doorlooptijd.
 *
 * Split out of DoorlooptijdService so that service keeps only the load +
 * orchestrate role. Unlike the other metric family this one never looks at a
 * deadline — it measures elapsed time only — which is exactly why it is its
 * own calculator rather than part of
 * {@see DeadlineComplianceCalculator}.
 *
 * Pure computation over already-enriched cases ({@see CaseEnricher}) —
 * nothing is read from OpenRegister.
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
use OCA\Dossiq\Service\ProcessMining\WorkingClock;
use OCA\Dossiq\Service\Support\LanguageMapText;

/**
 * Computes average closed-case throughput per case-type.
 *
 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
 */
class CaseTypeThroughputCalculator {
	/**
	 * Constructor.
	 *
	 * @param WorkingClock|null $clock The clock the working-hours column is on.
	 *                                 Optional so every existing caller builds
	 *                                 this calculator unchanged; without it the
	 *                                 working column is absent rather than
	 *                                 quietly equal to the calendar-day one.
	 * @param LanguageMapText $text A translatable title or name as one string.
	 */
	public function __construct(
		private readonly ?WorkingClock $clock = null,
		private readonly LanguageMapText $text = new LanguageMapText(),
	) {

	}//end __construct()

	/**
	 * Which clock the working-hours column is on.
	 *
	 * @return string One of {@see WorkingClock}'s CLOCK_* constants.
	 *
	 * @spec openspec/changes/dwell-time-on-the-working-calendar/specs/doorlooptijd-dashboard/spec.md
	 */
	public function clock(): string {
		if ($this->clock === null) {
			return WorkingClock::CLOCK_WALL;
		}

		return $this->clock->clock();
	}//end clock()

	/**
	 * Average closed-case throughput by case-type.
	 *
	 * @param array<int, array<string, mixed>> $cases Enriched cases.
	 * @param array<int, array<string, mixed>> $caseTypes Indexed case-type metadata.
	 *
	 * @return array<int, array{id: string, title: string, avgDays: int, avgWorkingHours: float, count: int}>
	 *
	 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
	 */
	public function computeCaseTypeBreakdown(array $cases, array $caseTypes): array {
		$accum = $this->accumulateThroughputByCaseType(cases: $cases);

		$caseTypeIndex = [];
		foreach ($caseTypes as $caseType) {
			$id = (string)($caseType['id'] ?? '');
			if ($id !== '') {
				$caseTypeIndex[$id] = $caseType;
			}
		}

		$out = [];
		foreach ($accum as $caseTypeId => $stats) {
			$title = $caseTypeId;
			if (isset($caseTypeIndex[$caseTypeId]['title']) === true) {
				$title = $this->text->textOf(value: $caseTypeIndex[$caseTypeId]['title']);
			}

			$out[] = [
				'id' => $caseTypeId,
				'title' => $title,
				// The calendar days a case took, which is what the applicant
				// waited. Kept under its old name so every chart already
				// drawn from it keeps meaning what it meant.
				'avgDays' => (int)round($stats['sum'] / $stats['count']),
				// The working hours the organisation spent on the same cases.
				// The two are reported side by side because they answer
				// different questions and neither replaces the other.
				'avgWorkingHours' => round(($stats['workingHours'] / $stats['count']), 1),
				'count' => $stats['count'],
			];
		}

		usort(
			$out,
			static fn (array $left, array $right): int => ($right['avgDays'] <=> $left['avgDays'])
		);

		return $out;
	}//end computeCaseTypeBreakdown()

	/**
	 * The share of closed cases per case type that ended on or before their
	 * deadline, as parallel arrays a chart reads directly, for the case types
	 * that closed the most cases. A case without a deadline has no norm to
	 * meet and is left out.
	 *
	 * @param array<int, array<string, mixed>> $cases     Enriched cases.
	 * @param array<int, array<string, mixed>> $caseTypes Case types.
	 * @param int                              $limit     How many case types, busiest first.
	 *
	 * @return array{labels: array<int, string>, shares: array<int, int>}
	 *
	 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
	 */
	public function computeWithinNormByCaseType(array $cases, array $caseTypes, int $limit=5): array {
		$titles = $this->titlesById(caseTypes: $caseTypes);

		$closed = array_filter(
			$cases,
			static fn (array $caseData): bool => $caseData['_isOpen'] !== true && $caseData['_endDate'] !== null && $caseData['_deadline'] !== null
		);

		$tally = [];
		foreach ($closed as $caseData) {
			$caseTypeId = (string)($caseData['caseType'] ?? '');
			if ($caseTypeId === '') {
				continue;
			}

			$tally[$caseTypeId] ??= ['met' => 0, 'total' => 0];
			$tally[$caseTypeId]['total']++;
			if ($caseData['_endDate'] <= $caseData['_deadline']) {
				$tally[$caseTypeId]['met']++;
			}
		}

		uasort($tally, static fn (array $left, array $right): int => ($right['total'] <=> $left['total']));

		$labels = [];
		$shares = [];
		foreach (array_slice($tally, 0, $limit, true) as $caseTypeId => $counts) {
			$labels[] = ($titles[$caseTypeId] ?? $caseTypeId);
			$shares[] = (int)round(($counts['met'] / $counts['total']) * 100);
		}

		return ['labels' => $labels, 'shares' => $shares];
	}//end computeWithinNormByCaseType()

	/**
	 * Per case type and per month of the period: how many cases closed, the
	 * rounded average days they took, and the share of them closed within
	 * their deadline. A month in which a case type closed nothing is left out;
	 * a case without a deadline counts for the average and not for the share.
	 *
	 * @param array<int, array<string, mixed>> $cases     Enriched cases.
	 * @param array<int, array<string, mixed>> $caseTypes Case types.
	 * @param int                              $months    How many months back from this one.
	 * @param DateTimeImmutable|null           $today     The day the figures are read on; now when absent.
	 *
	 * @return list<array{caseType: string, month: string, closed: int, avgDays: int|null, withinNormPercent: int|null}>
	 *
	 * @spec openspec/specs/doorlooptijd-csv/spec.md#requirement-the-processing-time-figures-download-as-csv
	 */
	public function computeMonthlyByCaseType(array $cases, array $caseTypes, int $months, ?DateTimeImmutable $today=null): array {
		$titles = $this->titlesById(caseTypes: $caseTypes);
		$first = ($today ?? new DateTimeImmutable())->modify('first day of this month');
		$inPeriod = [];
		for ($i = ($months - 1); $i >= 0; $i--) {
			$inPeriod[$first->modify('-' . $i . ' month')->format('Y-m')] = true;
		}

		$rows = [];
		foreach ($this->bucketByCaseTypeAndMonth(cases: $cases, inPeriod: $inPeriod) as $caseTypeId => $byMonth) {
			ksort($byMonth);
			foreach ($byMonth as $month => $bucket) {
				$rows[] = [
					'caseType' => ($titles[$caseTypeId] ?? (string)$caseTypeId),
					'month' => (string)$month,
					'closed' => count($bucket['days']),
					'avgDays' => (int)round(array_sum($bucket['days']) / count($bucket['days'])),
					'withinNormPercent' => $this->share(met: $bucket['met'], judged: $bucket['judged']),
				];
			}
		}

		usort($rows, static fn (array $left, array $right): int => [$left['caseType'], $left['month']] <=> [$right['caseType'], $right['month']]);

		return $rows;
	}//end computeMonthlyByCaseType()

	/**
	 * The closed cases inside the period, grouped by case type and month: the
	 * days each took, and how many of those with a deadline met it.
	 *
	 * @param array<int, array<string, mixed>> $cases    Enriched cases.
	 * @param array<string, bool>              $inPeriod The months of the period, `Y-m` as key.
	 *
	 * @return array<string, array<string, array{days: list<int>, met: int, judged: int}>>
	 */
	private function bucketByCaseTypeAndMonth(array $cases, array $inPeriod): array {
		$buckets = [];
		foreach ($cases as $caseData) {
			$end = ($caseData['_endDate'] ?? null);
			$caseTypeId = (string)($caseData['caseType'] ?? '');
			if (($caseData['_isOpen'] ?? false) === true || $end === null || ($caseData['_throughputDays'] ?? null) === null || $caseTypeId === '') {
				continue;
			}

			$month = substr((string)$end, 0, 7);
			if (isset($inPeriod[$month]) === false) {
				continue;
			}

			$bucket = ($buckets[$caseTypeId][$month] ?? ['days' => [], 'met' => 0, 'judged' => 0]);
			$bucket['days'][] = (int)$caseData['_throughputDays'];
			$deadline = ($caseData['_deadline'] ?? null);
			if ($deadline !== null) {
				$bucket['judged']++;
				$bucket['met'] += (int)($end <= $deadline);
			}

			$buckets[$caseTypeId][$month] = $bucket;
		}//end foreach

		return $buckets;
	}//end bucketByCaseTypeAndMonth()

	/**
	 * The rounded percentage met of judged, or null when nothing was judged.
	 *
	 * @param int $met    How many met their deadline.
	 * @param int $judged How many had a deadline.
	 *
	 * @return int|null
	 */
	private function share(int $met, int $judged): ?int {
		if ($judged === 0) {
			return null;
		}

		return (int)round(($met / $judged) * 100);
	}//end share()

	/**
	 * The title of every case type, by its id.
	 *
	 * @param array<int, array<string, mixed>> $caseTypes Case types.
	 *
	 * @return array<string, string>
	 */
	private function titlesById(array $caseTypes): array {
		$titles = [];
		foreach ($caseTypes as $caseType) {
			$id = (string)($caseType['id'] ?? '');
			if ($id !== '') {
				$titles[$id] = $this->text->textOf(value: ($caseType['title'] ?? $id));
			}
		}

		return $titles;
	}//end titlesById()

	/**
	 * Sum and count closed-case throughput days per case-type id.
	 *
	 * @param array<int, array<string, mixed>> $cases Enriched cases.
	 *
	 * @return array<string, array{sum: int, count: int, workingHours: float}>
	 *
	 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
	 */
	private function accumulateThroughputByCaseType(array $cases): array {
		$accum = [];
		foreach ($cases as $caseData) {
			if ($caseData['_isOpen'] === true || $caseData['_throughputDays'] === null) {
				continue;
			}

			$caseTypeId = (string)($caseData['caseType'] ?? '');
			if ($caseTypeId === '') {
				continue;
			}

			if (isset($accum[$caseTypeId]) === false) {
				$accum[$caseTypeId] = ['sum' => 0, 'count' => 0, 'workingHours' => 0.0];
			}

			$accum[$caseTypeId]['sum'] += $caseData['_throughputDays'];
			$accum[$caseTypeId]['workingHours'] += $this->workingHoursFor(caseData: $caseData);
			$accum[$caseTypeId]['count']++;
		}//end foreach

		return $accum;
	}//end accumulateThroughputByCaseType()

	/**
	 * One closed case's elapsed working hours, start to end.
	 *
	 * The dates are the normalised ones the enricher already wrote, so this
	 * never reparses a raw field and cannot disagree with `_throughputDays`
	 * about which day a case started.
	 *
	 * A case whose dates will not parse contributes zero working hours and
	 * still contributes its days, which is the shape the existing average
	 * already has: `_throughputDays` is null for those and they are skipped
	 * before this is reached.
	 *
	 * @param array<string, mixed> $caseData One enriched case.
	 *
	 * @return float The working hours.
	 *
	 * @spec openspec/changes/dwell-time-on-the-working-calendar/specs/doorlooptijd-dashboard/spec.md
	 */
	private function workingHoursFor(array $caseData): float {
		$start = ($caseData['_startDate'] ?? null);
		$end = ($caseData['_endDate'] ?? null);
		if ($this->clock === null || is_string($start) === false || is_string($end) === false) {
			// Without a clock the column is the calendar-day figure converted
			// at the working day this app has always assumed, and `clock()`
			// says so. Nothing here claims a calendar it did not read.
			return ((float)$caseData['_throughputDays'] * 8.0);
		}

		try {
			$measured = $this->clock->hoursBetween(
				from: new DateTimeImmutable($start),
				to: new DateTimeImmutable($end)
			);
		} catch (\Exception $e) {
			return 0.0;
		}

		return $measured['workingHours'];
	}//end workingHoursFor()
}//end class
