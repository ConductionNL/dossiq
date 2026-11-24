<?php

/**
 * Dossiq closed case measures.
 *
 * Two dashboard figures over the cases closed in a period: their average
 * duration in days and the share closed by their deadline.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Dashboard
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
 * @spec openspec/specs/dashboard/spec.md#REQ-DASH-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Dashboard;

use OCA\Dossiq\Service\Term\DayCount;

/**
 * Folds closed case rows into an average duration and a deadline compliance.
 *
 * @spec openspec/specs/dashboard/spec.md#REQ-DASH-001
 */
final class ClosedCaseMeasures {

	/**
	 * Constructor.
	 *
	 * @param DayCount $days The one place days between two dates are counted.
	 */
	public function __construct(
		private readonly DayCount $days,
	) {
	}//end __construct()

	/**
	 * Mean days from start to end across the closed cases.
	 *
	 * @param array<int, array> $closed The cases closed this month.
	 *
	 * @return float|null The average, or null when nothing closed.
	 *
	 * @spec openspec/specs/dashboard/spec.md#REQ-DASH-001
	 */
	public function averageProcessingDays(array $closed): ?float {
		$total = 0;
		$counted = 0;

		foreach ($closed as $row) {
			$days = $this->daysBetween(from: ($row['startDate'] ?? null), to: ($row['endDate'] ?? null));
			if ($days === null) {
				continue;
			}

			$total += $days;
			$counted++;
		}

		if ($counted === 0) {
			return null;
		}

		return round(($total / $counted), 1);
	}//end averageProcessingDays()

	/**
	 * Share of the closed cases that met their deadline, as a percentage.
	 *
	 * A case with no deadline is not counted either way: it had no target to
	 * meet, so including it would flatter or punish the figure arbitrarily.
	 *
	 * @param array<int, array> $closed The cases closed this month.
	 *
	 * @return float|null The percentage, or null when nothing measurable closed.
	 *
	 * @spec openspec/specs/dashboard/spec.md#REQ-DASH-001
	 */
	public function slaCompliance(array $closed): ?float {
		$within = 0;
		$measurable = 0;

		foreach ($closed as $row) {
			$deadline = substr((string)($row['deadline'] ?? ''), 0, 10);
			$end = substr((string)($row['endDate'] ?? ''), 0, 10);
			if ($deadline === '' || $end === '') {
				continue;
			}

			$measurable++;
			if ($end <= $deadline) {
				$within++;
			}
		}

		if ($measurable === 0) {
			return null;
		}

		return round((($within / $measurable) * 100), 0);
	}//end slaCompliance()

	/**
	 * Whole days between two dates.
	 *
	 * @param mixed $from The earlier date.
	 * @param mixed $to The later date.
	 *
	 * @return int|null The difference in days, or null when either is unusable.
	 */
	private function daysBetween(mixed $from, mixed $to): ?int {
		$fromDate = substr((string)$from, 0, 10);
		$toDate = substr((string)$to, 0, 10);
		if ($fromDate === '' || $toDate === '') {
			return null;
		}

		$start = date_create_immutable($fromDate);
		$end = date_create_immutable($toDate);
		if ($start === false || $end === false) {
			return null;
		}

		return $this->days->signed(from: $start, to: $end);
	}//end daysBetween()
}//end class
