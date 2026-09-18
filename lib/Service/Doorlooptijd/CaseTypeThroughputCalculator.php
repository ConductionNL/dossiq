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
	 */
	public function __construct(
		private readonly ?WorkingClock $clock = null,
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
	 * @return array<int, array{id: string, title: string, avgDays: int, count: int}>
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
				$title = (string)$caseTypeIndex[$caseTypeId]['title'];
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
	 * Sum and count closed-case throughput days per case-type id.
	 *
	 * @param array<int, array<string, mixed>> $cases Enriched cases.
	 *
	 * @return array<string, array{sum: int, count: int}>
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
