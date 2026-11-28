<?php

/**
 * The end date an extension of a case's term lands on.
 *
 * Either the date the caller named, which has to lie after the current end,
 * or the current end plus the case type's ISO 8601 extension period, rolled
 * onto a day the administered calendar works (Awt art. 1).
 * {@see \OCA\Dossiq\Service\CaseLifecycleService::extend()} asks it and carries
 * the extension through the term and the journal.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Lifecycle;

use DateInterval;
use DateTimeImmutable;
use OCA\Dossiq\Service\TermijnTimerService;
use RuntimeException;

/**
 * Resolves the new end date of an extension.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
final class ExtensionEndDate {

	/**
	 * Constructor.
	 *
	 * @param TermijnTimerService|null $timer The engine calendar bridge; without it the end is not rolled.
	 */
	public function __construct(
		private readonly ?TermijnTimerService $timer = null,
	) {
	}//end __construct()

	/**
	 * The new end date an extension lands on: the one the caller named, or
	 * the current one plus the case type's statutory period.
	 *
	 * @param string                               $current The current end date (Y-m-d), possibly empty
	 * @param array{named: string, period: string} $asked   `named` an explicit new end date, or '' for the period; `period` the case type's ISO
	 *                                                      8601 extension period.
	 *
	 * @return string The new end date as Y-m-d
	 *
	 * @throws RuntimeException When the named date is unreadable or not later,
	 *                          or when no period is configured and none was named
	 *
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 * @spec openspec/specs/case-bulk-status-transition/spec.md
	 */
	public function resolve(string $current, array $asked): string {
		[$named, $period] = [$asked['named'], $asked['period']];
		if ($named !== '') {
			return $this->requireLaterDate(current: $current, candidate: $named);
		}

		if ($period === '') {
			throw new RuntimeException('extension_period_not_configured');
		}

		return $this->addPeriod(date: $current, period: $period);
	}//end resolve()

	/**
	 * An explicit new end date, normalised, having checked it is later than
	 * the current one.
	 *
	 * @param string $current   The current end date (Y-m-d), possibly empty
	 * @param string $candidate The date the caller named
	 *
	 * @return string The new end date as Y-m-d
	 *
	 * @throws RuntimeException When the date is unreadable or not later
	 *
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 * @spec openspec/specs/case-bulk-status-transition/spec.md
	 */
	private function requireLaterDate(string $current, string $candidate): string {
		try {
			$next = new DateTimeImmutable($candidate);
		} catch (\Throwable $e) {
			throw new RuntimeException('new_end_date_unreadable');
		}

		if ($current !== '') {
			try {
				$now = new DateTimeImmutable($current);
			} catch (\Throwable $e) {
				$now = null;
			}

			if ($now !== null && $next <= $now) {
				throw new RuntimeException('new_end_date_not_later');
			}
		}

		return $next->format('Y-m-d');
	}//end requireLaterDate()

	/**
	 * Add an ISO 8601 duration to a date.
	 *
	 * @param string $date   The current end date (Y-m-d); today when empty
	 * @param string $period An ISO 8601 duration such as P14D
	 *
	 * @return string The new end date as Y-m-d
	 *
	 * @throws RuntimeException When the period is not a duration this can read
	 *
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function addPeriod(string $date, string $period): string {
		try {
			$start = new DateTimeImmutable($date);
		} catch (\Throwable $e) {
			$start = new DateTimeImmutable();
		}

		try {
			$interval = new DateInterval($period);
		} catch (\Throwable $e) {
			throw new RuntimeException('extension_period_unreadable');
		}

		// The extended end date is the one a handler is judged on, so the
		// organisation's calendar decides the day it lands on (Awt art. 1).
		$end = $start->add($interval);

		return ($this->timer?->rollTermEndFor(date: $end) ?? $end)->format('Y-m-d');
	}//end addPeriod()
}//end class
