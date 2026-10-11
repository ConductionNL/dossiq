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
		$objectService = $this->objectService();
		$register      = $this->settingsService->getConfigValue(key: 'register');
		$definitions   = $this->definitionsFor(objectService: $objectService, register: $register, caseId: $caseId);

		return [
			'definitions' => array_values(array_map(static fn (SessionDefinition $def): array => $def->toArray(), $definitions)),
			'sessions'    => $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $this->schemaKey(key: 'hearing_session_schema'),
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

		$objectService = $this->objectService();
		$register      = $this->settingsService->getConfigValue(key: 'register');
		$definition    = $this->declared(
			definitions: $this->definitionsFor(objectService: $objectService, register: $register, caseId: $caseId),
			sessionKey: $sessionKey
		);

		$scheduled = $this->caseDates->parse(value: $scheduledDate, field: 'scheduledDate');
		$now       = $this->timeFactory->now();
		$definition->rules()->assertInspectionFloor(scheduled: $scheduled, now: $now);
		$definition->rules()->assertInvitees(invitees: $invitees);

		$deadline  = $definition->rules()->inspectionDeadline(scheduled: $scheduled);
		$available = $now->setTime(0, 0, 0);
		if ($available > $deadline) {
			$available = $deadline;
		}

		$record = array_merge(
			$payload,
			[
				'case'                    => $caseId,
				'sessionKey'              => $definition->key,
				'title'                   => $definition->label,
				'scheduledDate'           => $scheduled->format(DateTimeInterface::ATOM),
				'chairperson'             => $chairpersonId,
				'invitees'                => $definition->rules()->stampInvitees(invitees: $invitees, when: $now),
				'inspectionAvailableFrom' => $available->format('Y-m-d'),
				'inspectionDeadline'      => $deadline->format('Y-m-d'),
				'status'                  => $definition->status(step: 'planned'),
				'hearingWaived'           => false,
				'recordingConsent'        => ($payload['recordingConsent'] ?? 'not_requested'),
			]
		);

		return $this->save(objectService: $objectService, register: $register, record: $record);
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

		$objectService = $this->objectService();
		$register      = $this->settingsService->getConfigValue(key: 'register');
		$definition    = $this->declared(
			definitions: $this->definitionsFor(objectService: $objectService, register: $register, caseId: $caseId),
			sessionKey: $sessionKey
		);
		if ($definition->waivable === false) {
			throw new InvalidArgumentException("{$definition->label} cannot be waived.");
		}

		$now    = $this->timeFactory->now();
		$record = array_merge(
			$payload,
			[
				'case'                    => $caseId,
				'sessionKey'              => $definition->key,
				'title'                   => $definition->label,
				'scheduledDate'           => $now->format(DateTimeInterface::ATOM),
				'chairperson'             => $chairpersonId,
				'invitees'                => ($payload['invitees'] ?? []),
				'inspectionAvailableFrom' => $now->format('Y-m-d'),
				'inspectionDeadline'      => $now->format('Y-m-d'),
				'status'                  => $definition->status(step: 'waived'),
				'hearingWaived'           => true,
				'waiverReason'            => $reason,
			]
		);

		return $this->save(objectService: $objectService, register: $register, record: $record);
	}//end waive()

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
	 * @throws InvalidArgumentException When minutes are missing or consent is absent.
	 * @throws RuntimeException When the session cannot be read or written.
	 *
	 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md#requirement-attendance-and-minutes-follow-the-session-definition-req-css-3
	 */
	public function addMinutes(string $sessionId, array $payload): array {
		[$objectService, $register, $current, $definition] = $this->loadSession(sessionId: $sessionId);

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
		$objectService = $this->objectService();
		$register      = $this->settingsService->getConfigValue(key: 'register');
		$current       = $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $this->schemaKey(key: 'hearing_session_schema'),
			id: $sessionId
		);
		if ($current === null) {
			throw new RuntimeException('Session not found');
		}

		$definitions = $this->definitionsFor(
			objectService: $objectService,
			register: $register,
			caseId: $this->uuidOf(value: ($current['case'] ?? ''))
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
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SessionDefinition is built through its validating named constructor.
	 */
	private function definitionsFor(object $objectService, string $register, string $caseId): array {
		$case = null;
		if ($caseId !== '') {
			$case = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $this->schemaKey(key: 'case_schema'),
				id: $caseId
			);
		}

		if ($case === null) {
			throw new RuntimeException('Case not found');
		}

		$caseTypeId = $this->uuidOf(value: ($case['caseType'] ?? ''));
		if ($caseTypeId === '') {
			return [];
		}

		$caseType = $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $this->schemaKey(key: 'case_type_schema'),
			id: $caseTypeId
		);
		if ($caseType === null) {
			throw new RuntimeException('Case type not found');
		}

		return SessionDefinition::allOf(caseType: $caseType);
	}//end definitionsFor()

	/**
	 * Save a new session.
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
			return ($this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $this->schemaKey(key: 'hearing_session_schema'),
				object: $record
			) ?? $record);
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
				schema: $this->schemaKey(key: 'hearing_session_schema'),
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

	/**
	 * The OpenRegister object service.
	 *
	 * @return object The object service.
	 *
	 * @throws RuntimeException When OpenRegister is not available.
	 */
	private function objectService(): object {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		return $objectService;
	}//end objectService()

	/**
	 * A configured schema id.
	 *
	 * @param string $key The config key.
	 *
	 * @return string The schema id.
	 *
	 * @throws RuntimeException When it is not configured.
	 */
	private function schemaKey(string $key): string {
		$schema = $this->settingsService->getConfigValue(key: $key);
		if ($schema === '') {
			throw new RuntimeException("The schema '$key' is not configured.");
		}

		return $schema;
	}//end schemaKey()

	/**
	 * The uuid of a reference, whether stored as a string or an object.
	 *
	 * @param mixed $value The reference.
	 *
	 * @return string The uuid, or ''.
	 */
	private function uuidOf(mixed $value): string {
		if (is_string($value) === true) {
			return trim($value);
		}

		if (is_array($value) === true) {
			foreach (['id', 'uuid'] as $key) {
				if (is_string($value[$key] ?? null) === true) {
					return trim($value[$key]);
				}
			}
		}

		return '';
	}//end uuidOf()
}//end class
