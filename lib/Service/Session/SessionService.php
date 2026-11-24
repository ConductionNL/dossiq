<?php

/**
 * Dossiq session service.
 *
 * Plans, waives, records attendance at and closes the sessions a case holds
 * with its parties. Which sessions a case can hold, who may be invited, how
 * long the file is open for inspection beforehand, whether a party may waive
 * and whether minutes are required are read from the case type's `sessions[]`,
 * so a procedure that needs a hearing declares one instead of shipping a class.
 *
 * Every session is a record in the session schema (`hearing_session_schema`)
 * carrying its `sessionKey`. OpenRegister writes the audit trail of each save
 * and shows sessions in Calendar through the schema's `calendarProvider`.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Session
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
 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Session;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\OpensInTheInitialState;
use OCA\Dossiq\Service\Support\CaseRegisterReads;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\AppFramework\Utility\ITimeFactory;
use RuntimeException;
use Throwable;

/**
 * Runs the sessions a case type declares.
 *
 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md#requirement-planning-a-session-keeps-the-inspection-floor-and-the-invitee-roles-req-css-2
 */
class SessionService {

	use SearchesObjects;
	use OpensInTheInitialState;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Register, schema keys and the object service.
	 * @param ITimeFactory       $timeFactory     The clock.
	 * @param CaseDateNormaliser $caseDates       The one reader of a date.
	 * @param SessionMeeting|null $meetings       Opens a planned session as a meeting in the decision app.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ITimeFactory $timeFactory,
		private readonly CaseDateNormaliser $caseDates,
		private readonly ?SessionMeeting $meetings = null,
	) {
	}//end __construct()

	/**
	 * The sessions a case's case type declares, and the sessions it holds.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array{definitions: array<int, array<string, mixed>>, sessions: array<int, array<string, mixed>>} The sessions.
	 *
	 * @throws RuntimeException When the case or its case type cannot be read.
	 *
	 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md#requirement-a-case-type-declares-the-sessions-a-case-holds-req-css-1
	 */
	public function sessionsFor(string $caseId): array {
		$objectService = $this->reads()->objectService();
		$register      = $this->settingsService->getConfigValue(key: 'register');
		$definitions   = $this->definitionsFor(location: [$objectService, $register], caseId: $caseId);

		return [
			'definitions' => array_values(array_map(static fn (SessionDefinition $def): array => $def->toArray(), $definitions)),
			'sessions'    => $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $this->reads()->schemaKey(key: 'hearing_session_schema'),
				filters: ['case' => $caseId]
			),
		];
	}//end sessionsFor()

	/**
	 * Plan a session the case type declares.
	 *
	 * @param string                $caseId        The case.
	 * @param string                $sessionKey    The `sessions[]` entry.
	 * @param string                $scheduledDate ISO-8601 start.
	 * @param string                $chairpersonId Who chairs the session.
	 * @param array<int, mixed>     $invitees      The invited parties, each {role, name?, channel?}.
	 * @param array<string, mixed>  $payload       Extra fields (location, videoCallUrl, members, ...).
	 *
	 * @return array<string, mixed> The stored session.
	 *
	 * @throws InvalidArgumentException When the session is not declared or the plan breaks its rules.
	 * @throws RuntimeException When the register cannot be reached or refuses the write.
	 *
	 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md#requirement-planning-a-session-keeps-the-inspection-floor-and-the-invitee-roles-req-css-2
	 */
	public function schedule(
		string $caseId,
		string $sessionKey,
		string $scheduledDate,
		string $chairpersonId,
		array $invitees,
		array $payload = [],
	): array {
		if (trim($caseId) === '' || trim($chairpersonId) === '') {
			throw new InvalidArgumentException('Name the case and who chairs the session.');
		}

		$write = $this->sessionWrite(caseId: $caseId, sessionKey: $sessionKey);
		$write->chairpersonId = $chairpersonId;
		$write->payload = $payload;
		$write->invitees = $invitees;

		return $this->plan(write: $write, scheduledDate: $scheduledDate);
	}//end schedule()

	/**
	 * Plan the session at its start, when the plan keeps the inspection floor and the invitee roles.
	 *
	 * @param SessionWrite $write         The session, who chairs it, the payload and the invitees.
	 * @param string       $scheduledDate ISO-8601 start.
	 *
	 * @return array<string, mixed> The stored session.
	 *
	 * @throws InvalidArgumentException When the plan breaks its rules.
	 * @throws RuntimeException When the register cannot be reached or refuses the write.
	 */
	private function plan(SessionWrite $write, string $scheduledDate): array {
		$write->scheduled = $this->caseDates->parse(value: $scheduledDate, field: 'scheduledDate');
		$write->now = $this->timeFactory->now();
		$write->definition->rules()->assertInspectionFloor(scheduled: $write->scheduled, now: $write->now);
		$write->definition->rules()->assertInvitees(invitees: $write->invitees);

		return $this->openAsMeeting(write: $write, saved: $this->save(write: $write, record: $this->plannedRecord(write: $write)));
	}//end plan()

	/**
	 * Open a planned session as a meeting in the decision app, and keep the meeting's id on it.
	 *
	 * Without the decision app, or when it holds no meeting, the session is
	 * answered as saved: the plan stands either way.
	 *
	 * @param SessionWrite         $write The session that was planned.
	 * @param array<string, mixed> $saved The stored session.
	 *
	 * @return array<string, mixed> The stored session, with `meeting` when one was opened.
	 *
	 * @throws RuntimeException When the register refuses to keep the meeting's id.
	 *
	 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md#requirement-a-planned-session-opens-as-a-meeting-in-the-decision-app-req-css-6
	 */
	private function openAsMeeting(SessionWrite $write, array $saved): array {
		if ($this->meetings === null) {
			return $saved;
		}

		$schema    = $this->reads()->schemaKey(key: 'hearing_session_schema');
		$meetingId = $this->meetings->open(session: $saved, location: [$write->register, $schema]);
		$sessionId = (string)($saved['id'] ?? ($saved['@self']['id'] ?? ''));
		if ($meetingId === '' || $sessionId === '') {
			return $saved;
		}

		$session = new StoredSession();
		$session->objectService = $write->objectService;
		$session->register      = $write->register;
		$session->id            = $sessionId;
		$session->current       = $saved;
		$session->definition    = $write->definition;

		return $this->patch(session: $session, changes: ['meeting' => $meetingId]);
	}//end openAsMeeting()

	/**
	 * Record that a party waived a session the case type lets them waive.
	 *
	 * @param string               $caseId        The case.
	 * @param string               $sessionKey    The `sessions[]` entry.
	 * @param string               $chairpersonId Who records the waiver for the session.
	 * @param string               $reason        Why it was waived.
	 * @param array<string, mixed> $payload       Extra fields (invitees, ...).
	 *
	 * @return array<string, mixed> The stored session.
	 *
	 * @throws InvalidArgumentException When the session cannot be waived or the reason is empty.
	 * @throws RuntimeException When the register cannot be reached or refuses the write.
	 *
	 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md#requirement-a-party-waives-only-a-session-declared-waivable-req-css-4
	 */
	public function waive(
		string $caseId,
		string $sessionKey,
		string $chairpersonId,
		string $reason,
		array $payload = [],
	): array {
		$reason = $this->waiverReason(reason: $reason, named: [$caseId, $chairpersonId]);
		$write  = $this->waivableWrite(caseId: $caseId, sessionKey: $sessionKey);
		$write->chairpersonId = $chairpersonId;
		$write->payload = $payload;
		$write->now = $this->timeFactory->now();

		return $this->save(write: $write, record: $this->waivedRecord(write: $write, reason: $reason));
	}//end waive()

	/**
	 * The trimmed reason of a waiver, refused when it is empty or the case or its recorder is not named.
	 *
	 * @param string                $reason Why it was waived.
	 * @param array{string, string} $named  The case and who records the waiver.
	 *
	 * @return string The trimmed reason.
	 *
	 * @throws InvalidArgumentException When the reason, the case or the recorder is empty.
	 */
	private function waiverReason(string $reason, array $named): string {
		$reason = trim($reason);
		if ($reason === '') {
			throw new InvalidArgumentException('Say why the session is waived.');
		}

		if (trim($named[0]) === '' || trim($named[1]) === '') {
			throw new InvalidArgumentException('Name the case and who records the waiver.');
		}

		return $reason;
	}//end waiverReason()

	/**
	 * Where a waivable session of the case is stored, and which declared session it is.
	 *
	 * @param string $caseId     The case uuid.
	 * @param string $sessionKey The declared session's key, or '' for the only one.
	 *
	 * @return SessionWrite
	 *
	 * @throws InvalidArgumentException When the declared session cannot be waived.
	 * @throws RuntimeException When the case or its case type cannot be read.
	 */
	private function waivableWrite(string $caseId, string $sessionKey): SessionWrite {
		$write = $this->sessionWrite(caseId: $caseId, sessionKey: $sessionKey);
		if ($write->definition->waivable === false) {
			throw new InvalidArgumentException("{$write->definition->label} cannot be waived.");
		}

		return $write;
	}//end waivableWrite()

	/**
	 * Where a session of the case is stored, and which declared session it is.
	 *
	 * @param string $caseId     The case uuid.
	 * @param string $sessionKey The declared session's key, or '' for the only one.
	 *
	 * @return SessionWrite
	 *
	 * @throws RuntimeException When the case or its case type cannot be read.
	 */
	private function sessionWrite(string $caseId, string $sessionKey): SessionWrite {
		$write = new SessionWrite();
		$write->objectService = $this->reads()->objectService();
		$write->register = $this->settingsService->getConfigValue(key: 'register');
		$write->definition = $this->declared(
			definitions: $this->definitionsFor(location: [$write->objectService, $write->register], caseId: $caseId),
			sessionKey: $sessionKey
		);
		$write->caseId = $caseId;

		return $write;
	}//end sessionWrite()

	/**
	 * The planned session: its inspection window, its invitees stamped, and its planned status.
	 *
	 * @param SessionWrite $write The session being planned.
	 *
	 * @return array<string, mixed>
	 */
	private function plannedRecord(SessionWrite $write): array {
		$rules = $write->definition->rules();
		$deadline = $rules->inspectionDeadline(scheduled: $write->scheduled);
		$available = $write->now->setTime(0, 0, 0);
		if ($available > $deadline) {
			$available = $deadline;
		}

		return array_merge(
			$write->payload,
			$this->sessionHead(write: $write),
			[
				'scheduledDate'           => $write->scheduled->format(DateTimeInterface::ATOM),
				'chairperson'             => $write->chairpersonId,
				'invitees'                => $rules->stampInvitees(invitees: $write->invitees, when: $write->now),
				'inspectionAvailableFrom' => $available->format('Y-m-d'),
				'inspectionDeadline'      => $deadline->format('Y-m-d'),
				'status'                  => $write->definition->status(step: 'planned'),
				'hearingWaived'           => false,
				'recordingConsent'        => ($write->payload['recordingConsent'] ?? 'not_requested'),
			]
		);
	}//end plannedRecord()

	/**
	 * The waived session: held today on paper, with the reason it was waived.
	 *
	 * @param SessionWrite $write  The session being waived.
	 * @param string       $reason Why it is waived, trimmed.
	 *
	 * @return array<string, mixed>
	 */
	private function waivedRecord(SessionWrite $write, string $reason): array {
		return array_merge(
			$write->payload,
			$this->sessionHead(write: $write),
			[
				'scheduledDate'           => $write->now->format(DateTimeInterface::ATOM),
				'chairperson'             => $write->chairpersonId,
				'invitees'                => ($write->payload['invitees'] ?? []),
				'inspectionAvailableFrom' => $write->now->format('Y-m-d'),
				'inspectionDeadline'      => $write->now->format('Y-m-d'),
				'status'                  => $write->definition->status(step: 'waived'),
				'hearingWaived'           => true,
				'waiverReason'            => $reason,
			]
		);
	}//end waivedRecord()

	/**
	 * The fields every session record opens with: its case, its key and its title.
	 *
	 * @param SessionWrite $write The session being written.
	 *
	 * @return array<string, string>
	 */
	private function sessionHead(SessionWrite $write): array {
		return [
			'case'       => $write->caseId,
			'sessionKey' => $write->definition->key,
			'title'      => $write->definition->label,
		];
	}//end sessionHead()

	/**
	 * Add attendance entries to a session.
	 *
	 * Inside the session's grace window entries are added freely; after it,
	 * each entry needs a `correctionReason`, and none is written when one lacks
	 * it.
	 *
	 * @param string                           $sessionId The session.
	 * @param array<int, array<string, mixed>> $entries   Each {invitee, present, arrivalTime?, correctionReason?}.
	 *
	 * @return array<string, mixed> The updated session.
	 *
	 * @throws InvalidArgumentException When there are no entries or a late one has no reason.
	 * @throws RuntimeException When the session cannot be read or written.
	 *
	 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md#requirement-attendance-and-minutes-follow-the-session-definition-req-css-3
	 */
	public function recordAttendance(string $sessionId, array $entries): array {
		if ($entries === []) {
			throw new InvalidArgumentException('Give at least one attendance entry.');
		}

		$session = $this->loadSession(sessionId: $sessionId);

		$scheduled = $this->timeFactory->now();
		$stored    = ($session->current['scheduledDate'] ?? '');
		if (is_string($stored) === true && $stored !== '') {
			$scheduled = $this->caseDates->parse(value: $stored, field: 'scheduledDate');
		}

		$freezeAt = $session->definition->rules()->attendanceFreezesAt(scheduled: $scheduled);
		$session->definition->rules()->assertAttendance(entries: $entries, closed: $this->timeFactory->now() > $freezeAt);
		$merged = array_merge((array) ($session->current['attendance'] ?? []), $entries);

		return $this->patch(
			session: $session,
			changes: [
				'attendance'         => $merged,
				'attendanceFrozenAt' => $freezeAt->format(DateTimeInterface::ATOM),
			]
		);
	}//end recordAttendance()

	/**
	 * Close a session with its minutes.
	 *
	 * When the session requires minutes, a summary or a document must be
	 * given or already stored. When it requires recording consent, an audio
	 * recording is refused unless consent is granted.
	 *
	 * @param string               $sessionId The session.
	 * @param array<string, mixed> $payload   {minutesSummary?, minutesDocument?, audioRecording?, recordingConsent?}.
	 *
	 * @return array<string, mixed> The updated session.
	 *
	 * @throws InvalidArgumentException When minutes are missing, consent is absent, or the payload changes a stored denial.
	 * @throws RuntimeException When the session cannot be read or written.
	 *
	 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md#requirement-attendance-and-minutes-follow-the-session-definition-req-css-3
	 */
	public function addMinutes(string $sessionId, array $payload): array {
		$session = $this->loadSession(sessionId: $sessionId);
		$this->assertDenialKept(session: $session, payload: $payload);

		$summary  = $this->field(primary: $payload, fallback: $session->current, key: 'minutesSummary');
		$document = $this->field(primary: $payload, fallback: $session->current, key: 'minutesDocument');
		$session->definition->rules()->assertMinutes(
			summary: $summary,
			document: $document,
			audio: $this->field(primary: $payload, fallback: [], key: 'audioRecording'),
			consent: $this->field(primary: $payload, fallback: $session->current, key: 'recordingConsent')
		);

		$changes = [
			'minutesSummary'  => $this->nullIfEmpty(value: $summary),
			'minutesDocument' => $this->nullIfEmpty(value: $document),
			'status'          => $session->definition->status(step: 'executed'),
		];

		return $this->patch(session: $session, changes: $this->withRecordingFields(changes: $changes, payload: $payload));
	}//end addMinutes()

	/**
	 * Refuse a payload that changes a stored recording-consent denial.
	 *
	 * @param StoredSession        $session The stored session and its definition.
	 * @param array<string, mixed> $payload The minutes payload.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the payload changes a stored denial.
	 *
	 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md#requirement-attendance-and-minutes-follow-the-session-definition-req-css-3
	 */
	private function assertDenialKept(StoredSession $session, array $payload): void {
		$session->definition->rules()->assertDenialKept(
			stored: (string)($session->current['recordingConsent'] ?? ''),
			asked: (string)($payload['recordingConsent'] ?? 'denied')
		);
	}//end assertDenialKept()

	/**
	 * The changes that close the session with its minutes, with the recording fields the payload names.
	 *
	 * @param array<string, mixed> $changes The minutes and the executed status.
	 * @param array<string, mixed> $payload The minutes payload.
	 *
	 * @return array<string, mixed> The changes.
	 *
	 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md#requirement-attendance-and-minutes-follow-the-session-definition-req-css-3
	 */
	private function withRecordingFields(array $changes, array $payload): array {
		foreach (['audioRecording', 'recordingConsent'] as $key) {
			if (array_key_exists($key, $payload) === true) {
				$changes[$key] = $payload[$key];
			}
		}

		return $changes;
	}//end withRecordingFields()

	/**
	 * The session, its register context and its definition.
	 *
	 * A session without a `sessionKey` (stored before sessions were declared)
	 * takes the case type's only session; with several declared it is refused,
	 * because which rules apply cannot be told.
	 *
	 * @param string $sessionId The session.
	 *
	 * @return StoredSession The session, where it is stored and its definition.
	 *
	 * @throws InvalidArgumentException When the session's definition cannot be told.
	 * @throws RuntimeException When the session or its case cannot be read.
	 */
	private function loadSession(string $sessionId): StoredSession {
		$session = new StoredSession();
		$session->objectService = $this->reads()->objectService();
		$session->register      = $this->settingsService->getConfigValue(key: 'register');
		$session->id            = $sessionId;
		$current = $this->findObjectAsArray(
			objectService: $session->objectService,
			register: $session->register,
			schema: $this->reads()->schemaKey(key: 'hearing_session_schema'),
			id: $sessionId
		);
		if ($current === null) {
			throw new RuntimeException('Session not found');
		}

		$session->current = $current;
		$definitions = $this->definitionsFor(
			location: [$session->objectService, $session->register],
			caseId: CaseRegisterReads::uuidOf(value: ($current['case'] ?? ''))
		);

		$key = ($current['sessionKey'] ?? '');
		if (is_string($key) === true && $key !== '') {
			$session->definition = $this->declared(definitions: $definitions, sessionKey: $key);
			return $session;
		}

		if (count($definitions) !== 1) {
			throw new InvalidArgumentException('This session names no session of its case type, and the case type does not declare exactly one.');
		}

		$session->definition = reset($definitions);
		return $session;
	}//end loadSession()

	/**
	 * The definition of a declared session.
	 *
	 * @param array<string, SessionDefinition> $definitions The declared sessions.
	 * @param string                           $sessionKey  The key asked for.
	 *
	 * @return SessionDefinition The definition.
	 *
	 * @throws InvalidArgumentException When the case type declares no such session.
	 */
	private function declared(array $definitions, string $sessionKey): SessionDefinition {
		if (isset($definitions[$sessionKey]) === false) {
			throw new InvalidArgumentException("This case type declares no session '$sessionKey'.");
		}

		return $definitions[$sessionKey];
	}//end declared()

	/**
	 * The sessions declared by the case type of a case.
	 *
	 * @param array{0: object, 1: string} $location The object service and the register.
	 * @param string                      $caseId   The case uuid.
	 *
	 * @return array<string, SessionDefinition> The sessions, keyed by key.
	 *
	 * @throws RuntimeException When the case or its case type cannot be read.
	 */
	private function definitionsFor(array $location, string $caseId): array {
		if ($caseId === '') {
			throw new RuntimeException('Case not found');
		}

		$caseType = $this->reads()->caseTypeOf(location: $location, caseId: $caseId);
		if ($caseType === null) {
			return [];
		}

		return (new SessionDefinitionReader())->allOf(caseType: $caseType);
	}//end definitionsFor()

	/**
	 * The case and schema reads this service starts from.
	 *
	 * @return CaseRegisterReads
	 */
	private function reads(): CaseRegisterReads {
		return new CaseRegisterReads(settingsService: $this->settingsService);
	}//end reads()

	/**
	 * Save a new session.
	 *
	 * It opens in the schema's initial status, and moves to the status the
	 * record names through the schema's declared transitions.
	 *
	 * @param SessionWrite         $write  The object service and register to write through.
	 * @param array<string, mixed> $record The session.
	 *
	 * @return array<string, mixed> The stored session.
	 *
	 * @throws RuntimeException When the register refuses the write.
	 */
	private function save(SessionWrite $write, array $record): array {
		try {
			return $this->createThenMove(
				objectService: $write->objectService,
				write: [
					'register' => $write->register,
					'schema'   => $this->reads()->schemaKey(key: 'hearing_session_schema'),
					'object'   => $record,
					'field'    => 'status',
				]
			);
		} catch (Throwable $e) {
			throw new RuntimeException('The session could not be saved: ' . $e->getMessage(), 0, $e);
		}
	}//end save()

	/**
	 * Change fields of a stored session.
	 *
	 * @param StoredSession        $session The stored session and where it is stored.
	 * @param array<string, mixed> $changes The fields to change.
	 *
	 * @return array<string, mixed> The updated session.
	 *
	 * @throws RuntimeException When the register refuses the write.
	 */
	private function patch(StoredSession $session, array $changes): array {
		try {
			return ($this->patchObjectAsArray(
				objectService: $session->objectService,
				register: $session->register,
				schema: $this->reads()->schemaKey(key: 'hearing_session_schema'),
				id: $session->id,
				changes: $changes
			) ?? array_merge($session->current, $changes));
		} catch (Throwable $e) {
			throw new RuntimeException('The session could not be updated: ' . $e->getMessage(), 0, $e);
		}
	}//end patch()

	/**
	 * A string, or null when it is empty.
	 *
	 * @param string $value The value.
	 *
	 * @return string|null The value, or null.
	 */
	private function nullIfEmpty(string $value): ?string {
		if ($value === '') {
			return null;
		}

		return $value;
	}//end nullIfEmpty()

	/**
	 * A trimmed string field from the payload, else from the stored record.
	 *
	 * @param array<string, mixed> $primary  The payload.
	 * @param array<string, mixed> $fallback The stored record.
	 * @param string               $key      The field.
	 *
	 * @return string The value, or ''.
	 */
	private function field(array $primary, array $fallback, string $key): string {
		$value = ($primary[$key] ?? ($fallback[$key] ?? ''));
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end field()

}//end class
