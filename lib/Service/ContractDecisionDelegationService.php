<?php

/**
 * Dossiq Contract Decision Delegation Service
 *
 * Delegates contract approval / renewal / besluit decisions to decidesk via
 * the Nextcloud event dispatcher (decidesk's merged event contract). dossiq
 * keeps ZGW case management; decidesk owns the deciding. This service:
 *
 * - Raises a decidesk Decision by dispatching `DecisionRequestedEvent`.
 * - Reads the synchronous result the decidesk listener writes back onto the
 *   event (`isHandled()` / `getDecisionId()`).
 * - FAILS CLOSED when decidesk is unavailable (never auto-approves).
 *
 * The terminal outcome is delivered separately, by decidesk dispatching a
 * `DecisionConcludedEvent` consumed by {@see \OCA\Dossiq\Listener\DecisionConcludedListener}.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dossiq-delegation-via-events/specs/contract-decision-delegation/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Raises decidesk Decisions (via `DecisionRequestedEvent`) for contract /
 * besluit decisions.
 *
 * @spec openspec/changes/dossiq-delegation-via-events/specs/contract-decision-delegation/spec.md
 */
class ContractDecisionDelegationService {
	/**
	 * Decision types supported by the contract delegation surface.
	 */
	public const DECISION_TYPE_CONTRACT_RENEWAL = 'contract-renewal';
	public const DECISION_TYPE_REPORT_ADOPTION = 'report-adoption';
	public const DECISION_TYPE_BEZWAAR = 'bezwaar-beslissing';

	/**
	 * Decision types for the remaining decision/advice flows delegated by
	 * `procest-delegate-remaining-decisions-to-decidesk` (ADR-005 decisionType).
	 */
	public const DECISION_TYPE_BEZWAAR_DECISION = 'bezwaar-decision';
	public const DECISION_TYPE_ADVICE = 'advice';

	/**
	 * Every spelling of the decision-request event FQN, newest first.
	 *
	 * Guarded by class_exists so dossiq stays installable without the decision
	 * app, which is an optional runtime dependency.
	 *
	 * TWO SPELLINGS because a cross-app event class name is a RUNTIME lookup
	 * this app can only follow, never move. The app renamed its namespace from
	 * OCA\Decidesk to OCA\Decidiq with no compatibility alias, and this constant
	 * named only the old one — so the guard below started throwing "decidesk is
	 * not installed" on an instance where it very much was, and every contract
	 * decision was blocked by a message that pointed at the wrong problem.
	 *
	 * @var array<int, string>
	 */
	private const DECISION_REQUESTED_EVENTS = [
		'\\OCA\\Decidiq\\Event\\DecisionRequestedEvent',
		'\\OCA\\Decidesk\\Event\\DecisionRequestedEvent',
	];

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $eventDispatcher Nextcloud typed event dispatcher.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IEventDispatcher $eventDispatcher,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Raise a decidesk Decision for a contract approval / renewal / sign-off.
	 *
	 * Dispatches a `DecisionRequestedEvent` synchronously and reads the result
	 * the decidesk listener writes back. FAILS CLOSED: when decidesk is not
	 * installed, did not handle the event, or returned no decisionId, this
	 * method throws — it never silently returns null / auto-approves (mirrors
	 * hydra-gate-unsafe-auth-resolver).
	 *
	 * @param string $caseRef The ZGW case reference (UUID) that owns this decision.
	 * @param string $contractRef The contract object UUID.
	 * @param string $decisionType Decision type slug (e.g. self::DECISION_TYPE_CONTRACT_RENEWAL).
	 * @param array<string,mixed> $subject Subject fields: subjectRegister, subjectSchema, subjectId, subjectLabel.
	 * @param array<string,mixed> $mandateContext Mandate context: requestedBy, mandateRole, mandateScope.
	 *
	 * @return string The decidesk decisionRef (UUID) to persist on the case.
	 *
	 * @throws RuntimeException When decidesk is unavailable or the Decision could not be created.
	 *
	 * @spec openspec/changes/dossiq-delegation-via-events/specs/contract-decision-delegation/spec.md#requirement-req-pdcd-001-contract-decisions-are-raised-as-decidesk-decisions-via-events
	 * @spec openspec/changes/dossiq-delegation-via-events/specs/contract-decision-delegation/spec.md#requirement-req-pdcd-002-delegation-fails-closed-when-decidesk-is-unavailable
	 */
	public function raiseContractDecision(
		string $caseRef,
		string $contractRef,
		string $decisionType,
		array $subject,
		array $mandateContext,
	): string {
		return $this->dispatchDecisionRequest(
			decisionType: $decisionType,
			externalReference: $caseRef,
			subject: [
				'subjectRegister' => (string)($subject['subjectRegister'] ?? ''),
				'subjectSchema' => (string)($subject['subjectSchema'] ?? ''),
				'subjectId' => (string)($subject['subjectId'] ?? $contractRef),
				'subjectLabel' => (string)($subject['subjectLabel'] ?? ''),
			],
			actorId: (string)($mandateContext['requestedBy'] ?? ''),
			payload: [
				'title' => (string)($subject['subjectLabel'] ?? ''),
				'context' => $mandateContext,
			],
		);
	}//end raiseContractDecision()

	/**
	 * Raise a decidesk Decision of an arbitrary decisionType. This is the shared
	 * core reused by the remaining decision/advice delegation siblings
	 * (BezwaarDecisionDelegationService, AdviceDelegationService) so there is
	 * exactly one delegation mechanism (the event dispatch).
	 *
	 * FAILS CLOSED: when decidesk is unavailable or did not handle the event
	 * this method throws — it never silently returns null / auto-decides.
	 *
	 * @param string $decisionType Decision type slug (ADR-005), e.g. self::DECISION_TYPE_ADVICE.
	 * @param string $externalReference The ZGW case/subject reference persisted on the decidesk Decision.
	 * @param array<string,mixed> $subject Subject fields: subjectRegister, subjectSchema, subjectId, subjectLabel.
	 * @param array<string,mixed> $context Optional decision context (disposition, reasoning, legalBasis, etc.).
	 *
	 * @return string The decidesk decisionRef (UUID) to persist on the case.
	 *
	 * @throws RuntimeException When decidesk is unavailable or the Decision could not be created.
	 *
	 * @spec openspec/changes/dossiq-delegation-via-events/specs/contract-decision-delegation/spec.md#requirement-req-pdcd-001-contract-decisions-are-raised-as-decidesk-decisions-via-events
	 * @spec openspec/changes/dossiq-delegation-via-events/specs/contract-decision-delegation/spec.md#requirement-req-pdcd-002-delegation-fails-closed-when-decidesk-is-unavailable
	 */
	public function raiseDecision(
		string $decisionType,
		string $externalReference,
		array $subject,
		array $context = [],
	): string {
		return $this->dispatchDecisionRequest(
			decisionType: $decisionType,
			externalReference: $externalReference,
			subject: [
				'subjectRegister' => (string)($subject['subjectRegister'] ?? ''),
				'subjectSchema' => (string)($subject['subjectSchema'] ?? ''),
				'subjectId' => (string)($subject['subjectId'] ?? ''),
				'subjectLabel' => (string)($subject['subjectLabel'] ?? ''),
			],
			actorId: (string)($context['actorId'] ?? ''),
			payload: [
				'title' => (string)($subject['subjectLabel'] ?? ''),
				'context' => $context,
			],
		);
	}//end raiseDecision()

	/**
	 * Build, dispatch and resolve a decidesk `DecisionRequestedEvent`.
	 *
	 * Guarded by class_exists — when decidesk is not installed the method fails
	 * closed (throws). After `dispatchTyped()` the decidesk listener has written
	 * `isHandled()` / `getDecisionId()` onto the event synchronously; when the
	 * event is not handled or carries no decisionId the method fails closed.
	 *
	 * @param string $decisionType The decision type slug.
	 * @param string $externalReference The ZGW case/subject reference.
	 * @param array<string,mixed> $subject Subject fields (subjectRegister/Schema/Id/Label).
	 * @param string $actorId The requesting actor id (may be empty).
	 * @param array<string,mixed> $payload Decision body payload (title/text/decisionDate/outcome/context).
	 *
	 * @return string The decidesk decisionId.
	 *
	 * @throws RuntimeException When decidesk is unavailable or did not handle the request.
	 */
	private function dispatchDecisionRequest(
		string $decisionType,
		string $externalReference,
		array $subject,
		string $actorId,
		array $payload,
	): string {
		$eventClass = $this->resolveRequestEventClass();

		// REQ-PDCD-002: fail closed when the decision app is not installed.
		if ($eventClass === null) {
			$this->logger->error(
				'ContractDecisionDelegationService: the decision app is not installed (DecisionRequestedEvent missing under any known namespace); failing closed',
				[
					'externalReference' => $externalReference,
					'decisionType' => $decisionType,
					'tried' => self::DECISION_REQUESTED_EVENTS,
				]
			);
			throw new RuntimeException(
				'Decision service unavailable: the decision app is not installed. Decision cannot proceed.'
			);
		}

		try {
			// Positional ctor args (decidesk contract): sourceApp, subjectRegister,
			// subjectSchema, subjectId, subjectLabel, decisionType, actorId,
			// payload, externalReference, correlationId.
			//
			// sourceApp is FROZEN at `procest`: it is this app's id AS DECIDESK
			// KNOWS IT, not our own app id. decidesk still ships
			// `<id>decidesk</id>`, matches this value exactly, and echoes it back
			// to DecisionConcludedListener::SOURCE_APP. Renaming it here silently
			// drops every in-flight and already-persisted decision. It moves only
			// in a coordinated pass that moves emitter and receiver together.
			$event = new $eventClass(
				'procest',
				(string)$subject['subjectRegister'],
				(string)$subject['subjectSchema'],
				(string)$subject['subjectId'],
				(string)$subject['subjectLabel'],
				$decisionType,
				$actorId,
				$payload,
				$externalReference,
				$externalReference
			);

			$this->eventDispatcher->dispatchTyped($event);
		} catch (Throwable $e) {
			$this->logger->error(
				'ContractDecisionDelegationService: DecisionRequestedEvent dispatch failed',
				['externalReference' => $externalReference, 'error' => $e->getMessage()]
			);
			// REQ-PDCD-002: re-throw to fail closed; caller must not proceed.
			throw new RuntimeException('Decision service error: ' . $e->getMessage(), 0, $e);
		}//end try

		// REQ-PDCD-002: the decidesk listener writes isHandled()/getDecisionId()
		// back onto the event synchronously. Anything else fails closed.
		$handled = (bool)$event->isHandled();
		$decisionId = $event->getDecisionId();
		if ($handled === false || $decisionId === null || $decisionId === '') {
			$this->logger->error(
				'ContractDecisionDelegationService: decidesk did not handle the decision request; failing closed',
				['externalReference' => $externalReference, 'decisionType' => $decisionType, 'handled' => $handled]
			);
			throw new RuntimeException('Decision service unavailable: decidesk did not handle the decision request. Decision cannot proceed.');
		}

		$this->logger->info(
			'ContractDecisionDelegationService: decidesk Decision raised via event',
			['externalReference' => $externalReference, 'decisionType' => $decisionType, 'decisionRef' => (string)$decisionId]
		);

		return (string)$decisionId;
	}//end dispatchDecisionRequest()
	/**
	 * The first decision-request event class that actually exists.
	 *
	 * Returns null when NONE does, which the caller turns into a fail-closed
	 * refusal. Resolving rather than assuming is the whole point: this app can
	 * only follow the other app's namespace, and a hard-coded spelling turns a
	 * rename over there into a broken feature over here.
	 *
	 * @return string|null The event FQN, or null when the decision app is absent.
	 */
	private function resolveRequestEventClass(): ?string {
		foreach (self::DECISION_REQUESTED_EVENTS as $candidate) {
			if (class_exists($candidate) === true) {
				return $candidate;
			}
		}

		return null;
	}//end resolveRequestEventClass()
}//end class
