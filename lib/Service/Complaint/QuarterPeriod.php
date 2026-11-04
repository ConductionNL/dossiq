<?php

/**
 * Dossiq QuarterPeriod.
 *
 * One calendar quarter: its first and last day, the quarter before it, and
 * the twelve months that end with it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Complaint
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
 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/complaint-management/spec.md#requirement-the-complaint-patterns-sit-on-the-process-analysis-page
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Complaint;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A calendar quarter.
 *
 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/complaint-management/spec.md#requirement-the-complaint-patterns-sit-on-the-process-analysis-page
 */
final class QuarterPeriod {
	/**
	 * Constructor.
	 *
	 * @param int $year    The year.
	 * @param int $quarter The quarter, 1 to 4.
	 */
	public function __construct(
		public readonly int $year,
		public readonly int $quarter,
	) {
	}//end __construct()

	/**
	 * The quarter a `YYYY-Qn` parameter names, or the last full quarter before
	 * `$now` when the parameter is empty.
	 *
	 * @param string            $param The parameter, e.g. `2026-Q3`, or ''.
	 * @param DateTimeImmutable $now   The moment the last full quarter is counted from.
	 *
	 * @return self
	 *
	 * @throws InvalidArgumentException When the parameter is not `YYYY-Qn`.
	 *
	 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/complaint-management/spec.md#requirement-the-complaint-patterns-sit-on-the-process-analysis-page
	 */
	public static function fromParam(string $param, DateTimeImmutable $now): self {
		$param = trim($param);
		if ($param === '') {
			$current = new self(year: (int)$now->format('Y'), quarter: intdiv(((int)$now->format('n') - 1), 3) + 1);

			return $current->previous();
		}

		if (preg_match('/^(\d{4})-Q([1-4])$/', $param, $match) !== 1) {
			throw new InvalidArgumentException('quarter must read YYYY-Qn');
		}

		return new self(year: (int)$match[1], quarter: (int)$match[2]);
	}//end fromParam()

	/**
	 * The quarter before this one.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/complaint-management/spec.md#requirement-the-complaint-patterns-sit-on-the-process-analysis-page
	 */
	public function previous(): self {
		if ($this->quarter === 1) {
			return new self(year: ($this->year - 1), quarter: 4);
		}

		return new self(year: $this->year, quarter: ($this->quarter - 1));
	}//end previous()

	/**
	 * The first day, `Y-m-d`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/complaint-management/spec.md#requirement-the-complaint-patterns-sit-on-the-process-analysis-page
	 */
	public function firstDay(): string {
		return sprintf('%04d-%02d-01', $this->year, ((($this->quarter - 1) * 3) + 1));
	}//end firstDay()

	/**
	 * The last day, `Y-m-d`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/complaint-management/spec.md#requirement-the-complaint-patterns-sit-on-the-process-analysis-page
	 */
	public function lastDay(): string {
		return (new DateTimeImmutable($this->firstDay()))->modify('+3 months -1 day')->format('Y-m-d');
	}//end lastDay()

	/**
	 * The twelve months that end with this quarter, oldest first, as `Y-m`.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/complaint-management/spec.md#requirement-the-complaint-patterns-sit-on-the-process-analysis-page
	 */
	public function trailingMonths(): array {
		$first = (new DateTimeImmutable($this->firstDay()))->modify('-9 months');
		$months = [];
		for ($i = 0; $i < 12; $i++) {
			$months[] = $first->modify('+'.$i.' months')->format('Y-m');
		}

		return $months;
	}//end trailingMonths()

	/**
	 * Whether a date falls in this quarter.
	 *
	 * @param string $date A date that starts with `Y-m-d`.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/complaint-management/spec.md#requirement-the-complaint-patterns-sit-on-the-process-analysis-page
	 */
	public function holds(string $date): bool {
		$day = substr($date, 0, 10);

		return $day >= $this->firstDay() && $day <= $this->lastDay();
	}//end holds()
}//end class
