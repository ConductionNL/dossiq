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
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ITimeFactory $timeFactory,
		private readonly CaseDateNormaliser $caseDates,
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
		$definitions   = $this->definitionsFor(objectService: $objectService, register: $register, caseId: $caseId);

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
		$write->scheduled = $this->caseDates->parse(value: $scheduledDate, field: 'scheduledDate');
		$write->now = $this->timeFactory->now();
		$write->definition->rules()->assertInspectionFloor(scheduled: $write->scheduled, now: $write->now);
		$write->definition->rules()->assertInvitees(invitees: $invitees);

		return $this->save(objectService: $write->objectService, register: $write->register, record: $this->plannedRecord(write: $write));
	}//end schedule()

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
		$reason = trim($reason);
		if ($reason === '') {
			throw new InvalidArgumentException('Say why the session is waived.');
		}

		if (trim($caseId) === '' || trim($chairpersonId) === '') {
			throw new InvalidArgumentException('Name the case and who records the waiver.');
		}

		$write = $this->sessionWrite(caseId: $caseId, sessionKey: $sessionKey);
		if ($write->definition->waivable === false) {
			throw new InvalidArgumentException("{$write->definition->label} cannot be waived.");
		}

		$write->chairpersonId = $chairpersonId;
		$write->payload = $payload;
		$write->now = $this->timeFactory->now();

		return $this->save(objectService: $write->objectService, register: $write->register, record: $this->waivedRecord(write: $write, reason: $reason));
	}//end waive()

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
			definitions: $this->definitionsFor(objectService: $write->objectService, register: $write->register, caseId: $caseId),
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

		[$objectService, $register, $current, $definition] = $this->loadSession(sessionId: $sessionId);

		$scheduled = $this->timeFactory->now();
		$stored    = ($current['scheduledDate'] ?? '');
		if (is_string($stored) === true && $stored !== '') {
			$scheduled = $this->caseDates->parse(value: $stored, field: 'scheduledDate');
		}

		$freezeAt = $definition->rules()->attendanceFreezesAt(scheduled: $scheduled);
		$definition->rules()->assertAttendance(entries: $entries, closed: $this->timeFactory->now() > $freezeAt);
		$merged = array_merge((array) ($current['attendance'] ?? []), $entries);

		return $this->patch(
			objectService: $objectService,
			register: $register,
			sessionId: $sessionId,
			current: $current,
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
		[$objectService, $register, $current, $definition] = $this->loadSession(sessionId: $sessionId);
		$definition->rules()->assertDenialKept(
			stored: (string)($current['recordingConsent'] ?? ''),
			asked: (string)($payload['recordingConsent'] ?? 'denied')
		);

		$summary  = $this->field(primary: $payload, fallback: $current, key: 'minutesSummary');
		$document = $this->field(primary: $payload, fallback: $current, key: 'minutesDocument');
		$definition->rules()->assertMinutes(
			summary: $summary,
			document: $document,
			audio: $this->field(primary: $payload, fallback: [], key: 'audioRecording'),
			consent: $this->field(primary: $payload, fallback: $current, key: 'recordingConsent')
		);

		$changes = [
			'minutesSummary'  => $this->nullIfEmpty(value: $summary),
			'minutesDocument' => $this->nullIfEmpty(value: $document),
			'status'          => $definition->status(step: 'executed'),
		];
		foreach (['audioRecording', 'recordingConsent'] as $key) {
			if (array_key_exists($key, $payload) === true) {
				$changes[$key] = $payload[$key];
			}
		}

		return $this->patch(
			objectService: $objectService,
			register: $register,
			sessionId: $sessionId,
			current: $current,
			changes: $changes
		);
	}//end addMinutes()

	/**
	 * The session, its register context and its definition.
	 *
	 * A session without a `sessionKey` (stored before sessions were declared)
	 * takes the case type's only session; with several declared it is refused,
	 * because which rules apply cannot be told.
	 *
	 * @param string $sessionId The session.
	 *
	 * @return array{0: object, 1: string, 2: array<string, mixed>, 3: SessionDefinition} The context.
	 *
	 * @throws InvalidArgumentException When the session's definition cannot be told.
	 * @throws RuntimeException When the session or its case cannot be read.
	 */
	private function loadSession(string $sessionId): array {
		$objectService = $this->reads()->objectService();
		$register      = $this->settingsService->getConfigValue(key: 'register');
		$current       = $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $this->reads()->schemaKey(key: 'hearing_session_schema'),
			id: $sessionId
		);
		if ($current === null) {
			throw new RuntimeException('Session not found');
		}

		$definitions = $this->definitionsFor(
			objectService: $objectService,
			register: $register,
			caseId: CaseRegisterReads::uuidOf(value: ($current['case'] ?? ''))
		);

		$key = ($current['sessionKey'] ?? '');
		if (is_string($key) === true && $key !== '') {
			return [$objectService, $register, $current, $this->declared(definitions: $definitions, sessionKey: $key)];
		}

		if (count($definitions) !== 1) {
			throw new InvalidArgumentException('This session names no session of its case type, and the case type does not declare exactly one.');
		}

		return [$objectService, $register, $current, reset($definitions)];
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
	 * @param object $objectService The object service.
	 * @param string $register      The register.
	 * @param string $caseId        The case uuid.
	 *
	 * @return array<string, SessionDefinition> The sessions, keyed by key.
	 *
	 * @throws RuntimeException When the case or its case type cannot be read.
	 */
	private function definitionsFor(object $objectService, string $register, string $caseId): array {
		if ($caseId === '') {
			throw new RuntimeException('Case not found');
		}

		$caseType = $this->reads()->caseTypeOf(location: [$objectService, $register], caseId: $caseId);
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
	 * @param object               $objectService The object service.
	 * @param string               $register      The register.
	 * @param array<string, mixed> $record        The session.
	 *
	 * @return array<string, mixed> The stored session.
	 *
	 * @throws RuntimeException When the register refuses the write.
	 */
	private function save(object $objectService, string $register, array $record): array {
		try {
			return $this->createThenMove(
				objectService: $objectService,
				write: ['register' => $register, 'schema' => $this->reads()->schemaKey(key: 'hearing_session_schema'), 'object' => $record, 'field' => 'status']
			);
		} catch (Throwable $e) {
			throw new RuntimeException('The session could not be saved: ' . $e->getMessage(), 0, $e);
		}
	}//end save()

	/**
	 * Change fields of a stored session.
	 *
	 * @param object               $objectService The object service.
	 * @param string               $register      The register.
	 * @param string               $sessionId     The session.
	 * @param array<string, mixed> $current       The stored session.
	 * @param array<string, mixed> $changes       The fields to change.
	 *
	 * @return array<string, mixed> The updated session.
	 *
	 * @throws RuntimeException When the register refuses the write.
	 */
	private function patch(
		object $objectService,
		string $register,
		string $sessionId,
		array $current,
		array $changes,
	): array {
		try {
			return ($this->patchObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $this->reads()->schemaKey(key: 'hearing_session_schema'),
				id: $sessionId,
				changes: $changes
			) ?? array_merge($current, $changes));
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
