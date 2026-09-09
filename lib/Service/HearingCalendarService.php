<?php

/**
 * Dossiq Hearing Calendar Service
 *
 * Puts a scheduled hoorgesprek on the calendar of everyone expected at it.
 * This is its own class rather than a private method on HearingService because
 * it is a different seam: HearingService owns the register record, this owns
 * the calendar write, and the two fail independently.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-03
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\AppInfo\Application;
use DateInterval;
use DateTimeImmutable;
use OCP\Calendar\ICalendar;
use OCP\Calendar\ICalendarEventBuilder;
use OCP\Calendar\ICalendarIsWritable;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager as ICalendarManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Writes the calendar event for a hearing.
 *
 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-03
 */
class HearingCalendarService {

	/**
	 * Prefix of the calendar event summary. The Talk room uses the same words,
	 * so a participant sees one name for one hearing in both places.
	 */
	private const EVENT_SUMMARY_PREFIX = 'Hoorgesprek klacht ';

	/**
	 * How long the event runs. The hearing schema records no duration, so an
	 * hour is a stated assumption rather than a guessed one.
	 */
	private const EVENT_DURATION = 'PT1H';

	/**
	 * Principal URI prefix for a Nextcloud account's own calendars.
	 */
	private const PRINCIPAL_PREFIX = 'principals/users/';

	/**
	 * Constructor.
	 *
	 * @param ICalendarManager $calendarManager Calendar manager, writes the hearing event
	 * @param IUserManager $userManager Resolves a participant to an account
	 * @param IUserSession $userSession The organiser of the hearing
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly ICalendarManager $calendarManager,
		private readonly IUserManager $userManager,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Write the hearing into the calendar of everyone who is expected there.
	 *
	 * One event, one UID, written into the organiser's calendar and into the
	 * calendar of every participant that resolves to a Nextcloud account.
	 * `createFromString()` writes straight through CalDAV and does NOT run
	 * sabre's scheduling plugin, so an event written once into the organiser's
	 * calendar would reach nobody else. Writing the same ICS per attendee is
	 * what actually puts the hearing on their day.
	 *
	 * A participant that is a bare name rather than an account id gets an
	 * ATTENDEE line and no calendar of its own, because there is nothing to
	 * write to. The hearing has no duration field, so the event is one hour.
	 *
	 * @param array<string, mixed> $data Hearing data with participants.
	 *
	 * @return string The event UID, or an empty string when nothing was written.
	 *
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-03
	 */
	public function createEvent(array $data): string {
		$participants = $data['participants'] ?? [];
		if (is_array($participants) === false || $participants === []) {
			return '';
		}

		$date = (string)($data['date'] ?? '');
		try {
			$start = new DateTimeImmutable($date);
		} catch (\Exception $e) {
			$this->logger->warning(
				'Hearing has no readable date, no calendar event written: ' . $date,
				['app' => Application::APP_ID],
			);
			return '';
		}

		$location = (string)($data['location'] ?? '');
		$organiser = $this->userSession->getUser();

		$builder = $this->calendarManager->createEventBuilder();
		$builder->setSummary(self::EVENT_SUMMARY_PREFIX . (string)($data['complaint'] ?? ''));
		$builder->setStartDate($start);
		$builder->setEndDate($start->add(new DateInterval(self::EVENT_DURATION)));
		if ($location !== '') {
			$builder->setLocation($location);
		}

		$builder->setDescription($this->describeHearing(data: $data, participants: $participants));

		if ($organiser !== null && (string)$organiser->getEMailAddress() !== '') {
			$builder->setOrganizer(
				(string)$organiser->getEMailAddress(),
				$organiser->getDisplayName()
			);
		}

		$attendees = $this->resolveParticipants(participants: $participants);
		foreach ($attendees as $attendee) {
			$builder->addAttendee($attendee['email'], $attendee['name']);
		}

		$ics = $builder->toIcs();
		$uid = '';
		if (preg_match('/^UID:(.+)$/mi', $ics, $matches) === 1) {
			$uid = trim($matches[1]);
		}

		if ($uid === '') {
			$this->logger->warning(
				'Calendar event for hearing carried no UID, nothing written',
				['app' => Application::APP_ID],
			);
			return '';
		}

		$principals = [];
		if ($organiser !== null) {
			$principals[] = self::PRINCIPAL_PREFIX . $organiser->getUID();
		}

		foreach ($attendees as $attendee) {
			$principals[] = self::PRINCIPAL_PREFIX . $attendee['uid'];
		}

		$written = 0;
		foreach (array_unique($principals) as $principal) {
			if ($this->writeToCalendar(principal: $principal, uid: $uid, ics: $ics) === true) {
				$written++;
			}
		}

		if ($written === 0) {
			$this->logger->warning(
				'No writable calendar found for the hearing, event not stored',
				['app' => Application::APP_ID],
			);
			return '';
		}

		$this->logger->info(
			'Hearing written to ' . $written . ' calendars for ' . count($participants) . ' participants',
			['app' => Application::APP_ID, 'calendarEventId' => $uid],
		);

		return $uid;
	}//end createEvent()

	/**
	 * Turn the participant list into addressable attendees.
	 *
	 * A participant is either a Nextcloud account id or a plain name. Only the
	 * first can be invited, and only when the account carries an email address:
	 * an ATTENDEE line without a mailto is not an invitation.
	 *
	 * @param array<int, mixed> $participants Participants as stored.
	 *
	 * @return array<int, array{uid: string, email: string, name: string}>
	 *
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-03
	 */
	private function resolveParticipants(array $participants): array {
		$resolved = [];
		foreach ($participants as $participant) {
			if (is_string($participant) === false || trim($participant) === '') {
				continue;
			}

			$user = $this->userManager->get(trim($participant));
			if ($user === null) {
				continue;
			}

			$email = (string)$user->getEMailAddress();
			if ($email === '') {
				continue;
			}

			$resolved[] = [
				'uid' => $user->getUID(),
				'email' => $email,
				'name' => $user->getDisplayName(),
			];
		}

		return $resolved;
	}//end resolveParticipants()

	/**
	 * Write one ICS into the first writable calendar a principal owns.
	 *
	 * @param string $principal Principal URI to write for.
	 * @param string $uid Event UID, used as the object name.
	 * @param string $ics The event, already serialised.
	 *
	 * @return bool True when a calendar took the event.
	 *
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-03
	 */
	private function writeToCalendar(string $principal, string $uid, string $ics): bool {
		try {
			$calendars = $this->calendarManager->getCalendarsForPrincipal($principal);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Could not read calendars for ' . $principal . ': ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
			return false;
		}

		foreach ($calendars as $calendar) {
			if (($calendar instanceof ICreateFromString) === false) {
				continue;
			}

			if ($calendar instanceof ICalendar && $calendar->isDeleted() === true) {
				continue;
			}

			if ($calendar instanceof ICalendarIsWritable && $calendar->isWritable() === false) {
				continue;
			}

			try {
				$calendar->createFromString($uid . '.ics', $ics);
				return true;
			} catch (\Throwable $e) {
				$this->logger->warning(
					'Calendar refused the hearing event for ' . $principal . ': ' . $e->getMessage(),
					['app' => Application::APP_ID],
				);
			}
		}

		return false;
	}//end writeToCalendar()

	/**
	 * Describe the hearing in the body of the calendar event.
	 *
	 * The description names every planned participant, including the ones that
	 * are a bare name rather than an account: they cannot be invited, and the
	 * people who can be need to know they are expected too.
	 *
	 * @param array<string, mixed> $data Hearing data.
	 * @param array<int, mixed> $participants Participants as stored.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-03
	 */
	private function describeHearing(array $data, array $participants): string {
		$lines = ['Hoorgesprek over klacht ' . (string)($data['complaint'] ?? '')];

		$type = (string)($data['type'] ?? '');
		if ($type !== '') {
			$lines[] = 'Vorm: ' . $type;
		}

		$talkUrl = (string)($data['talkRoomUrl'] ?? '');
		if ($talkUrl !== '') {
			$lines[] = 'Gespreksruimte: ' . $talkUrl;
		}

		$names = [];
		foreach ($participants as $participant) {
			if (is_string($participant) === false) {
				continue;
			}

			$name = trim($participant);
			if ($name !== '') {
				$names[] = $name;
			}
		}

		if ($names !== []) {
			$lines[] = 'Deelnemers: ' . implode(', ', $names);
		}

		return implode("\n", $lines);
	}//end describeHearing()
}//end class
