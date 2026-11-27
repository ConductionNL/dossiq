<?php

/**
 * Dossiq hearing calendar writer.
 *
 * Writes one hearing event into the first calendar of each principal that
 * takes it: one that accepts ICS, is not in the trash and is writable. Split
 * out of {@see \OCA\Dossiq\Service\HearingCalendarService}, which keeps
 * building the event and working out who is expected; this class only
 * writes, and reports the calendars that would not take it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Calendar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-03
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Calendar;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Service\HearingCalendarEvent;
use OCP\Calendar\ICalendarIsWritable;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager as ICalendarManager;
use Psr\Log\LoggerInterface;

/**
 * Puts one hearing event into each principal's first calendar that takes it.
 *
 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-03
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class HearingCalendarWriter {

	/**
	 * Constructor.
	 *
	 * @param ICalendarManager $calendarManager Reads each principal's calendars.
	 * @param LoggerInterface  $logger          Logs a calendar that would not take the event.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly ICalendarManager $calendarManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Write one event for every principal, and report when no calendar took it.
	 *
	 * @param HearingCalendarEvent $event      The event.
	 * @param array<int, string>   $principals Principal URIs, each one once.
	 *
	 * @return int How many calendars took the event.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function writeForAll(HearingCalendarEvent $event, array $principals): int {
		$written = 0;
		foreach ($principals as $principal) {
			if ($this->writeToCalendar(principal: $principal, event: $event) === true) {
				$written++;
			}
		}

		if ($written === 0) {
			$this->logger->warning(
				'No writable calendar found for the hearing, event not stored',
				['app' => AppIdentity::APP_ID],
			);
		}

		return $written;
	}//end writeForAll()

	/**
	 * Write one ICS into the first writable calendar a principal owns.
	 *
	 * @param string $principal Principal URI to write for.
	 * @param HearingCalendarEvent $event The event: its UID (the object name) and its serialised ICS.
	 *
	 * @return bool True when a calendar took the event.
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-03
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function writeToCalendar(string $principal, HearingCalendarEvent $event): bool {
		try {
			$calendars = $this->calendarManager->getCalendarsForPrincipal($principal);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Could not read calendars for ' . $principal . ': ' . $e->getMessage(),
				['app' => AppIdentity::APP_ID],
			);
			return false;
		}

		foreach ($calendars as $calendar) {
			if ($this->takesHearings(calendar: $calendar) === false) {
				continue;
			}

			try {
				$calendar->createFromString($event->uid . '.ics', $event->ics);
				return true;
			} catch (\Throwable $e) {
				$this->logger->warning(
					'Calendar refused the hearing event for ' . $principal . ': ' . $e->getMessage(),
					['app' => AppIdentity::APP_ID],
				);
			}
		}

		return false;
	}//end writeToCalendar()

	/**
	 * Whether a calendar can take a new hearing: it takes ICS, is not in the trash and is writable.
	 *
	 * @param mixed $calendar A calendar of the principal.
	 *
	 * @return bool
	 *
	 * @psalm-assert-if-true ICreateFromString $calendar
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function takesHearings(mixed $calendar): bool {
		if (($calendar instanceof ICreateFromString) === false) {
			return false;
		}

		// ICreateFromString extends ICalendar, so isDeleted() is always
		// there: a calendar in the trash must not take a new hearing.
		if ($calendar->isDeleted() === true) {
			return false;
		}

		return ($calendar instanceof ICalendarIsWritable && $calendar->isWritable() === false) === false;
	}//end takesHearings()
}//end class
