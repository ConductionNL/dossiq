<?php

/**
 * Dossiq Bezwaar Decision Service.
 *
 * Domain service for the bezwaar-decision capability — the formal
 * beslissing op bezwaar under Awb art. 7:11/7:12. Dossiq owns the
 * decision record, the structured rechtsmiddelenclausule, the
 * proceskostenvergoeding bookkeeping, and the publication + notification
 * flow. This service composes those operations and delegates every
 * persistence call to OpenRegister via SettingsService::getObjectService();
 * it never owns bespoke CRUD or a custom controller.
 *
 *  - draft()             — create a bezwaarDecision in status "draft" with
 *                          the canonical Awb 7:11 disposition enum and
 *                          link it to its bezwaar; required fields are
 *                          surface-validated.
 *  - publish()           — promote a draft to published after the
 *                          per-dispositionType mandatory-field guard
 *                          passes (replacementDecision for
 *                          gegrond_wijzigen; appealNotice completeness;
 *                          deviationRationale when advisoryOpinion is set
 *                          and the decision deviates; proceskosten rules
 *                          when herroepen/wijzigen). Sets publishedAt,
 *                          stamps notifiedRecipients, calls
 *                          applyToBezwaar() so the case transitions to
 *                          "Decision on objection" via the
 *                          status-transition-engine.
 *  - applyToBezwaar()    — write the decision back onto the linked
 *                          bezwaar by invoking the
 *                          StatusTransitionService — no bespoke
 *                          transition logic lives here.
 *
 * Identity for the publication actor is ALWAYS derived from
 * IUserSession. Static error messages only — exception details never
 * bubble to controllers.
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

use OCA\Dossiq\Kernel\Contracts\DecisionServiceInterface;
use OCA\Dossiq\Service\BezwaarDecisionDelegationService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\StatusTransitionService;
use OCA\Dossiq\Service\Support\CallerIdentity;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Bezwaar decision service: draft, publish, and apply to the linked
 * bezwaar via the status-transition-engine.
 *
 * @spec openspec/specs/bezwaar-decision/spec.md
 */
class DecisionService implements DecisionServiceInterface {

	use SearchesObjects;

	/**
	 * Canonical Awb art. 7:11 disposition values (REQ-BD-2).
	 *
	 * Declared once on {@see DecisionValidator} — the class that enforces
	 * them — and re-exported here for backwards compatibility with
	 * existing consumers of `DecisionService::VALID_DISPOSITIONS`.
	 *
	 * @var array<int, string>
	 */
	public const VALID_DISPOSITIONS = DecisionValidator::VALID_DISPOSITIONS;

	/**
	 * Bezwaar status target on publication (handed off to the
	 * status-transition-engine).
	 */
	private const TARGET_BEZWAAR_STATUS = 'Decision on objection';

	/**
	 * Transition id wired on the bezwaar workflowTemplate to move into
	 * "Decision on objection".
	 */
	private const TRANSITION_ID = 'beslissing-op-bezwaar';

	/**
	 * The decision fields that name a recipient, and the role each is listed under.
	 *
	 * @var array<string, string>
	 */
	private const RECIPIENT_FIELDS = [
		'bezwaarmaker' => 'bezwaarmaker',
		'authorisedRepresentative' => 'gemachtigde',
		'primairBeslisser' => 'primair-beslisser',
		'advisoryOpinion' => 'bac-secretaris',
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Schema/register bridge.
	 * @param IUserSession $userSession Acting identity source.
	 * @param StatusTransitionService $transitions Engine used by
	 *                                             applyToBezwaar()
	 *                                             to transition
	 *                                             the linked
	 *                                             bezwaar
	 *                                             without
	 *                                             bespoke
	 *                                             transition
	 *                                             logic.
	 * @param LoggerInterface $logger Logger.
	 * @param BezwaarDecisionDelegationService $decisionDelegation Decision delegation to decidesk (event dispatch).
	 * @param DecisionValidator $validator The Awb validity matrix (REQ-PDRD-004).
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IUserSession $userSession,
		private readonly StatusTransitionService $transitions,
		private readonly LoggerInterface $logger,
		private readonly BezwaarDecisionDelegationService $decisionDelegation,
		private readonly DecisionValidator $validator,
	) {
	}//end __construct()

	/**
	 * Create a bezwaarDecision in status "draft".
	 *
	 * Surface-validates the disposition enum and the per-disposition
	 * mandatory-field rules that apply at draft time (canonical enum,
	 * replacementDecision compatibility, reasoning + legalBasis
	 * presence). Hard guards that only apply at publication time
	 * (appealNotice completeness, proceskosten resolution) are
	 * deferred to publish().
	 *
	 * @param string $objectionId UUID of the bezwaar.
	 * @param array<string, mixed> $payload Decision properties.
	 *
	 * @return array<string, mixed> The created decision record.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable, schemas
	 *                          are unconfigured, or the payload is
	 *                          invalid at draft time.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function draft(string $objectionId, array $payload): array {
		$payload = array_diff_key($payload, CallerIdentity::KEYS);
		$store = $this->requireDecisionStore();
		$this->validator->assertDraftable(payload: $payload);

		// 🔴 `bezwaar`, NOT `objectionProceeding`. The schema declares the link
		// as `bezwaar` and REQUIRES it; `objectionProceeding` is the name of
		// the SCHEMA it $refs, not of the property. Written under the wrong
		// name the link is an undeclared key, which OpenRegister drops in
		// silence, so every draft stored a decision belonging to no bezwaar and
		// publish() then read an empty objection id. Nothing reported it,
		// because nothing ever called either method. `bacAdviceRequest` had the
		// identical defect and needed a backfill; this one is caught before a
		// row exists.
		$record = array_merge(
			$payload,
			[
				'bezwaar' => $objectionId,
				'status' => 'draft',
			]
		);
		// The publishedAt and notifiedRecipients fields are owned by publish().
		unset($record['publishedAt'], $record['notifiedRecipients']);

		try {
			return ($this->saveObjectAsArray(
				objectService: $store['objectService'],
				register: $store['register'],
				schema: $store['schema'],
				object: $record
			) ?? $record);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq bezwaar-decision: failed to draft: ' . $e->getMessage()
			);
			throw new RuntimeException('Could not draft bezwaarDecision');
		}
	}//end draft()

	/**
	 * The case a bezwaar belongs to, for the per-case guard.
	 *
	 * A controller cannot authorise a decision on an objection without one:
	 * `CaseAccessGuard` answers per CASE, and an objection names its case.
	 * Answering null denies, which is the posture every other bezwaar surface
	 * takes on an unresolvable id.
	 *
	 * @param string $objectionId UUID of the bezwaar.
	 *
	 * @return string|null The case uuid, or null when it cannot be resolved.
	 *
	 * @spec openspec/specs/bezwaar-decision/spec.md
	 */
	public function caseIdForObjection(string $objectionId): ?string {
		$objectionId = trim($objectionId);
		if ($objectionId === '') {
			return null;
		}

		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue(key: 'register');
		$objectionSchema = $this->settingsService->getConfigValue(key: 'bezwaar_schema');
		if ($objectService === null || $register === '' || $objectionSchema === '') {
			return null;
		}

		$objection = $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $objectionSchema,
			id: $objectionId
		);

		$caseId = trim((string)($objection['case'] ?? ''));

		if ($caseId === '') {
			return null;
		}

		return $caseId;
	}//end caseIdForObjection()

	/**
	 * The case a drafted decision belongs to, through its bezwaar.
	 *
	 * @param string $decisionId UUID of the bezwaarDecision.
	 *
	 * @return string|null The case uuid, or null when it cannot be resolved.
	 *
	 * @spec openspec/specs/bezwaar-decision/spec.md
	 */
	public function caseIdForDecision(string $decisionId): ?string {
		$decisionId = trim($decisionId);
		if ($decisionId === '') {
			return null;
		}

		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue(key: 'register');
		$decisionSchema = $this->settingsService->getConfigValue(key: 'bezwaar_decision_schema');
		if ($objectService === null || $register === '' || $decisionSchema === '') {
			return null;
		}

		$decision = $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $decisionSchema,
			id: $decisionId
		);

		return $this->caseIdForObjection(objectionId: (string)($decision['bezwaar'] ?? ''));
	}//end caseIdForDecision()

	/**
	 * Publish a draft bezwaarDecision by delegating the *deciding* to decidesk.
	 *
	 * Runs the full Awb validity matrix (REQ-BD-3, REQ-BD-5, REQ-BD-6,
	 * REQ-BD-7) as dossiq domain validation (REQ-PDRD-004), then raises a
	 * decidesk `bezwaar-decision` Decision by dispatching a `DecisionRequestedEvent`
	 * (REQ-PDRD-001) and persists the returned `decisionRef` on the record.
	 * dossiq no longer authors the besluit locally: there is no
	 * `status:'published'` local decision state — the besluit is materialised
	 * from the decidesk `DecisionConcludedEvent` by
	 * {@see \OCA\Dossiq\Listener\DecisionConcludedListener} (REQ-PDRD-003,
	 * REQ-PDRD-007). FAILS CLOSED when decidesk is unavailable (REQ-PDRD-002):
	 * no local decided state is set as a fallback.
	 *
	 * @param string $decisionId UUID of the bezwaarDecision.
	 *
	 * @return array<string, mixed> The decision record annotated with the decidesk decisionRef.
	 *
	 * @throws RuntimeException When validation fails, the decidesk leaf is
	 *                          unavailable (fail closed), or persistence errors.
	 *
	 * @spec openspec/specs/remaining-decision-delegation/spec.md
	 * @spec openspec/specs/remaining-decision-delegation/spec.md#requirement-req-pdrd-002-delegation-fails-closed-when-decidesk-is-unavailable
	 * @spec openspec/specs/remaining-decision-delegation/spec.md#requirement-req-pdrd-004-the-awb-and-idor-domain-rules-stay-in-dossiq
	 */
	public function publish(string $decisionId): array {
		$store = $this->requireDecisionStore() + ['decisionId' => $decisionId];
		$current = $this->findObjectAsArray(
			objectService: $store['objectService'],
			register: $store['register'],
			schema: $store['schema'],
			id: $decisionId
		);
		if ($current === null) {
			throw new RuntimeException('BezwaarDecision not found');
		}

		// REQ-PDRD-004: the Awb validity matrix (7:11 disposition set, 7:12
		// motivering, proceskosten, replacement/appeal guards) stays in dossiq
		// and runs BEFORE the Decision is raised, so no Decision can ever be
		// raised on an Awb-invalid payload.
		$this->validator->assertPublishable(decision: $current);

		$patch = $this->delegationPatch(
			decision: $current,
			decisionRef: $this->raiseDecision(store: $store, decision: $current)
		);

		return $this->recordDelegation(store: $store, change: ['current' => $current, 'patch' => $patch]);
	}//end publish()

	/**
	 * Where decisions live, or why they cannot be written.
	 *
	 * @return array{objectService: object, register: string, schema: string} The object service, register and decision schema.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable or the schema is not configured.
	 */
	private function requireDecisionStore(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$decisionSchema = $this->settingsService->getConfigValue(
			key: 'bezwaar_decision_schema'
		);
		if ($register === '' || $decisionSchema === '') {
			throw new RuntimeException(
				'BezwaarDecision schema is not configured'
			);
		}

		return ['objectService' => $objectService, 'register' => $register, 'schema' => $decisionSchema];
	}//end requireDecisionStore()

	/**
	 * Raise the decision with the deciding app and answer its reference.
	 *
	 * REQ-PDRD-001 / REQ-PDRD-002: the deciding is delegated to decidesk via
	 * its DecisionRequestedEvent. Fail closed: never author the besluit
	 * locally as a fallback.
	 *
	 * @param array<string, mixed> $store    The register, decision schema and decision id.
	 * @param array<string, mixed> $decision The stored decision.
	 *
	 * @return string The decision reference.
	 *
	 * @throws RuntimeException When the deciding app is unavailable.
	 */
	private function raiseDecision(array $store, array $decision): string {
		$objectionRef = (string)$store['decisionId'];
		$objectionId = (string)($decision['bezwaar'] ?? '');
		if ($objectionId !== '') {
			$objectionRef = $objectionId;
		}

		try {
			return $this->decisionDelegation->raiseBezwaarDecision(
				objectionId: $objectionRef,
				payload: [
					'subjectRegister' => $store['register'],
					'subjectSchema' => $store['schema'],
					'subjectId' => $store['decisionId'],
					'subjectLabel' => (string)($decision['title'] ?? ($decision['onderwerp'] ?? '')),
					'dispositionType' => (string)($decision['dispositionType'] ?? ''),
					'reasoning' => (string)($decision['reasoning'] ?? ''),
					'legalBasis' => (string)($decision['legalBasis'] ?? ''),
					'replacementDecision' => (string)($decision['replacementDecision'] ?? ''),
				],
			);
		} catch (RuntimeException $e) {
			// REQ-PDRD-002: surface the fail-closed error; do NOT set any local
			// decided state as a fallback.
			$this->logger->error(
				'Dossiq bezwaar-decision: decidesk Decision raise failed — failing closed: '
				. $e->getMessage()
			);
			throw new RuntimeException('Decision service unavailable: ' . $e->getMessage(), 0, $e);
		}//end try
	}//end raiseDecision()

	/**
	 * The fields publishing records: the reference and the notification audit
	 * list only, plus the proceskosten total when one is awarded. There is no
	 * local "published" decision state; the besluit is the decidesk outcome.
	 *
	 * @param array<string, mixed> $decision    The stored decision.
	 * @param string               $decisionRef The decision reference.
	 *
	 * @return array<string, mixed> The patch.
	 */
	private function delegationPatch(array $decision, string $decisionRef): array {
		$patch = [
			'decisionRef' => $decisionRef,
			'status' => 'awaiting-decidesk',
			'notifiedRecipients' => $this->collectRecipients(decision: $decision),
		];

		$totalAmount = $this->validator->computeProceskostenTotal(decision: $decision);
		if ($totalAmount !== null) {
			$proceskosten = (array)($decision['legalCostsCompensation'] ?? []);
			$proceskosten['totalAmount'] = $totalAmount;
			$patch['legalCostsCompensation'] = $proceskosten;
		}

		return $patch;
	}//end delegationPatch()

	/**
	 * Write the delegation patch onto the decision.
	 *
	 * @param array<string, mixed>                                              $store  The object service, register, decision schema and decision id.
	 * @param array{current: array<string, mixed>, patch: array<string, mixed>} $change The stored decision and the patch.
	 *
	 * @return array<string, mixed> The decision as written.
	 *
	 * @throws RuntimeException When the patch cannot be written.
	 */
	private function recordDelegation(array $store, array $change): array {
		try {
			return ($this->patchObjectAsArray(
				objectService: $store['objectService'],
				register: $store['register'],
				schema: $store['schema'],
				id: (string)$store['decisionId'],
				changes: $change['patch']
			) ?? array_merge($change['current'], $change['patch']));
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq bezwaar-decision: failed to persist decisionRef: '
				. $e->getMessage()
			);
			throw new RuntimeException('Could not record bezwaarDecision delegation');
		}
	}//end recordDelegation()

	/**
	 * Apply the bezwaar status transition once decidesk has concluded.
	 *
	 * The ZGW `Besluit` is materialised from the decidesk outcome by
	 * {@see \OCA\Dossiq\Listener\DecisionConcludedListener} when decidesk
	 * dispatches a `DecisionConcludedEvent` — there is no dossiq-local poll of
	 * the decidesk outcome here. This method only triggers the configured
	 * status transition on the linked bezwaar; the status engine still owns
	 * guards + side effects, and the besluit is never authored locally.
	 *
	 * @param string $objectionId UUID of the source bezwaar.
	 * @param string $decisionId UUID of the bezwaarDecision being applied.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-09-dossiq-delegation-via-events/specs/contract-decision-delegation/spec.md#requirement-req-pdcd-003-the-zgw-besluit-is-materialised-from-the-decisionconcludedevent
	 */
	public function applyToBezwaar(string $objectionId, string $decisionId): void {
		$caseId = $this->caseOfObjection(objectionId: $objectionId);
		if ($caseId === '') {
			return;
		}

		try {
			$this->transitions->execute(
				caseId: $caseId,
				transitionId: self::TRANSITION_ID,
				comment: 'Applied beslissing op bezwaar ' . $decisionId,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq bezwaar-decision: transition to '
				. self::TARGET_BEZWAAR_STATUS . ' failed: ' . $e->getMessage()
			);
		}
	}//end applyToBezwaar()

	/**
	 * The case an objection belongs to, as stored, or '' when it cannot be read.
	 *
	 * @param string $objectionId UUID of the bezwaar.
	 *
	 * @return string The case uuid, or ''.
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

		$objection = $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $objectionSchema,
			id: $objectionId
		);

		return (string)($objection['case'] ?? '');
	}//end caseOfObjection()

	/**
	 * Build the recipient audit list for the publication notification
	 * flow (REQ-BD-10). Bezwaarmaker, gemachtigde, primair beslisser,
	 * and the BAC secretaris (when advisoryOpinion is set) are
	 * surfaced from the decision payload where available. The actual
	 * notification fan-out is owned by NotificatieService /
	 * BerichtenboxAdapter; this method records who SHOULD be reached.
	 *
	 * @param array<string, mixed> $decision Decision payload.
	 *
	 * @return array<int, string>
	 */
	private function collectRecipients(array $decision): array {
		$recipients = [];

		$acting = $this->userSession->getUser();
		if ($acting !== null) {
			$recipients[] = 'actor:' . $acting->getUID();
		}

		foreach (self::RECIPIENT_FIELDS as $field => $role) {
			$recipient = (string)($decision[$field] ?? '');
			if ($recipient !== '') {
				$recipients[] = $role . ':' . $recipient;
			}
		}

		return array_values(array_unique($recipients));
	}//end collectRecipients()
}//end class
