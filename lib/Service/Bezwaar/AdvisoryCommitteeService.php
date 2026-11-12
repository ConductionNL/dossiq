<?php

/**
 * Dossiq Bezwaar Advisory Committee Service.
 *
 * Domain service for the bezwaaradviescommissie (BAC) capability under
 * Awb Art. 7:13. Owns the legitimate domain operations that cannot be
 * handled by the manifest-driven CRUD path:
 *
 *  - assignToCommittee()          — referral with independence check
 *                                   (Awb Art. 7:13(3))
 *  - transitionAdviceStatus()     — one-way lifecycle transitions
 *                                   (assigned → in-deliberation → advice-issued)
 *  - autoAssignDefaultCommittee() — listener entry point used when a bezwaar
 *                                   case enters status "Advies aangevraagd"
 *
 * Per the per-app convention every mutation goes through OpenRegister via
 * the manifest renderer; this service composes those calls and writes the
 * entries on OpenRegister's audit trail of the advice request, which satisfy Archiefwet 1995.
 *
 * Identity is ALWAYS derived from `IUserSession`. Static error messages
 * only — exception details never bubble to controllers.
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

use DateTimeImmutable;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\AdviceDelegationService;
use OCA\Dossiq\Service\Governance\GovernanceBodyReader;
use OCA\Dossiq\Service\InternalWriteMarker;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Transitions\GuardFailedException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * BAC service: committee assignment + advice request lifecycle.
 *
 * @spec openspec/specs/bezwaar-advisory-committee/spec.md
 */
class AdvisoryCommitteeService {

	use SearchesObjects;
	use ReadsPanelMembers;

	/**
	 * Allowed advice-request lifecycle states.
	 */
	private const VALID_STATUSES = [
		'assigned',
		'in-deliberation',
		'advice-issued',
		'inadmissible',
	];

	/**
	 * Allowed one-way forward transitions (source => [allowed targets]).
	 * niet-ontvankelijk is a terminal advice the committee MAY issue from
	 * in-deliberation per Awb Art. 7:13(7).
	 */
	private const ALLOWED_TRANSITIONS = [
		'assigned' => ['in-deliberation'],
		'in-deliberation' => ['advice-issued', 'inadmissible'],
		'advice-issued' => [],
		'inadmissible' => [],
	];

	/**
	 * Trail event and message of an independence refusal: 0 names a
	 * conflicting member, 1 could not verify independence.
	 */
	private const INDEPENDENCE_REFUSALS = [
		['independence-check-failed', 'Panel member conflict (Awb Art. 7:13 lid 3): '],
		['independence-check-unverifiable', 'Panel independence could not be verified (Awb Art. 7:13 lid 3): '],
	];

	/**
	 * Required structured-advice fields when transitioning to advice-issued.
	 */
	private const REQUIRED_ADVICE_FIELDS = [
		'conclusion',
		'recommendation',
	];

	/**
	 * Default deadline in days for advice delivery (12 weeks per Awb 7:24(1)).
	 */
	private const DEFAULT_DEADLINE_DAYS = 84;

	/**
	 * The code of the RuntimeException raised for an advice request that does not exist.
	 */
	public const NOT_FOUND = 404;

	/**
	 * The code of the RuntimeException raised when someone other than the committee chair signs the advice.
	 */
	public const NOT_CHAIR = 403;

	/**
	 * The statuses the committee chair signs.
	 */
	private const SIGNED_STATUSES = ['advice-issued', 'inadmissible'];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Schema/register bridge
	 * @param LoggerInterface $logger Logger
	 * @param AdviceDelegationService $adviceDelegation Advice delegation to decidesk (ADR-019)
	 * @param BezwaarAuditTrail $auditTrail Writes each entry onto OpenRegister's audit trail
	 * @param PanelIndependenceChecker $independence Awb Art. 7:13 lid 3 panel check
	 * @param GovernanceBodyReader|null $governanceBodies Reads the committee back from decidiq, falling back locally
	 * @param InternalWriteMarker $internalWrites Marks the transition's own save for DeliberationStartGuard
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly AdviceDelegationService $adviceDelegation,
		private readonly BezwaarAuditTrail $auditTrail,
		private readonly PanelIndependenceChecker $independence,
		private readonly ?GovernanceBodyReader $governanceBodies = null,
		private readonly InternalWriteMarker $internalWrites = new InternalWriteMarker(),
	) {
	}//end __construct()

	/**
	 * Assign a bezwaar case to a committee, creating a bacAdviceRequest in
	 * state `assigned`. The independence check (Awb Art. 7:13(3)) is
	 * deferred until the advance-to-in-deliberation transition because a
	 * committee MAY be valid for one bezwaar and invalid for another.
	 *
	 * @param string $objectionId UUID of the bezwaar (lifecycle)
	 * @param string $commissieId UUID of the committee
	 * @param array<string, mixed> $payload Optional extra fields
	 *                                      (panel, deadline, etc.)
	 *
	 * @return array<string, mixed> The created bacAdviceRequest record
	 *
	 * @throws RuntimeException When OpenRegister unavailable or refs invalid
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function assignToCommittee(
		string $objectionId,
		string $commissieId,
		array $payload = [],
	): array {
		$store = ($this->requestStore() ?? throw new RuntimeException('OpenRegister is not available'));
		$committeeSchema = $this->settingsService->getConfigValue(
			key: 'bezwaaradviescommissie_schema'
		);

		if (in_array('', [$store['register'], $store['schema'], $committeeSchema], true) === true) {
			throw new RuntimeException(
				'BAC schemas are not configured'
			);
		}

		$this->assertCommitteeAcceptsReferrals(
			committees: ['schema' => $committeeSchema] + $store,
			commissieId: $commissieId
		);

		$record = $this->newRequest(
			payload: $payload,
			referral: ['bezwaar' => $objectionId, 'committee' => $commissieId],
		);

		return $this->saveNewRequest(store: $store, record: $record);
	}//end assignToCommittee()

	/**
	 * The object service, register and advice-request schema, or null without OpenRegister.
	 *
	 * @return array{objectService: object, register: string, schema: string}|null The store.
	 *
	 * @spec exclude one read shared by every write in this class
	 */
	private function requestStore(): ?array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		return [
			'objectService' => $objectService,
			'register' => $this->settingsService->getConfigValue(key: 'register'),
			'schema' => $this->settingsService->getConfigValue(key: 'bac_advice_request_schema'),
		];
	}//end requestStore()

	/**
	 * A new advice request in state `assigned`.
	 *
	 * @param array<string, mixed> $payload  The caller's extra fields (panel, deadline, etc.).
	 * @param array<string, string> $referral The `bezwaar` and `committee` it refers.
	 *
	 * @return array<string, mixed> The record to save.
	 *
	 * @spec exclude one step of assignToCommittee(), which carries the requirement
	 */
	private function newRequest(array $payload, array $referral): array {
		$deadline = (new DateTimeImmutable())
			->modify('+' . self::DEFAULT_DEADLINE_DAYS . ' days')
			->format('Y-m-d');

		// `bezwaar` IS THE PROPERTY NAME. `objectionProceeding` is the
		// SCHEMA it $refs — the two were confused, and every advice
		// request written since has omitted a property the schema marks
		// required while carrying an undeclared one instead. The visible
		// cost: BezwaarDetail's advice-request stats and list filter on
		// `bezwaar: @objectId` (src/manifest.json:1852, 1871, 1906), so a
		// bezwaar showed NO advice requests, however many it had.
		return array_merge(
			['panel' => [], 'deadline' => $deadline],
			$payload,
			$referral + ['status' => 'assigned', 'assignedAt' => (new DateTimeImmutable())->format('c')]
		);
	}//end newRequest()

	/**
	 * Save a new advice request and put its panel on the request's own trail.
	 *
	 * @param array{objectService: object, register: string, schema: string} $store The request store.
	 * @param array<string, mixed> $record The request to save.
	 *
	 * @return array<string, mixed> The saved request, or the record when the store answers nothing.
	 *
	 * @throws RuntimeException When the save fails.
	 *
	 * @spec exclude one step of assignToCommittee(), which carries the requirement
	 */
	private function saveNewRequest(array $store, array $record): array {
		try {
			$saved = ($this->saveObjectAsArray(
				objectService: $store['objectService'],
				register: $store['register'],
				schema: $store['schema'],
				object: $record
			) ?? $record);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq BAC: failed to create advice request: ' . $e->getMessage()
			);
			throw new RuntimeException('Could not create advice request');
		}

		// The panel composition is a row on the request's own OpenRegister
		// trail; a request whose entry cannot be written is deleted again.
		$this->auditTrail->recordForNewRecord(
			objectService: $store['objectService'],
			register: $store['register'],
			schema: $store['schema'],
			objectUuid: (string)($saved['@self']['id'] ?? ($saved['id'] ?? ($saved['uuid'] ?? ''))),
			event: 'panel-member-added',
			payload: [
				'panel' => $record['panel'],
				'commissieId' => $record['committee'],
				'objectionProceeding' => $record['bezwaar'],
			],
		);

		return $saved;
	}//end saveNewRequest()

	/**
	 * Refuse a committee that does not exist or no longer accepts referrals.
	 *
	 * Decidiq holds the committee as a governance body and answers first;
	 * without decidiq, or for a committee not yet raised there, the local row
	 * answers (migrate-committees-to-decidiq task 3).
	 *
	 * @param array{objectService: object, register: string, schema: string} $committees The committee store.
	 * @param string $commissieId The committee id.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the committee is missing or archived.
	 *
	 * @spec openspec/changes/migrate-committees-to-decidiq/specs/migrate-committees-to-decidiq/spec.md#requirement-req-mcd-003-reads-resolve-from-decidiq-falling-back-locally
	 */
	private function assertCommitteeAcceptsReferrals(array $committees, string $commissieId): void {
		// Validate committee exists and is active. find() returns an
		// ObjectEntity (never an array), so go through the array bridge.
		$committee = $this->findObjectAsArray(
			objectService: $committees['objectService'],
			register: $committees['register'],
			schema: $committees['schema'],
			id: $commissieId
		);
		if ($committee === null) {
			throw new RuntimeException('Committee not found');
		}

		// Decidiq holds the committee as a governance body and is the authority
		// for whether it is still active (migrate-committees-to-decidiq task 3).
		// Without decidiq, or for a committee not yet raised there, the local
		// row answers as before.
		$committee = ($this->governanceBodies?->resolve(row: $committee) ?? $committee);

		$active = $committee['active'] ?? true;
		if ($active === false) {
			throw new RuntimeException(
				'Committee is archived and cannot accept new bezwaaren'
			);
		}
	}//end assertCommitteeAcceptsReferrals()

	/**
	 * Advance the advice request to a new status. Enforces the one-way
	 * lifecycle (REQ-BAC-3), the independence check (REQ-BAC-2) and the
	 * advice content contract (REQ-BAC-4).
	 *
	 * @param string $requestId Advice request UUID
	 * @param string $newStatus Target status
	 * @param array<string, mixed> $payload Optional patch (advice text,
	 *                                      signatureEvidence, conclusion, ...)
	 *
	 * @return array<string, mixed> The updated advice request record
	 *
	 * @throws RuntimeException When the transition is forbidden
	 * @throws GuardFailedException When the independence check fails
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 * @spec openspec/specs/remaining-decision-delegation/spec.md
	 * @spec openspec/specs/remaining-decision-delegation/spec.md#requirement-req-pdrd-004-the-awb-and-idor-domain-rules-stay-in-dossiq
	 */
	public function transitionAdviceStatus(
		string $requestId,
		string $newStatus,
		array $payload = [],
	): array {
		if (in_array($newStatus, self::VALID_STATUSES, true) === false) {
			throw new RuntimeException('Invalid BAC advice status');
		}

		$request = $this->loadAdviceRequest(requestId: $requestId);
		$from = (string)($request['current']['status'] ?? 'assigned');
		$this->assertTransitionAllowed(from: $from, newStatus: $newStatus);
		if (in_array($newStatus, self::SIGNED_STATUSES, true) === true) {
			$this->assertCallerChairs(request: $request);
		}

		$update = $payload;
		$update['status'] = $newStatus;
		$update = $this->buildTransitionUpdate(
			update: $update,
			adviceDecisionRef: $this->guardTransition(request: $request, move: ['from' => $from] + $update),
		);

		try {
			return $this->applyTransition(request: $request, update: $update);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq BAC: failed to transition advice request '
				. $requestId . ': ' . $e->getMessage()
			);
			throw new RuntimeException('Could not transition advice request');
		}//end try
	}//end transitionAdviceStatus()

	/**
	 * The stored advice request, null when it does not exist.
	 *
	 * @param array{objectService: object, register: string, schema: string} $store The request store.
	 * @param string $requestId Advice request UUID.
	 *
	 * @return array<string, mixed>|null The request.
	 *
	 * @spec exclude one read shared by the two request readers in this class
	 */
	private function findRequest(array $store, string $requestId): ?array {
		return $this->findObjectAsArray(
			objectService: $store['objectService'],
			register: $store['register'],
			schema: $store['schema'],
			id: $requestId
		);
	}//end findRequest()

	/**
	 * The advice request a council deviation is recorded on, null when it cannot be read.
	 *
	 * @param array{objectService: object, register: string, schema: string} $store The request store.
	 * @param string $requestId Advice request UUID.
	 *
	 * @return array<string, mixed>|null The request, null when missing or the read fails (logged).
	 *
	 * @spec exclude one read behind recordCouncilDeviation(), which carries the requirement
	 */
	private function readForDeviation(array $store, string $requestId): ?array {
		$current = null;
		try {
			$current = $this->findRequest(store: $store, requestId: $requestId);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq BAC: failed to read the advice request for a council deviation: '
				. $e->getMessage()
			);
		}

		return $current;
	}//end readForDeviation()

	/**
	 * The structured advice must carry every required field.
	 *
	 * @param array<string, mixed> $advice The stored request with the caller's patch over it.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When a required field is missing or empty.
	 *
	 * @spec exclude one guard of issueAdviceDecision(), which carries the requirement
	 */
	private function requireAdviceFields(array $advice): void {
		$missing = array_values(
			array_filter(
				self::REQUIRED_ADVICE_FIELDS,
				static fn (string $field): bool => in_array(($advice[$field] ?? null), [null, '', []], true)
			)
		);
		if ($missing !== []) {
			throw new RuntimeException(
				'Advice cannot be issued: missing required field '
				. $missing[0]
			);
		}
	}//end requireAdviceFields()

	/**
	 * Run the guard the move needs, and answer the advice decision it raised.
	 *
	 * @param array{objectService: object, register: string, schema: string, id: string, current: array<string, mixed>} $request The stored request.
	 * @param array<string, mixed> $move The caller's patch with its `from` and target `status`.
	 *
	 * @return string The decidesk Decision reference, '' when the move raises none.
	 *
	 * @throws RuntimeException|GuardFailedException When the guard refuses.
	 *
	 * @spec exclude one step of transitionAdviceStatus(), which carries the requirement
	 */
	private function guardTransition(array $request, array $move): string {
		// Guard: assigned → in-deliberation requires panel and
		// independence (REQ-BAC-2).
		if ($move['from'] === 'assigned' && $move['status'] === 'in-deliberation') {
			$this->guardPanel(request: $request);
		}

		// Guard: in-deliberation → advice-issued requires the structured
		// advice content (REQ-BAC-4).
		if ($move['from'] === 'in-deliberation' && $move['status'] === 'advice-issued') {
			return $this->issueAdviceDecision(request: $request, payload: $move);
		}

		return '';
	}//end guardTransition()

	/**
	 * Refuse a signature by anyone but the chair of the request's committee.
	 *
	 * @param array{objectService: object, register: string, schema: string, id: string, current: array<string, mixed>} $request The stored request.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the committee or its chair cannot be read, or the caller is not the chair.
	 *
	 * @spec exclude one guard of transitionAdviceStatus(), which carries the requirement
	 */
	private function assertCallerChairs(array $request): void {
		$chair = '';
		$committeeId = (string)($request['current']['committee'] ?? '');
		if ($committeeId !== '') {
			$committee = $this->findObjectAsArray(
				objectService: $request['objectService'],
				register: $request['register'],
				schema: $this->settingsService->getConfigValue(key: 'bezwaaradviescommissie_schema'),
				id: $committeeId
			);
			if ($committee !== null) {
				$committee = ($this->governanceBodies?->resolve(row: $committee) ?? $committee);
				$chair = (string)($committee['chair'] ?? '');
			}
		}

		if ($chair === '' || $chair !== $this->auditTrail->resolveActor()) {
			throw new RuntimeException('Only the chair of the committee can sign the advice', self::NOT_CHAIR);
		}
	}//end assertCallerChairs()

	/**
	 * The stored advice request with the store it lives in.
	 *
	 * @param string $requestId Advice request UUID.
	 *
	 * @return array{objectService: object, register: string, schema: string, id: string, current: array<string, mixed>} The request.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable or the request is missing.
	 *
	 * @spec exclude one read behind transitionAdviceStatus(), which carries the requirement
	 */
	private function loadAdviceRequest(string $requestId): array {
		$store = ($this->requestStore() ?? throw new RuntimeException('OpenRegister is not available'));
		$current = $this->findRequest(store: $store, requestId: $requestId);
		if ($current === null) {
			throw new RuntimeException('Advice request not found', self::NOT_FOUND);
		}

		// Keyed on the stored request's own id, so a slug or other id form the
		// store resolves still names the object the guard sees being saved.
		return $store + ['id' => (string)($current['id'] ?? ($current['@self']['id'] ?? $requestId)), 'current' => $current];
	}//end loadAdviceRequest()

	/**
	 * Write a transition's patch, recording the chair's signature first on a terminal state.
	 *
	 * A terminal state carries the chair's signature, with the chair from the
	 * session. It is recorded before the status moves; a status write that
	 * then fails leaves an `advice-signed-by-chair-not-applied` row
	 * (REQ-BAT-003).
	 *
	 * @param array{objectService: object, register: string, schema: string, id: string, current: array<string, mixed>} $request The stored request.
	 * @param array<string, mixed> $update The patch.
	 *
	 * @return array<string, mixed> The updated advice request record.
	 *
	 * @throws RuntimeException When the signature cannot be recorded; nothing is changed.
	 */
	private function applyTransition(array $request, array $update): array {
		// Marked so DeliberationStartGuard lets this save reach an outcome status.
		$apply = fn (): array => $this->internalWrites->runInternal(
			objectId: $request['id'],
			write: fn (): array => ($this->patchObjectAsArray(
				objectService: $request['objectService'],
				register: $request['register'],
				schema: $request['schema'],
				id: $request['id'],
				changes: $update
			) ?? array_merge($request['current'], $update))
		);

		if (isset($update['adviceIssuedAt']) === false) {
			return $apply();
		}

		return $this->auditTrail->recordThenApply(
			register: $request['register'],
			schema: $request['schema'],
			objectUuid: $request['id'],
			event: 'advice-signed-by-chair',
			payload: [
				'chair' => $this->auditTrail->resolveActor(),
				'signatureEvidence' => $update['signatureEvidence'] ?? ($request['current']['signatureEvidence'] ?? null),
				'conclusion' => $update['conclusion'] ?? ($request['current']['conclusion'] ?? null),
			],
			tag: '',
			apply: $apply,
		);
	}//end applyTransition()

	/**
	 * Listener entry-point: when a bezwaar enters status
	 * "Hearing planned", auto-assign the default committee for the
	 * bezwaar's jurisdiction.
	 *
	 * @param string $objectionId The bezwaar (lifecycle) UUID
	 *
	 * @return array<string, mixed>|null The created advice request, or
	 *                                   null when no default committee
	 *                                   is configured.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function autoAssignDefaultCommittee(string $objectionId): ?array {
		$defaultId = $this->settingsService->getConfigValue(
			key: 'bac_default_committee'
		);
		if ($defaultId === '') {
			$this->logger->info(
				'Dossiq BAC: no default committee configured; '
				. 'skipping auto-assignment for bezwaar ' . $objectionId
			);
			return null;
		}

		try {
			return $this->assignToCommittee(
				objectionId: $objectionId,
				commissieId: $defaultId,
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq BAC: auto-assignment failed for bezwaar '
				. $objectionId . ': ' . $e->getMessage()
			);
			return null;
		}
	}//end autoAssignDefaultCommittee()

	/**
	 * Record a council-deviation event on the linked advice request after
	 * the parent bezwaar-lifecycle finalises a besluit op bezwaar with
	 * `motivatieAfwijkingAdvies` set (REQ-BAC-5).
	 *
	 * @param string $requestId Advice request UUID
	 * @param string $decisionId Besluit op bezwaar UUID
	 * @param string $rationaleRef Reference / hash of the motivation
	 *
	 * @return void
	 *
	 * @throws RuntimeException A BezwaarEntryNotWrittenException when the entry could not be written; the caller logs it with the entry.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 * @spec openspec/specs/bezwaar-awb-audit-trail/spec.md
	 */
	public function recordCouncilDeviation(
		string $requestId,
		string $decisionId,
		string $rationaleRef,
	): void {
		$store = $this->requestStore();
		if ($store === null) {
			return;
		}

		$current = $this->readForDeviation(store: $store, requestId: $requestId);
		if ($current === null) {
			return;
		}

		// Outside the try on purpose: an entry that cannot be written goes to
		// the caller, which logs it with the entry (REQ-BAT-003).
		$this->auditTrail->record(
			register: $store['register'],
			schema: $store['schema'],
			objectUuid: $requestId,
			event: 'council-deviation-recorded',
			payload: [
				'decision' => $decisionId,
				'motivatie' => $rationaleRef,
			],
			tag: BezwaarAuditTrail::TAG_BAC_REFERRAL,
		);
	}//end recordCouncilDeviation()

	/**
	 * Assert that the requested advice-status transition is permitted by the
	 * one-way lifecycle (REQ-BAC-3).
	 *
	 * @param string $from Current advice-request status
	 * @param string $newStatus Requested target status
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the transition is not permitted
	 */
	private function assertTransitionAllowed(string $from, string $newStatus): void {
		$allowed = self::ALLOWED_TRANSITIONS[$from] ?? [];

		if (in_array($newStatus, $allowed, true) === false) {
			throw new RuntimeException(
				'Transition from ' . $from . ' to ' . $newStatus . ' is not permitted'
			);
		}
	}//end assertTransitionAllowed()

	/**
	 * Guard a move into in-deliberation (REQ-BAC-2) for a request as it will be saved.
	 *
	 * Called by DeliberationStartGuard on every single-object save that moves
	 * an advice request into in-deliberation.
	 *
	 * @param array<string, mixed> $request   The advice request as it will be saved
	 * @param string               $requestId Advice request UUID
	 *
	 * @return void
	 *
	 * @throws RuntimeException When OpenRegister is not available, or no panel has been set
	 * @throws GuardFailedException When a panel member is not independent, or independence cannot be verified
	 *
	 * @spec openspec/changes/every-move-into-deliberation-runs-the-panel-check/specs/bezwaar-advisory-committee/spec.md#requirement-every-single-object-save-that-starts-deliberation-runs-the-panel-check
	 */
	public function guardDeliberationStart(array $request, string $requestId): void {
		$store = ($this->requestStore() ?? throw new RuntimeException('OpenRegister is not available'));
		$this->guardPanel(request: $store + ['id' => $requestId, 'current' => $request]);
	}//end guardDeliberationStart()

	/**
	 * Guard the assigned → in-deliberation transition (REQ-BAC-2): a panel
	 * must be set and every member must be independent. An independence
	 * failure is recorded on the request's trail before the guard raises.
	 *
	 * @param array{objectService: object, register: string, schema: string, id: string, current: array<string, mixed>} $request The stored request.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When no panel has been set, it holds only blank members, or a member is not a user id
	 * @throws GuardFailedException When a panel member is not independent, or independence cannot be verified
	 */
	private function guardPanel(array $request): void {
		$panel = $this->requirePanelMembers(panel: (array)($request['current']['panel'] ?? []));

		$independence = $this->independence->check(
			objectionId: $this->objectionIdOf(request: $request['current']),
			panel: $panel,
		);

		if ($independence['ok'] !== false) {
			return;
		}

		// A refusal without a member means independence could not be verified.
		[$event, $message] = self::INDEPENDENCE_REFUSALS[(int)($independence['member'] === null)];

		// Recorded before raising, and refused whether or not the entry is
		// written; an unwritten entry goes to the error log in full.
		$this->auditTrail->recordRefusal(
			register: $request['register'],
			schema: $request['schema'],
			objectUuid: $request['id'],
			event: $event,
			payload: [
				'conflictingMember' => $independence['member'],
				'reason' => $independence['reason'],
			],
		);

		throw new GuardFailedException(
			failedGuards: [],
			message: $message . $independence['reason']
		);
	}//end guardPanel()

	/**
	 * Validate the structured advice content (REQ-BAC-4) and raise the
	 * decidesk `advice` Decision (REQ-PDRD-001 / REQ-PDRD-002).
	 *
	 * The BAC advice is *made* in decidesk; this fails CLOSED and never
	 * authors the advice outcome locally as a fallback.
	 *
	 * @param array{objectService: object, register: string, schema: string, id: string, current: array<string, mixed>} $request The stored request.
	 * @param array<string, mixed> $payload Caller-supplied patch
	 *
	 * @return string The decidesk Decision reference
	 *
	 * @throws RuntimeException When a required advice field is missing or the
	 *                          decision service is unavailable
	 */
	private function issueAdviceDecision(array $request, array $payload): string {
		$merged = array_merge($request['current'], $payload);
		$this->requireAdviceFields(advice: $merged);

		try {
			return $this->adviceDelegation->raiseAdviceDecision(
				subjectSchema: 'bacAdviceRequest',
				subjectId: $request['id'],
				payload: [
					'subjectRegister' => $request['register'],
					'externalReference' => $this->externalReferenceFor(request: $request['current'], requestId: $request['id']),
					'subjectLabel' => (string)($merged['conclusion'] ?? 'BAC-advies'),
					'adviceType' => (string)($merged['recommendation'] ?? ''),
					'question' => (string)($merged['conclusion'] ?? ''),
				],
			);
		} catch (RuntimeException $e) {
			$this->logger->error(
				'Dossiq BAC: decidesk advice Decision raise failed — failing closed: '
				. $e->getMessage()
			);
			throw new RuntimeException('Decision service unavailable: ' . $e->getMessage(), 0, $e);
		}//end try
	}//end issueAdviceDecision()

	/**
	 * Compose the advice-request patch for a lifecycle transition, stamping
	 * the issue timestamp on terminal states. The chair's signature is recorded
	 * on the request's trail by transitionAdviceStatus(), before this patch lands.
	 *
	 * @param array<string, mixed> $update Caller-supplied patch with the target `status`
	 * @param string $adviceDecisionRef decidesk Decision reference, or ''
	 *
	 * @return array<string, mixed> The patch to persist
	 */
	private function buildTransitionUpdate(array $update, string $adviceDecisionRef): array {
		if ($adviceDecisionRef !== '') {
			$update['decisionRef'] = $adviceDecisionRef;
		}

		if (in_array($update['status'], self::SIGNED_STATUSES, true) === false) {
			return $update;
		}

		$update['adviceIssuedAt'] = (new DateTimeImmutable())
			->format('c');

		return $update;
	}//end buildTransitionUpdate()

	/**
	 * The objection this advice request belongs to.
	 *
	 * `bezwaar` is the schema-declared property; `objectionProceeding` is the
	 * schema it $refs, and was written into the object by mistake for as long as
	 * this service has existed. Rows created before the fix carry only the wrong
	 * key, so the legacy branch stays until `BackfillAdviceRequestObjection` has
	 * run everywhere.
	 *
	 * The legacy branch LOGS. A silent fallback would make the repair step look
	 * unnecessary — the reads would keep working, the manifest filters would keep
	 * showing nothing, and the only symptom would stay invisible.
	 *
	 * @param array<string, mixed> $request The stored advice request.
	 *
	 * @return string The objection id, or '' when neither key is present.
	 */
	private function objectionIdOf(array $request): string {
		$objectionId = (string)($request['bezwaar'] ?? '');
		if ($objectionId !== '') {
			return $objectionId;
		}

		$legacy = (string)($request['objectionProceeding'] ?? '');
		if ($legacy !== '') {
			$this->logger->warning(
				'Dossiq BAC: advice request still carries the legacy objection key; run the backfill repair step',
				['app' => Application::APP_ID, 'requestId' => ($request['id'] ?? null)]
			);
		}

		return $legacy;
	}//end objectionIdOf()

	/**
	 * The external reference a raised advice decision carries.
	 *
	 * Prefers the objection, falling back to the advice request's own id so the
	 * decision is never raised with an empty reference.
	 *
	 * @param array<string, mixed> $request The stored advice request.
	 * @param string $requestId The advice request's own id.
	 *
	 * @return string The reference.
	 */
	private function externalReferenceFor(array $request, string $requestId): string {
		$objectionId = $this->objectionIdOf(request: $request);

		return [$objectionId, $requestId][(int)($objectionId === '')];
	}//end externalReferenceFor()
}//end class
