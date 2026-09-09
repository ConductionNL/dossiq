<?php

/**
 * Dossiq KCC SLA Calculator
 *
 * SLA-deadline arithmetic for KlantContactCentrum contact moments and
 * callbacks: which channel gets how long, when that deadline falls, and when a
 * failed callback should be retried.
 *
 * The weekend / Dutch-holiday / working-day questions underneath all of that
 * are not answered here. They belong to WorkingDayCalculator, which is the
 * app's single implementation of them; the four methods below are kept as
 * pass-throughs because KCC callers already depend on this class's shape.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Kcc
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
 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-25
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Kcc;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use OCA\Dossiq\Service\WorkingDayCalculator;

/**
 * Deterministic SLA / working-day calculator for the KCC integration.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-25
 */
class SlaCalculator {
	/**
	 * Default SLA targets in seconds per channel.
	 *
	 * - phone: 2.8 minutes (168s) handle-time target.
	 * - chat: 1 hour first-response.
	 * - email / web_form: 2 working days.
	 *
	 * @var array<string, int>
	 */
	public const CHANNEL_SLA_SECONDS = [
		'phone' => 168,
		'chat' => 3600,
		'social' => 3600,
		'email' => (2 * 8 * 3600),
		'web_form' => (2 * 8 * 3600),
		'letter' => (5 * 8 * 3600),
	];

	/**
	 * Email/letter SLA is expressed in working days rather than raw seconds.
	 *
	 * @var array<string, int>
	 */
	private const CHANNEL_SLA_WORKING_DAYS = [
		'email' => 2,
		'web_form' => 2,
		'letter' => 5,
	];

	/**
	 * Constructor.
	 *
	 * @param WorkingDayCalculator $workingDays The app's single source of truth
	 *                                          for weekends, Dutch national
	 *                                          holidays and working-day
	 *                                          arithmetic.
	 */
	public function __construct(
		private readonly WorkingDayCalculator $workingDays,
	) {
	}//end __construct()

	/**
	 * Determine whether a date is a weekend day.
	 *
	 * @param DateTimeInterface $date The date to inspect.
	 *
	 * @return bool True for Saturday or Sunday.
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-25
	 */
	public function isWeekend(DateTimeInterface $date): bool {
		return $this->workingDays->isWeekend(date: $date);
	}//end isWeekend()

	/**
	 * Determine whether a date is a Dutch public holiday.
	 *
	 * Covers the nationally recognised holidays: Nieuwjaarsdag, Goede Vrijdag,
	 * Eerste/Tweede Paasdag, Koningsdag, Bevrijdingsdag, Hemelvaartsdag,
	 * Eerste/Tweede Pinksterdag, Eerste/Tweede Kerstdag.
	 *
	 * @param DateTimeInterface $date The date to inspect.
	 *
	 * @return bool True when the date is a recognised public holiday.
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-25
	 */
	public function isDutchHoliday(DateTimeInterface $date): bool {
		return $this->workingDays->isHoliday(date: $date);
	}//end isDutchHoliday()

	/**
	 * Determine whether a date is a working day (not weekend, not holiday).
	 *
	 * @param DateTimeInterface $date The date to inspect.
	 *
	 * @return bool True for a working day.
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-25
	 */
	public function isWorkingDay(DateTimeInterface $date): bool {
		return $this->workingDays->isWorkingDay(date: $date);
	}//end isWorkingDay()

	/**
	 * Add a number of working days to a starting date.
	 *
	 * The time-of-day component of the start date is preserved.
	 *
	 * @param DateTimeImmutable $start The starting date-time.
	 * @param int $days Number of working days to add (>= 0).
	 *
	 * @return DateTimeImmutable The resulting date-time.
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-25
	 */
	public function addWorkingDays(DateTimeImmutable $start, int $days): DateTimeImmutable {
		return $this->workingDays->addWorkingDays(start: $start, days: $days);
	}//end addWorkingDays()

	/**
	 * Count the working days in an inclusive date range.
	 *
	 * @param DateTimeImmutable $start Range start (inclusive).
	 * @param DateTimeImmutable $end Range end (inclusive).
	 *
	 * @return int Number of working days in the range (0 when end < start).
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-25
	 */
	public function countWorkingDays(DateTimeImmutable $start, DateTimeImmutable $end): int {
		return $this->workingDays->countWorkingDays(start: $start, end: $end);
	}//end countWorkingDays()

	/**
	 * Compute the SLA deadline for a contact moment on a given channel.
	 *
	 * Working-day channels (email, web_form, letter) advance by whole working
	 * days; real-time channels (phone, chat, social) add the raw second target.
	 *
	 * @param string $channel The contact channel.
	 * @param DateTimeImmutable $start The contact start time.
	 *
	 * @return DateTimeImmutable The SLA deadline.
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-25
	 */
	public function deadlineFor(string $channel, DateTimeImmutable $start): DateTimeImmutable {
		if (isset(self::CHANNEL_SLA_WORKING_DAYS[$channel]) === true) {
			return $this->addWorkingDays(start: $start, days: self::CHANNEL_SLA_WORKING_DAYS[$channel]);
		}

		$seconds = (int)(self::CHANNEL_SLA_SECONDS[$channel] ?? self::CHANNEL_SLA_SECONDS['chat']);
		return $start->add(new DateInterval('PT' . $seconds . 'S'));
	}//end deadlineFor()

	/**
	 * Determine whether an SLA has been breached at a reference time.
	 *
	 * @param string $channel The contact channel.
	 * @param DateTimeImmutable $start The contact start time.
	 * @param DateTimeImmutable $now The reference (current) time.
	 *
	 * @return bool True when the deadline has passed.
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-25
	 */
	public function isBreached(string $channel, DateTimeImmutable $start, DateTimeImmutable $now): bool {
		return ($now > $this->deadlineFor(channel: $channel, start: $start));
	}//end isBreached()

	/**
	 * Compute the retry time for a callback attempt with exponential backoff.
	 *
	 * Backoff doubles per attempt from a 15-minute base, capped at 24h.
	 *
	 * @param DateTimeImmutable $from The time the attempt failed.
	 * @param int $attemptCount The number of attempts already made (>= 0).
	 *
	 * @return DateTimeImmutable The next attempt time.
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-25
	 */
	public function nextRetryAt(DateTimeImmutable $from, int $attemptCount): DateTimeImmutable {
		$baseMinutes = 15;
		$factor = (2 ** max(0, $attemptCount));
		$minutes = (int)min(($baseMinutes * $factor), (24 * 60));

		return $from->add(new DateInterval('PT' . $minutes . 'M'));
	}//end nextRetryAt()
}//end class
