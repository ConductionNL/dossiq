<?php

/**
 * Dossiq Bezwaar Hearing Service.
 *
 * Domain service for the bezwaar-hearing (hoorzitting) capability under
 * Awb Art. 7:2 – 7:7. Owns the legitimate domain operations that cannot
 * be expressed by the manifest-driven CRUD path:
 *
 *  - schedule()           — Create a gepland hearingSession with the
 *                           Awb art. 7:4 lid 2 inspection-of-file floor
 *                           (≥ 7 days before scheduledDate) and an
 *                           awb-art-7:2 audit entry.
 *  - waive()              — Record an art. 7:3 waiver (afzien van het
 *                           hoorrecht) with a non-empty reason and an
 *                           awb-art-7:3 audit entry.
 *  - recordAttendance()   — Append-only attendance capture with a
 *                           one-hour grace window after the hearing
 *                           concludes; later corrections require a
 *                           documented correctionReason and write an
 *                           awb-art-7:7 audit entry.
 *  - addMinutes()         — Promote the session to uitgevoerd when
 *                           minutesSummary or minutesDocument is set;
 *                           audioRecording is only accepted when
 *                           recordingConsent = granted (AVG art. 6).
 *
 * Identity is ALWAYS derived from IUserSession. Per the per-app
 * convention every mutation goes through OpenRegister via the manifest
 * renderer; this service composes those calls and writes the
 * entries on OpenRegister's audit trail of the session, tagged with the applicable Awb
 * article so that beroep dossier export can demonstrate compliance.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Bezwaar
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Bezwaar;

use OCA\Dossiq\Service\Hearing\HearingPlan;
use OCA\Dossiq\Service\Hearing\NewSession;
use OCA\Dossiq\Service\Hearing\SessionPatch;
use OCA\Dossiq\Service\Hearing\SessionStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\OwningCaseResolver;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Hearing service: scheduling, waiver, attendance and minutes capture.
 *
 * @spec openspec/specs/bezwaar-hearing/spec.md
 */
class HearingService {

	use SearchesObjects;

	/**
	 * The invitee a seeded default hearing is created with.
	 */
	private const SEED_INVITEES = [
		[
			'role' => 'bezwaarmaker',
			'channel' => 'email',
			'accessibilityNeeds' => [],
		],
	];

	/**
	 * Audit-tag catalogue covering the legally relevant events on a
	 * hearingSession (REQ-BH-8). Values are the canonical tags every
	 * downstream consumer (beroep export, accessibility report) reads.
	 * They are declared once on {@see BezwaarAuditTrail} — the writer
	 * that stamps them — and re-exported here for backwards
	 * compatibility with existing consumers of `HearingService::TAG_*`.
	 */
	public const TAG_SCHEDULED = BezwaarAuditTrail::TAG_SCHEDULED;
	public const TAG_INVITATION_SENT = BezwaarAuditTrail::TAG_INVITATION_SENT;
	public const TAG_WAIVER = BezwaarAuditTrail::TAG_WAIVER;
	public const TAG_INSPECTION = BezwaarAuditTrail::TAG_INSPECTION;
	public const TAG_CONFIDENTIAL_WITHELD = BezwaarAuditTrail::TAG_CONFIDENTIAL_WITHELD;
	public const TAG_VERSLAG = BezwaarAuditTrail::TAG_VERSLAG;
	public const TAG_BAC_REFERRAL = BezwaarAuditTrail::TAG_BAC_REFERRAL;
	public const TAG_RECORDING_CONSENT = BezwaarAuditTrail::TAG_RECORDING_CONSENT;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Schema/register bridge
	 * @param LoggerInterface $logger Logger
	 * @param BezwaarAuditTrail $auditTrail Writes each entry onto OpenRegister's audit trail
	 * @param HearingSchedulePlanner $planner Awb art. 7:4 date arithmetic
	 * @param HearingMinutesRecorder $minutes Awb art. 7:7 verslag assembly + consent gate
	 * @param OwningCaseResolver $owningCase Resolves a session's parent bezwaar case
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly BezwaarAuditTrail $auditTrail,
		private readonly HearingSchedulePlanner $planner,
		private readonly HearingMinutesRecorder $minutes,
		private readonly OwningCaseResolver $owningCase,
	) {
	}//end __construct()

	/**
	 * Schedule a hearing for a bezwaar case (REQ-BH-2 happy path).
	 *
	 * Creates a hearingSession with status = gepland, computes the
	 * art. 7:4 inspection deadline as scheduledDate − 7 days, and
	 * appends an awb-art-7:2 audit entry to the new session.
	 *
	 * @param string $caseId UUID of the parent bezwaar case
	 * @param string $scheduledDate ISO-8601 date-time of the hearing
	 * @param string $chairpersonId UUID of the voorzitter role
	 * @param array<int, array> $invitees Invitee objects (see schema)
	 * @param array<string, mixed> $payload Optional extras (location, videoCallUrl, members, inspectionAvailableFrom, ...)
	 *
	 * @return array<string, mixed> The persisted hearingSession record
	 *
	 * @throws RuntimeException When OpenRegister is unavailable, the
	 *                          inspection-of-file floor is violated, or
	 *                          schemas are unconfigured.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function schedule(
		string $caseId,
		string $scheduledDate,
		string $chairpersonId,
		array $invitees,
		array $payload = [],
	): array {
		$store = $this->configuredStore();

		if ($caseId === '' || $chairpersonId === '' || $invitees === []) {
			throw new RuntimeException(
				'case, chairperson and at least one invitee are required'
			);
		}

		$record = $this->planner->scheduledRecord(
			plan: new HearingPlan(caseId: $caseId, scheduledDate: $scheduledDate, chairpersonId: $chairpersonId, invitees: $invitees, payload: $payload)
		);

		// Awb art. 7:2. The entry is a row on the session's own OpenRegister
		// trail; a hearing whose entry cannot be written is deleted again.
		return $this->createSession(store: $store, session: $this->scheduledSession(record: $record));
	}//end schedule()

	/**
	 * Record an art. 7:3 waiver: bezwaarmaker afziet van het hoorrecht.
	 *
	 * Creates a hearingSession with status = afzien, hearingWaived = true
	 * and the supplied reason. Writes an awb-art-7:3 audit entry.
	 *
	 * @param string $caseId UUID of the parent bezwaar case
	 * @param string $reason Non-empty waiver reason
	 * @param array<string, mixed> $payload Optional extras
	 *
	 * @return array<string, mixed> The persisted hearingSession record
	 *
	 * @throws RuntimeException When the reason is empty or persistence fails.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function waive(
		string $caseId,
		string $reason,
		array $payload = [],
	): array {
		$reason = trim($reason);
		if ($reason === '') {
			throw new RuntimeException(
				'Waiver reason is required (Awb art. 7:3)'
			);
		}

		$store = $this->configuredStore();

		// Awb art. 7:3. A waiver whose entry cannot be written is deleted again.
		return $this->createSession(
			store: $store,
			session: new NewSession(
				record: array_merge($payload, ['case' => $caseId] + $this->planner->waiverFields(reason: $reason, payload: $payload)),
				action: 'record waiver',
				event: 'hearing-waived',
				auditPayload: [
					'case' => $caseId,
					'reason' => $reason,
				],
				tag: self::TAG_WAIVER,
			)
		);
	}//end waive()

	/**
	 * Resolve the bezwaar case a hearing session belongs to.
	 *
	 * `recordAttendance()` carries only a session id, so there is nothing in
	 * its signature to authorise against. This resolves the parent case so the
	 * controller can apply the ordinary per-case guard. Returns null — which
	 * the caller treats as DENY — whenever the session cannot be resolved, so
	 * an unknown id is not an existence oracle.
	 *
	 * @param string $sessionId UUID of the hearingSession
	 *
	 * @return string|null The parent case UUID, or null when unresolvable.
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function getCaseIdForSession(string $sessionId): ?string {
		return $this->owningCase->resolve(
			objectId: $sessionId,
			schemaKey: 'hearing_session_schema',
			caseField: 'case',
		);
	}//end getCaseIdForSession()

	/**
	 * Record attendance on a hearingSession (REQ-BH-5).
	 *
	 * Within the one-hour grace window after the hearing concludes
	 * attendance entries SHALL be appendable freely; after the window
	 * any correction SHALL carry a non-empty correctionReason that is
	 * logged as an awb-art-7:7 audit entry.
	 *
	 * @param string $sessionId UUID of the hearingSession
	 * @param array<int, array<string, mixed>> $entries Attendance entries: each {invitee, present, arrivalTime?, correctionReason?}
	 *
	 * @return array<string, mixed> The updated hearingSession record
	 *
	 * @throws RuntimeException When the session is not found, persistence fails, or a late correction lacks a reason.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function recordAttendance(
		string $sessionId,
		array $entries,
	): array {
		if ($entries === []) {
			throw new RuntimeException(
				'At least one attendance entry is required'
			);
		}

		$store = $this->openStore();
		$current = $this->requireSession(store: $store, sessionId: $sessionId);

		$freeze = $this->planner->attendanceFreeze(current: $current);

		$merged = (array)($current['attendance'] ?? []);
		foreach ($entries as $entry) {
			$merged[] = $entry;
		}

		return $this->writeAttendance(
			store: $store,
			patch: new SessionPatch(
				sessionId: $sessionId,
				current: $current,
				update: [
					'attendance' => $merged,
					'attendanceFrozenAt' => $freeze['frozenAt'],
				],
				entries: $this->minutes->lateCorrections(entries: $entries, isFrozen: $freeze['isFrozen']),
			)
		);
	}//end recordAttendance()

	/**
	 * Attach minutes (verslag) to a hearingSession and promote it to
	 * uitgevoerd when at least one of minutesSummary or minutesDocument
	 * is provided (REQ-BH-6). When audioRecording is supplied it SHALL
	 * only be accepted if recordingConsent = granted.
	 *
	 * @param string $sessionId UUID of the hearingSession
	 * @param array<string, mixed> $payload Minutes payload {minutesSummary?, minutesDocument?, audioRecording?, recordingConsent?}
	 *
	 * @return array<string, mixed> The updated hearingSession record
	 *
	 * @throws RuntimeException When verslag is missing, audio consent is
	 *                          denied, or persistence fails.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function addMinutes(
		string $sessionId,
		array $payload,
	): array {
		$store = $this->openStore();
		$current = $this->requireSession(store: $store, sessionId: $sessionId);
		[$summary, $document] = $this->minutes->minutesTexts(payload: $payload, current: $current);

		// Audio recording handling: gated by explicit consent (AVG art. 6).
		$this->minutes->guardRecordingConsent(
			sessionId: $sessionId,
			payload: $payload,
			current: $current,
			register: $store->register,
			schema: $store->schema,
		);

		return $this->recordMinutes(
			store: $store,
			patch: new SessionPatch(
				sessionId: $sessionId,
				current: $current,
				update: $this->minutes->buildMinutesUpdate(payload: $payload, summary: $summary, document: $document),
				entries: [
					[
						'hasSummary' => trim($summary) !== '',
						'hasDocument' => trim($document) !== '',
					],
				],
			)
		);
	}//end addMinutes()

	/**
	 * Listener entry-point: seed a default hearing session for a bezwaar
	 * that has just transitioned to "Hearing planned" (REQ-BH-2
	 * scheduling). The seed is intentionally minimal — invitees are
	 * empty, scheduledDate is fourteen days out — and only fires when
	 * no hearing session already exists for the case.
	 *
	 * @param string $objectionId The bezwaar (lifecycle) UUID
	 *
	 * @return array<string, mixed>|null Created hearing session, or null when one already exists / infra unavailable.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function seedDefaultHearing(string $objectionId): ?array {
		$store = $this->findConfiguredStore();
		if ($store === null) {
			return null;
		}

		$caseId = $this->seedCaseId(store: $store, objectionId: $objectionId);
		if ($caseId === '') {
			return null;
		}

		try {
			return $this->schedule(
				caseId: $caseId,
				scheduledDate: $this->planner->seedDate(),
				chairpersonId: 'system',
				invitees: self::SEED_INVITEES,
			);
		} catch (\Throwable $e) {
			$this->logger->info(
				'Dossiq hearing: seedDefaultHearing skipped for bezwaar '
				. $objectionId . ': ' . $e->getMessage()
			);
			return null;
		}
	}//end seedDefaultHearing()

	/**
	 * The object service with the register and hearing session schema, or
	 * null when OpenRegister is not available.
	 *
	 * @return SessionStore|null
	 */
	private function findStore(): ?SessionStore {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		return new SessionStore(
			objectService: $objectService,
			register: $this->settingsService->getConfigValue(key: 'register'),
			schema: $this->settingsService->getConfigValue(key: 'hearing_session_schema'),
		);
	}//end findStore()

	/**
	 * The session store, or null when OpenRegister is not available or the
	 * register or schema is not configured.
	 *
	 * @return SessionStore|null
	 */
	private function findConfiguredStore(): ?SessionStore {
		$store = $this->findStore();
		if ($store === null || $store->isConfigured() === false) {
			return null;
		}

		return $store;
	}//end findConfiguredStore()

	/**
	 * The session store; OpenRegister must be available.
	 *
	 * @return SessionStore
	 *
	 * @throws RuntimeException When OpenRegister is not available.
	 */
	private function openStore(): SessionStore {
		$store = $this->findStore();
		if ($store === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		return $store;
	}//end openStore()

	/**
	 * The session store; OpenRegister must be available and the register
	 * and schema configured.
	 *
	 * @return SessionStore
	 *
	 * @throws RuntimeException When OpenRegister is not available or the schema is not configured.
	 */
	private function configuredStore(): SessionStore {
		$store = $this->openStore();
		if ($store->isConfigured() === false) {
			throw new RuntimeException('Hearing schema is not configured');
		}

		return $store;
	}//end configuredStore()

	/**
	 * The stored session.
	 *
	 * @param SessionStore $store     The session store.
	 * @param string       $sessionId UUID of the session.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException When the session is not found.
	 */
	private function requireSession(SessionStore $store, string $sessionId): array {
		$current = $this->findObjectAsArray(
			objectService: $store->objectService,
			register: $store->register,
			schema: $store->schema,
			id: $sessionId
		);
		if ($current === null) {
			throw new RuntimeException('Hearing session not found');
		}

		return $current;
	}//end requireSession()

	/**
	 * The new session and audit entry of a scheduled hearing.
	 *
	 * @param array<string, mixed> $record The scheduled record.
	 *
	 * @return NewSession
	 */
	private function scheduledSession(array $record): NewSession {
		return new NewSession(
			record: $record,
			action: 'schedule hearing',
			event: 'hearing-scheduled',
			auditPayload: [
				'case' => $record['case'],
				'scheduledDate' => $record['scheduledDate'],
				'inspectionDeadline' => $record['inspectionDeadline'],
			],
			tag: self::TAG_SCHEDULED,
		);
	}//end scheduledSession()

	/**
	 * Save a new session, then write its audit entry.
	 *
	 * @param SessionStore $store   The session store.
	 * @param NewSession   $session The record and its entry.
	 *
	 * @return array<string, mixed> The saved record, or the record itself when the store answers nothing.
	 *
	 * @throws RuntimeException When saving or the entry fails.
	 */
	private function createSession(SessionStore $store, NewSession $session): array {
		try {
			$saved = ($this->saveObjectAsArray(
				objectService: $store->objectService,
				register: $store->register,
				schema: $store->schema,
				object: $session->record
			) ?? $session->record);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq hearing: failed to ' . $session->action . ': ' . $e->getMessage()
			);
			throw new RuntimeException('Could not ' . $session->action);
		}

		$this->auditTrail->recordForNewRecord(
			objectService: $store->objectService,
			register: $store->register,
			schema: $store->schema,
			objectUuid: $this->idOf(record: $saved),
			event: $session->event,
			payload: $session->auditPayload,
			tag: $session->tag,
		);

		return $saved;
	}//end createSession()

	/**
	 * Record each late correction, then write the attendance.
	 *
	 * Awb art. 7:7. Each late correction is recorded before the attendance
	 * changes; a correction whose entry cannot be written changes nothing. A
	 * patch that fails after the entries leaves an
	 * `attendance-late-correction-not-applied` row per correction (REQ-BAT-003).
	 *
	 * @param SessionStore $store The session store.
	 * @param SessionPatch $patch The attendance patch; its entries are the late corrections.
	 *
	 * @return array<string, mixed> The updated session.
	 *
	 * @throws RuntimeException When an entry or the patch fails.
	 */
	private function writeAttendance(SessionStore $store, SessionPatch $patch): array {
		foreach ($patch->entries as $correction) {
			$this->auditTrail->record(
				register: $store->register,
				schema: $store->schema,
				objectUuid: $patch->sessionId,
				event: 'attendance-late-correction',
				payload: $correction,
				tag: self::TAG_VERSLAG,
			);
		}

		try {
			return $this->patchSession(store: $store, patch: $patch);
		} catch (\Throwable $e) {
			foreach ($patch->entries as $correction) {
				$this->auditTrail->recordRefusal(
					register: $store->register,
					schema: $store->schema,
					objectUuid: $patch->sessionId,
					event: 'attendance-late-correction-not-applied',
					payload: $correction + ['error' => $e->getMessage()],
					tag: self::TAG_VERSLAG,
				);
			}

			$this->logger->error(
				'Dossiq hearing: failed to record attendance: ' . $e->getMessage()
			);
			throw new RuntimeException('Could not record attendance');
		}//end try
	}//end writeAttendance()

	/**
	 * Record the verslag entry, then apply the minutes.
	 *
	 * Awb art. 7:7. The verslag is recorded before the session changes.
	 *
	 * @param SessionStore $store The session store.
	 * @param SessionPatch $patch The minutes patch; its one entry is the verslag payload.
	 *
	 * @return array<string, mixed> The updated session.
	 *
	 * @throws RuntimeException When the entry or the patch fails.
	 */
	private function recordMinutes(SessionStore $store, SessionPatch $patch): array {
		try {
			return $this->auditTrail->recordThenApply(
				register: $store->register,
				schema: $store->schema,
				objectUuid: $patch->sessionId,
				event: 'verslag-recorded',
				payload: $patch->entries[0],
				tag: self::TAG_VERSLAG,
				apply: fn (): array => $this->patchSession(store: $store, patch: $patch),
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq hearing: failed to add minutes: ' . $e->getMessage()
			);
			throw new RuntimeException('Could not add minutes');
		}//end try
	}//end recordMinutes()

	/**
	 * Apply a patch to a stored session.
	 *
	 * @param SessionStore $store The session store.
	 * @param SessionPatch $patch The patch.
	 *
	 * @return array<string, mixed> The updated session, or the stored one with the changes when the store answers nothing.
	 */
	private function patchSession(SessionStore $store, SessionPatch $patch): array {
		return ($this->patchObjectAsArray(
			objectService: $store->objectService,
			register: $store->register,
			schema: $store->schema,
			id: $patch->sessionId,
			changes: $patch->update
		) ?? array_merge($patch->current, $patch->update));
	}//end patchSession()

	/**
	 * The uuid of a saved record, as OpenRegister serialises it.
	 *
	 * @param array<string, mixed> $record The saved record.
	 *
	 * @return string The uuid, or '' when it carries none.
	 */
	private function idOf(array $record): string {
		return (string)($record['@self']['id'] ?? ($record['id'] ?? ($record['uuid'] ?? '')));
	}//end idOf()

	/**
	 * The case a default hearing is seeded for: '' when it cannot be
	 * resolved or the case may have a session already.
	 *
	 * @param SessionStore $store       The session store.
	 * @param string       $objectionId Bezwaar UUID.
	 *
	 * @return string
	 */
	private function seedCaseId(SessionStore $store, string $objectionId): string {
		// The objection's own id stands in for the case when it names none.
		$caseId = $this->caseOfObjection(objectionId: $objectionId);
		if ($caseId === '') {
			$caseId = $objectionId;
		}

		if ($caseId === '' || $this->mayHaveSession(store: $store, caseId: $caseId) === true) {
			return '';
		}

		return $caseId;
	}//end seedCaseId()

	/**
	 * Whether the case has a session already, or the lookup failed.
	 *
	 * @param SessionStore $store  The session store.
	 * @param string       $caseId UUID of the case.
	 *
	 * @return bool
	 */
	private function mayHaveSession(SessionStore $store, string $caseId): bool {
		try {
			$existing = $this->searchObjectsAsArrays(
				objectService: $store->objectService,
				register: $store->register,
				schema: $store->schema,
				filters: ['case' => $caseId]
			);
		} catch (\Throwable $e) {
			$this->logger->debug(
				'Dossiq hearing: lookup for existing sessions failed: '
				. $e->getMessage()
			);
			return true;
		}

		return $existing !== [];
	}//end mayHaveSession()

	/**
	 * The case field of a bezwaar record.
	 *
	 * @param string $objectionId Bezwaar UUID
	 *
	 * @return string The case UUID, or '' when it is unconfigured, missing or unreadable.
	 */
	private function caseOfObjection(string $objectionId): string {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return '';
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$objectionSchema = $this->settingsService->getConfigValue(key: 'bezwaar_schema');
		if ($register === '' || $objectionSchema === '') {
			return '';
		}

		try {
			$objection = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $objectionSchema,
				id: $objectionId
			);
			return (string)($objection['case'] ?? '');
		} catch (\Throwable $e) {
			$this->logger->debug(
				'Dossiq hearing: bezwaar lookup failed: ' . $e->getMessage()
			);
		}

		return '';
	}//end caseOfObjection()
}//end class
