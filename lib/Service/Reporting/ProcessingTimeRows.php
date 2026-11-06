<?php

/**
 * Processing-time table rows: open records at risk and the per-type breakdown.
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

namespace OCA\Dossiq\Service\Reporting;

use DateTimeImmutable;
use OCA\Dossiq\Service\Term\DayCount;

/**
 * Pure arithmetic over the prepared records and types described on
 * {@see ProcessingTimeCalculator}, for the two tables of the dashboard.
 *
 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
 */
class ProcessingTimeRows {

	/**
	 * Days left at or below which an open record reads as critical.
	 *
	 * @var int
	 */
	public const CRITICAL_DAYS = 7;

	/**
	 * Days left at or below which an open record reads as at risk, whatever
	 * share of its target that is.
	 *
	 * @var int
	 */
	public const WARNING_DAYS = 14;

	/**
	 * The share of the target used beyond which a record counts as displayed
	 * at its cap, so one far-overdue record does not stretch a progress bar.
	 *
	 * @var float
	 */
	public const PERCENT_USED_CAP = 1.5;


	/**
	 * Constructor.
	 *
	 * @param ProcessingTimeCalculator $calculator Processing days and percentages.
	 * @param DayCount                 $days       Whole-day arithmetic.
	 */
	public function __construct(
		private readonly ProcessingTimeCalculator $calculator = new ProcessingTimeCalculator(),
		private readonly DayCount $days = new DayCount(),
	) {
	}//end __construct()

	/**
	 * Open records that used at least `1 - $threshold` of their target, or ran
	 * past it, most urgent first.
	 *
	 * `severity` is `overdue` past the target, `critical` within
	 * {@see self::CRITICAL_DAYS} of it, `warning` within
	 * {@see self::WARNING_DAYS} or inside the threshold, and `ok` otherwise
	 * (only reachable with a threshold of 1).
	 *
	 * @param array<int, array<string, mixed>>    $open      Open records.
	 * @param array<string, array<string, mixed>> $types     Prepared types keyed by id.
	 * @param float                               $threshold Remaining share at or below which a record is listed.
	 * @param DateTimeImmutable                   $today     The reference day.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
	 */
	public function atRisk(array $open, array $types, float $threshold, DateTimeImmutable $today): array {
		// Counted between calendar dates in one zone, so a reference day at
		// the tenant's midnight does not lose a day against a UTC start date.
		$today = new DateTimeImmutable($today->format('Y-m-d'));
		$rows  = [];
		foreach ($open as $record) {
			$typeId = (string)($record['caseType'] ?? '');
			$target = ($types[$typeId]['targetDays'] ?? null);
			$start  = ($record['startDate'] ?? null);
			if ($target === null || is_string($start) === false) {
				continue;
			}

			$elapsed     = $this->days->signed(from: new DateTimeImmutable($start), to: $today);
			$term        = $this->runningTerm(record: $record, start: $start, target: (int)$target);
			$remaining   = ($term - $elapsed);
			$percentUsed = ($elapsed / max(1, $term));
			if ($remaining >= 0 && (1 - $percentUsed) > $threshold) {
				continue;
			}

			$shownUsed = min($percentUsed, self::PERCENT_USED_CAP);
			$rows[]    = [
				'id'                 => (string)($record['id'] ?? ''),
				'title'              => (string)($record['title'] ?? ''),
				'identifier'         => (string)($record['identifier'] ?? ''),
				'caseType'           => $typeId,
				'caseTypeName'       => (string)($types[$typeId]['name'] ?? ''),
				'deadline'           => ($record['deadline'] ?? null),
				'targetDays'         => $target,
				'elapsedDays'        => $elapsed,
				'remainingDays'      => $remaining,
				'percentUsed'        => $shownUsed,
				'percentUsedDisplay' => (int)round($shownUsed * 100),
				'isOverdue'          => ($remaining < 0),
				'severity'           => $this->severity(remaining: $remaining, percentUsed: $percentUsed, threshold: $threshold),
			];
		}//end foreach

		usort(
			$rows,
			static fn (array $left, array $right): int => ($left['remainingDays'] <=> $right['remainingDays'])
		);

		return $rows;
	}//end atRisk()

	/**
	 * Per-type performance rows, including types without completed records.
	 *
	 * `status` is `good` at 90% or more within target, `warning` at 70% or
	 * more, `critical` below, and `no-target` without a target or records.
	 *
	 * @param array<int, array<string, mixed>>    $completed Completed records.
	 * @param array<string, array<string, mixed>> $types     Prepared types keyed by id.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
	 */
	public function performance(array $completed, array $types): array {
		$tallies = $this->familyTallies(tallies: $this->tally(completed: $completed, types: $types), types: $types);

		$rows = [];
		foreach ($tallies as $typeId => $tally) {
			$type   = $types[$typeId];
			$target = $type['targetDays'];
			$avg    = null;
			$rate   = null;
			if ($tally['total'] > 0) {
				$avg = (int)round($tally['totalDays'] / $tally['total']);
			}

			if ($target !== null) {
				$rate = $this->calculator->percent(part: $tally['withinSla'], whole: $tally['total']);
			}

			$rows[] = [
				'id'             => (string)$typeId,
				'name'           => (string)$type['name'],
				'targetDays'     => $target,
				'avgActualDays'  => $avg,
				'complianceRate' => $rate,
				'total'          => $tally['total'],
				'withinSla'      => $tally['withinSla'],
				'status'         => $this->performanceStatus(rate: $rate),
			];
		}//end foreach

		return $rows;
	}//end performance()

	/**
	 * The tallies of every version of one case type added together, under the
	 * version that holds the most completed records (its name and target are
	 * the row's). A type without a family is its own.
	 *
	 * @param array<string, array{total: int, totalDays: int, withinSla: int}> $tallies One tally per type id.
	 * @param array<string, array<string, mixed>>                              $types   Prepared types keyed by id.
	 *
	 * @return array<string, array{total: int, totalDays: int, withinSla: int}> One tally per family, keyed by its leading type id.
	 */
	private function familyTallies(array $tallies, array $types): array {
		$lead = [];
		foreach ($tallies as $typeId => $tally) {
			$family = (string)($types[$typeId]['family'] ?? $typeId);
			$current = ($lead[$family] ?? null);
			if ($current === null || $tally['total'] > $tallies[$current]['total']) {
				$lead[$family] = (string)$typeId;
			}
		}

		$out = [];
		foreach ($tallies as $typeId => $tally) {
			$key = $lead[(string)($types[$typeId]['family'] ?? $typeId)];
			$out[$key] ??= ['total' => 0, 'totalDays' => 0, 'withinSla' => 0];
			$out[$key]['total']     += $tally['total'];
			$out[$key]['totalDays'] += $tally['totalDays'];
			$out[$key]['withinSla'] += $tally['withinSla'];
		}

		return $out;
	}//end familyTallies()

	/**
	 * Completed-record counts, day totals and within-target counts per type.
	 *
	 * @param array<int, array<string, mixed>>    $completed Completed records.
	 * @param array<string, array<string, mixed>> $types     Prepared types keyed by id.
	 *
	 * @return array<string, array{total: int, totalDays: int, withinSla: int}> One tally per type.
	 */
	private function tally(array $completed, array $types): array {
		$tallies = array_fill_keys(array_keys($types), ['total' => 0, 'totalDays' => 0, 'withinSla' => 0]);

		foreach ($completed as $record) {
			$typeId = (string)($record['caseType'] ?? '');
			$actual = $this->calculator->processingDays(record: $record);
			if (isset($tallies[$typeId]) === false || $actual === null) {
				continue;
			}

			$target = $types[$typeId]['targetDays'];
			$tallies[$typeId]['total']++;
			$tallies[$typeId]['totalDays'] += $actual;
			$tallies[$typeId]['withinSla']  += (int)($target !== null && $actual <= $target);
		}

		return $tallies;
	}//end tally()

	/**
	 * The days of the term the record runs on: from its start to its own
	 * deadline when it has one, else its type's target.
	 *
	 * A case's deadline follows its running statutory term (an extension, a
	 * suspension, a Woo request's own four weeks); the type's target is the
	 * norm the type was given. Measuring a case against the target alone put a
	 * Woo request four days from its deadline in the "on track" half (r33).
	 *
	 * @param array<string, mixed> $record The open record.
	 * @param string               $start  Its start date.
	 * @param int                  $target Its type's target days.
	 *
	 * @return int The term in days.
	 *
	 * @spec openspec/specs/at-risk-reads-the-running-term/spec.md#requirement-at-risk-reads-the-cases-running-term
	 */
	private function runningTerm(array $record, string $start, int $target): int {
		$deadline = ($record['deadline'] ?? null);
		if (is_string($deadline) === false || $deadline === '') {
			return $target;
		}

		$term = $this->days->signed(from: new DateTimeImmutable($start), to: new DateTimeImmutable($deadline));
		if ($term <= 0) {
			return $target;
		}

		return $term;
	}//end runningTerm()

	/**
	 * The severity band of one open record.
	 *
	 * @param int   $remaining   Days left until the target.
	 * @param float $percentUsed Share of the target used.
	 * @param float $threshold   Remaining share at or below which a record is at risk.
	 *
	 * @return string One of overdue, critical, warning, ok.
	 */
	private function severity(int $remaining, float $percentUsed, float $threshold): string {
		if ($remaining < 0) {
			return 'overdue';
		}

		if ($remaining <= self::CRITICAL_DAYS) {
			return 'critical';
		}

		if ($remaining <= self::WARNING_DAYS || (1 - $percentUsed) <= min($threshold, 0.25)) {
			return 'warning';
		}

		return 'ok';
	}//end severity()

	/**
	 * The performance band of a compliance rate.
	 *
	 * @param int|null $rate The rate in percent, or null.
	 *
	 * @return string One of good, warning, critical, no-target.
	 */
	private function performanceStatus(?int $rate): string {
		if ($rate === null) {
			return 'no-target';
		}

		if ($rate >= 90) {
			return 'good';
		}

		if ($rate >= 70) {
			return 'warning';
		}

		return 'critical';
	}//end performanceStatus()
}//end class
