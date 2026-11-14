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
 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-03
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use OCA\Dossiq\Kernel\AppIdentity;
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
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The total is the sum of small private steps; each method stays under the method thresholds.
 *
 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-03
 */
class HearingCalendarService {

	/**
	 * Prefix of the calendar event summary. The Talk room uses the same words,
	 * so a participant sees one name for one hearing in both places.
	 */
	private const EVENT_SUMMARY_PREFIX = 'Hoorgesprek klacht ';

	/**
	 * How long the event runs, in seconds. The hearing schema records no
	 * duration, so an hour is a stated assumption rather than a guessed one.
	 */
	private const EVENT_DURATION_SECONDS = 3600;

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
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-03
	 */
	public function createEvent(array $data): string {
		$participants = $this->plannedParticipants(data: $data);
		if ($participants === []) {
			return '';
		}

		$start = $this->readStart(data: $data);
		if ($start === null) {
			return '';
		}

		return $this->writeHearing(hearing: new PlannedHearing(data: $data, start: $start, participants: $participants));
	}//end createEvent()

	/**
	 * Build the hearing's event and write it into every calendar it reaches.
	 *
	 * @param PlannedHearing $hearing The hearing data, its start and the planned participants.
	 *
	 * @return string The event UID, or an empty string when nothing was written.
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-03
	 */
	private function writeHearing(PlannedHearing $hearing): string {
		[$ics, $organiser, $attendees] = $this->hearingEvent(hearing: $hearing);

		$uid = $this->readUid(ics: $ics);
		if ($uid === '') {
			return '';
		}

		$event = new HearingCalendarEvent(uid: $uid, ics: $ics);
		$written = $this->writeForAll(event: $event, principals: $this->principalsFor(organiser: $organiser, attendees: $attendees));
		if ($written === 0) {
			return '';
		}

		$this->logWritten(written: $written, participants: $hearing->participants, uid: $uid);

		return $uid;
	}//end writeHearing()

	/**
	 * The hearing's ICS, with the organiser and the attendees it names.
	 *
	 * @param PlannedHearing $hearing The hearing data, its start and the planned participants.
	 *
	 * @return array{0: string, 1: IUser|null, 2: array<int, mixed>} The ICS, the organiser and the attendees.
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-03
	 */
	private function hearingEvent(PlannedHearing $hearing): array {
		$organiser = $this->userSession->getUser();
		$attendees = $this->resolveParticipants(participants: $hearing->participants);

		$ics = $this->buildEvent(hearing: $hearing, organiser: $organiser, attendees: $attendees);

		return [$ics, $organiser, $attendees];
	}//end hearingEvent()

	/**
	 * Log how many calendars the hearing reached.
	 *
	 * @param int $written How many calendars were written.
	 * @param array<int, string> $participants The planned participants.
	 * @param string $uid The event UID.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-03
	 */
	private function logWritten(int $written, array $participants, string $uid): void {
		$this->logger->info(
			'Hearing written to ' . $written . ' calendars for ' . count($participants) . ' participants',
			['app' => AppIdentity::APP_ID, 'calendarEventId' => $uid],
		);
	}//end logWritten()

	/**
	 * The participants a hearing plans, empty when it plans none or stores them in another shape.
	 *
	 * @param array<string, mixed> $data Hearing data.
	 *
	 * @return array<int|string, mixed> Participants as stored.
	 */
	private function plannedParticipants(array $data): array {
		$participants = $data['participants'] ?? [];
		if (is_array($participants) === false) {
			return [];
		}

		return $participants;
	}//end plannedParticipants()

	/**
	 * Write one event for every principal, and report when no calendar took it.
	 *
	 * @param HearingCalendarEvent $event      The event.
	 * @param array<int, string>   $principals Principal URIs, each one once.
	 *
	 * @return int How many calendars took the event.
	 */
	private function writeForAll(HearingCalendarEvent $event, array $principals): int {
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
	 * Read the hearing's start moment, or nothing when the date is unreadable.
	 *
	 * An unreadable date must not become an event at "now": a hearing on the
	 * wrong day in someone's calendar is worse than no hearing in it.
	 *
	 * @param array<string, mixed> $data Hearing data.
	 *
	 * @return DateTimeImmutable|null
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-03
	 */
	private function readStart(array $data): ?DateTimeImmutable {
		$date = (string)($data['date'] ?? '');
		try {
			return new DateTimeImmutable($date);
		} catch (\Exception $e) {
			$this->logger->warning(
				'Hearing has no readable date, no calendar event written: ' . $date,
				['app' => AppIdentity::APP_ID],
			);
			return null;
		}
	}//end readStart()

	/**
	 * Serialise the hearing as one VEVENT.
	 *
	 * @param PlannedHearing $hearing The hearing data, its start and the participants as stored.
	 * @param IUser|null $organiser The account scheduling the hearing.
	 * @param array<int, array{uid: string, email: string, name: string}> $attendees Addressable attendees.
	 *
	 * @return string The event as ICS.
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-03
	 */
	private function buildEvent(PlannedHearing $hearing, ?IUser $organiser, array $attendees): string {
		$data = $hearing->data;
		$start = $hearing->start;
		$builder = $this->calendarManager->createEventBuilder();
		$builder->setSummary(self::EVENT_SUMMARY_PREFIX . (string)($data['complaint'] ?? ''));
		$builder->setStartDate($start);
		$builder->setEndDate($start->setTimestamp($start->getTimestamp() + self::EVENT_DURATION_SECONDS));
		$builder->setDescription($this->describeHearing(data: $data, participants: $hearing->participants));

		$location = (string)($data['location'] ?? '');
		if ($location !== '') {
			$builder->setLocation($location);
		}

		$this->withParties(builder: $builder, organiser: $organiser, attendees: $attendees);

		return $builder->toIcs();
	}//end buildEvent()

	/**
	 * Put the organiser, when they have an address, and the attendees on the event.
	 *
	 * @param ICalendarEventBuilder                                       $builder   The event being built.
	 * @param IUser|null                                                  $organiser The organiser.
	 * @param array<int, array{uid: string, email: string, name: string}> $attendees Addressable attendees.
	 *
	 * @return void
	 */
	private function withParties(ICalendarEventBuilder $builder, ?IUser $organiser, array $attendees): void {
		if ($organiser !== null && (string)$organiser->getEMailAddress() !== '') {
			$builder->setOrganizer(
				(string)$organiser->getEMailAddress(),
				$organiser->getDisplayName()
			);
		}

		foreach ($attendees as $attendee) {
			$builder->addAttendee($attendee['email'], $attendee['name']);
		}
	}//end withParties()

	/**
	 * Read the UID out of a serialised event.
	 *
	 * Every copy of the hearing has to carry the SAME UID, or a participant's
	 * reply lands on an event nobody else holds. Taking it from the ICS rather
	 * than from a second call is what guarantees that.
	 *
	 * @param string $ics The serialised event.
	 *
	 * @return string The UID, or an empty string when it carries none.
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-03
	 */
	private function readUid(string $ics): string {
		$matches = [];
		if (preg_match('/^UID:(.+)$/mi', $ics, $matches) === 1) {
			return trim($matches[1]);
		}

		$this->logger->warning(
			'Calendar event for hearing carried no UID, nothing written',
			['app' => AppIdentity::APP_ID],
		);

		return '';
	}//end readUid()

	/**
	 * The principals whose calendars this hearing belongs in.
	 *
	 * @param IUser|null $organiser The account scheduling the hearing.
	 * @param array<int, array{uid: string, email: string, name: string}> $attendees Addressable attendees.
	 *
	 * @return array<int, string> Principal URIs, each one once.
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-03
	 */
	private function principalsFor(?IUser $organiser, array $attendees): array {
		$principals = [];
		if ($organiser !== null) {
			$principals[] = self::PRINCIPAL_PREFIX . $organiser->getUID();
		}

		foreach ($attendees as $attendee) {
			$principals[] = self::PRINCIPAL_PREFIX . $attendee['uid'];
		}

		return array_values(array_unique($principals));
	}//end principalsFor()

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
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-03
	 */
	private function resolveParticipants(array $participants): array {
		$resolved = [];
		foreach ($participants as $participant) {
			$attendee = $this->attendeeFor(participant: $participant);
			if ($attendee !== []) {
				$resolved[] = $attendee;
			}
		}

		return $resolved;
	}//end resolveParticipants()

	/**
	 * One participant as an addressable attendee: an account id whose account has an address.
	 *
	 * @param mixed $participant The participant as stored.
	 *
	 * @return array{uid: string, email: string, name: string}|array{} The attendee, empty when it cannot be invited.
	 */
	private function attendeeFor(mixed $participant): array {
		if (is_string($participant) === false || trim($participant) === '') {
			return [];
		}

		$user = $this->userManager->get(trim($participant));
		if ($user === null) {
			return [];
		}

		$email = (string)$user->getEMailAddress();
		if ($email === '') {
			return [];
		}

		return ['uid' => $user->getUID(), 'email' => $email, 'name' => $user->getDisplayName()];
	}//end attendeeFor()

	/**
	 * Write one ICS into the first writable calendar a principal owns.
	 *
	 * @param string $principal Principal URI to write for.
	 * @param HearingCalendarEvent $event The event: its UID (the object name) and its serialised ICS.
	 *
	 * @return bool True when a calendar took the event.
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-03
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
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-03
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

		$names = $this->participantNames(participants: $participants);
		if ($names !== []) {
			$lines[] = 'Deelnemers: ' . implode(', ', $names);
		}

		return implode("\n", $lines);
	}//end describeHearing()

	/**
	 * The trimmed, non-empty names of the participants stored as text.
	 *
	 * @param array<int, mixed> $participants Participants as stored.
	 *
	 * @return array<int, string>
	 */
	private function participantNames(array $participants): array {
		$names = [];
		foreach ($participants as $participant) {
			if (is_string($participant) === true && trim($participant) !== '') {
				$names[] = trim($participant);
			}
		}

		return $names;
	}//end participantNames()
}//end class
