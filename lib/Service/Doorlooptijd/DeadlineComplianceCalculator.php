<?php

/**
 * Dossiq DeadlineComplianceCalculator.
 *
 * Every throughput-time number that compares a case's dates against its
 * deadline: the headline KPI bands (open / at-risk / overdue), the on-time
 * percentage over the last twelve months, the monthly on-time-vs-late
 * compliance series, and the per-case RAG status on the open-case list.
 *
 * Split out of DoorlooptijdService so that service keeps only the load +
 * orchestrate role. Keeping all four together is deliberate: the KPI bands
 * and the list's RAG status apply the *same* banding rule (overdue below
 * zero, at-risk within the threshold, on-time otherwise), and they must not
 * be allowed to drift apart.
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

/**
 * Computes the deadline-compliance metrics of the throughput-time dashboard.
 *
 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
 */
class DeadlineComplianceCalculator {
	/**
	 * The groupings a throughput summary takes.
	 *
	 * @var array<int, string>
	 */
	public const GRANULARITIES = ['week', 'month', 'quarter', 'year'];

	/**
	 * Compute the four headline KPIs.
	 *
	 * @param array<int, array<string, mixed>> $cases Enriched cases.
	 * @param int $atRiskDays Threshold for at-risk band.
	 *
	 * @return array{open: int, atRisk: int, overdue: int, onTimePercent: int}
	 *
	 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
	 */
	public function computeKpi(array $cases, int $atRiskDays): array {
		$bands = $this->countOpenBands(cases: $cases, atRiskDays: $atRiskDays);

		return [
			'open' => $bands['open'],
			'atRisk' => $bands['atRisk'],
			'overdue' => $bands['overdue'],
			'onTimePercent' => $this->computeOnTimePercent(cases: $cases),
		];
	}//end computeKpi()

	/**
	 * Count open cases split over the on-time / at-risk / overdue bands.
	 *
	 * @param array<int, array<string, mixed>> $cases Enriched cases.
	 * @param int $atRiskDays Threshold for at-risk band.
	 *
	 * @return array{open: int, atRisk: int, overdue: int}
	 *
	 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
	 */
	private function countOpenBands(array $cases, int $atRiskDays): array {
		$open = 0;
		$atRisk = 0;
		$overdue = 0;
		foreach ($cases as $caseData) {
			if ($caseData['_isOpen'] !== true) {
				continue;
			}

			$open++;
			$daysRemaining = $caseData['_daysRemaining'];
			if ($daysRemaining === null) {
				continue;
			}

			if ($daysRemaining < 0) {
				$overdue++;
			} elseif ($daysRemaining <= $atRiskDays) {
				$atRisk++;
			}
		}//end foreach

		return [
			'open' => $open,
			'atRisk' => $atRisk,
			'overdue' => $overdue,
		];
	}//end countOpenBands()

	/**
	 * Percentage of cases closed in the last 12 months that met their deadline.
	 *
	 * @param array<int, array<string, mixed>> $cases Enriched cases.
	 *
	 * @return int The on-time percentage; 100 when nothing closed in window.
	 *
	 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
	 */
	private function computeOnTimePercent(array $cases): int {
		// Closed cases in the last 12 months.
		$cutoff = (new DateTimeImmutable('-12 months'))->format('Y-m-d');
		$closedOnTime = 0;
		$closedLate = 0;
		foreach ($cases as $caseData) {
			if ($caseData['_isOpen'] === true) {
				continue;
			}

			if ($caseData['_endDate'] === null || $caseData['_endDate'] < $cutoff) {
				continue;
			}

			if ($caseData['_deadline'] === null) {
				continue;
			}

			if ($caseData['_endDate'] <= $caseData['_deadline']) {
				$closedOnTime++;
				continue;
			}

			$closedLate++;
		}//end foreach

		$totalClosed = ($closedOnTime + $closedLate);
		if ($totalClosed === 0) {
			return 100;
		}

		return (int)round(($closedOnTime / $totalClosed) * 100);
	}//end computeOnTimePercent()

	/**
	 * Monthly on-time / late counts over the requested period.
	 *
	 * @param array<int, array<string, mixed>> $cases Enriched cases.
	 * @param string $period Period spec (e.g. `12m`, `6m`, `3m`).
	 *
	 * @return array<int, array{month: string, onTime: int, late: int, percent: int}>
	 *
	 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
	 */
	public function computeMonthlyCompliance(array $cases, string $period): array {
		$months = $this->parseMonths(period: $period);
		$buckets = [];

		$endDate = new DateTimeImmutable('first day of this month');
		for ($i = ($months - 1); $i >= 0; $i--) {
			$month = $endDate->modify('-' . $i . ' month')->format('Y-m');
			$buckets[$month] = ['onTime' => 0, 'late' => 0];
		}

		foreach ($cases as $caseData) {
			if ($caseData['_endDate'] === null || $caseData['_deadline'] === null) {
				continue;
			}

			$month = substr($caseData['_endDate'], 0, 7);
			if (isset($buckets[$month]) === false) {
				continue;
			}

			if ($caseData['_endDate'] <= $caseData['_deadline']) {
				$buckets[$month]['onTime']++;
				continue;
			}

			$buckets[$month]['late']++;
		}//end foreach

		$out = [];
		foreach ($buckets as $month => $counts) {
			$total = ($counts['onTime'] + $counts['late']);
			$percent = 100;
			if ($total !== 0) {
				$percent = (int)round(($counts['onTime'] / $total) * 100);
			}

			$out[] = [
				'month' => $month,
				'onTime' => $counts['onTime'],
				'late' => $counts['late'],
				'percent' => $percent,
			];
		}

		return $out;
	}//end computeMonthlyCompliance()

	/**
	 * Build the sortable list of open cases with RAG status.
	 *
	 * @param array<int, array<string, mixed>> $cases Enriched cases.
	 * @param int $atRiskDays Threshold for at-risk band.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
	 */
	public function buildCaseList(array $cases, int $atRiskDays): array {
		$rows = [];
		foreach ($cases as $caseData) {
			if ($caseData['_isOpen'] !== true) {
				continue;
			}

			$daysRemaining = $caseData['_daysRemaining'];
			$ragStatus = 'on-time';
			if ($daysRemaining !== null) {
				if ($daysRemaining < 0) {
					$ragStatus = 'overdue';
				} elseif ($daysRemaining <= $atRiskDays) {
					$ragStatus = 'at-risk';
				}
			}

			$rows[] = [
				'id' => (string)($caseData['id'] ?? ''),
				'identifier' => (string)($caseData['identifier'] ?? ''),
				'title' => (string)($caseData['title'] ?? ''),
				'caseTypeTitle' => (string)($caseData['_caseTypeTitle'] ?? ''),
				'startDate' => $caseData['_startDate'],
				'deadline' => $caseData['_deadline'],
				'daysRemaining' => $daysRemaining,
				'ragStatus' => $ragStatus,
			];
		}//end foreach

		usort(
			$rows,
			static function (array $left, array $right): int {
				$leftValue = $left['daysRemaining'] ?? PHP_INT_MAX;
				$rightValue = $right['daysRemaining'] ?? PHP_INT_MAX;
				return ($leftValue <=> $rightValue);
			}
		);

		return $rows;
	}//end buildCaseList()

	/**
	 * The throughput summary over closed cases: the average days a case took
	 * in the last twelve months, how many closed this calendar year, and the
	 * average days per month over the requested period as parallel arrays.
	 *
	 * The averages over the period are grouped per `granularity`: per month
	 * (`YYYY-MM`, the default), per quarter (`YYYY-Qn`), per year (`YYYY`) or
	 * per ISO week (`YYYY-Www`), oldest first, today's last. They keep the key
	 * `monthlyAvgDays`, which the processing-time chart reads.
	 *
	 * @param array<int, array<string, mixed>> $cases       Enriched cases.
	 * @param string                           $period      Period spec (e.g. `12m`).
	 * @param DateTimeImmutable|null           $today       The day the figures are read on; now when absent.
	 * @param string                           $granularity `month`, `quarter`, `year` or `week`.
	 *
	 * @return array<string, mixed> `avgDays`, `closedThisYear`, `granularity` and `monthlyAvgDays` (`labels`, `values`).
	 *
	 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
	 */
	public function computeSummary(array $cases, string $period, ?DateTimeImmutable $today = null, string $granularity = 'month'): array {
		$today = ($today ?? new DateTimeImmutable());
		$cutoff = $today->modify('-12 months')->format('Y-m-d');
		$year = $today->format('Y');
		$months = $this->parseMonths(period: $period);
		if (in_array($granularity, self::GRANULARITIES, true) === false) {
			$granularity = 'month';
		}

		$grouping = new ThroughputBuckets();
		$buckets = $grouping->emptyBuckets(
			start: $today->modify('first day of this month')->modify('-' . ($months - 1) . ' month'),
			today: $today,
			granularity: $granularity
		);

		$recent = [];
		$closedThisYear = 0;
		foreach ($cases as $caseData) {
			$end = $caseData['_endDate'];
			$days = $caseData['_throughputDays'];
			if ($caseData['_isOpen'] === true || $end === null || $days === null) {
				continue;
			}

			if (str_starts_with($end, $year) === true) {
				$closedThisYear++;
			}

			if ($end >= $cutoff) {
				$recent[] = $days;
			}

			$bucket = $grouping->bucketOf(day: new DateTimeImmutable(substr($end, 0, 10)), granularity: $granularity);
			if (isset($buckets[$bucket]) === true) {
				$buckets[$bucket][] = $days;
			}
		}//end foreach

		$values = array_map(fn (array $list): ?int => $this->average(values: $list), array_values($buckets));

		return [
			'avgDays' => $this->average(values: $recent),
			'closedThisYear' => $closedThisYear,
			'granularity' => $granularity,
			'monthlyAvgDays' => ['labels' => array_map('strval', array_keys($buckets)), 'values' => $values],
		];
	}//end computeSummary()

	/**
	 * The rounded average of a list of day counts, or null for an empty list.
	 *
	 * @param array<int, int> $values Day counts.
	 *
	 * @return int|null
	 */
	private function average(array $values): ?int {
		if ($values === []) {
			return null;
		}

		return (int)round(array_sum($values) / count($values));
	}//end average()

	/**
	 * Translate a period string into a month count (`12m` → 12, `6m` → 6).
	 *
	 * @param string $period Period spec.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
	 */
	private function parseMonths(string $period): int {
		if (preg_match('/^(\d+)m$/', $period, $matches) === 1) {
			$months = (int)$matches[1];
			if ($months >= 1 && $months <= 36) {
				return $months;
			}
		}

		return 12;
	}//end parseMonths()
}//end class
