<?php

/**
 * Dossiq ThroughputBuckets.
 *
 * The groups a throughput summary averages over: per month, quarter, year or
 * ISO week, from the first day of the window to today.
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
 * Names and lists the groups of a throughput summary.
 *
 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
 */
class ThroughputBuckets {
	/**
	 * The empty groups from the first day of the window to today, in order.
	 *
	 * @param DateTimeImmutable $start       The window's first day.
	 * @param DateTimeImmutable $today       The day the figures are read on.
	 * @param string            $granularity `month`, `quarter`, `year` or `week`.
	 *
	 * @return array<string, array<int, int>>
	 *
	 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
	 */
	public function emptyBuckets(DateTimeImmutable $start, DateTimeImmutable $today, string $granularity): array {
		$step = '+1 month';
		if ($granularity === 'week') {
			$step = '+1 week';
		}

		$buckets = [];
		for ($day = $start; $day <= $today; $day = $day->modify($step)) {
			$buckets[$this->bucketOf(day: $day, granularity: $granularity)] = [];
		}

		$buckets[$this->bucketOf(day: $today, granularity: $granularity)] = [];

		return $buckets;
	}//end emptyBuckets()

	/**
	 * The group a day falls in: `YYYY-MM`, `YYYY-Qn`, `YYYY` or the ISO week `YYYY-Www`.
	 *
	 * @param DateTimeImmutable $day         The day.
	 * @param string            $granularity `month`, `quarter`, `year` or `week`.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
	 */
	public function bucketOf(DateTimeImmutable $day, string $granularity): string {
		if ($granularity === 'week') {
			return $day->format('o-\\WW');
		}

		if ($granularity === 'quarter') {
			return $day->format('Y') . '-Q' . (intdiv(((int)$day->format('n')) - 1, 3) + 1);
		}

		if ($granularity === 'year') {
			return $day->format('Y');
		}

		return $day->format('Y-m');
	}//end bucketOf()
}//end class
