<?php

/**
 * Procest Contract Decision Delegation Service
 *
 * Delegates contract approval / renewal / besluit decisions to decidesk via
 * the Nextcloud event dispatcher (decidesk's merged event contract). procest
 * keeps ZGW case management; decidesk owns the deciding. This service:
 *
 * - Raises a decidesk Decision by dispatching `DecisionRequestedEvent`.
 * - Reads the synchronous result the decidesk listener writes back onto the
 *   event (`isHandled()` / `getDecisionId()`).
 * - FAILS CLOSED when decidesk is unavailable (never auto-approves).
 *
 * The terminal outcome is delivered separately, by decidesk dispatching a
 * `DecisionConcludedEvent` consumed by {@see \OCA\Procest\Listener\DecisionConcludedListener}.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/procest-delegation-via-events/specs/contract-decision-delegation/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Raises decidesk Decisions (via `DecisionRequestedEvent`) for contract /
 * besluit decisions.
 *
 * @spec openspec/changes/procest-delegation-via-events/specs/contract-decision-delegation/spec.md
 */
class ContractDecisionDelegationService
{
    /**
     * Decision types supported by the contract delegation surface.
     */
    public const DECISION_TYPE_CONTRACT_RENEWAL = 'contract-renewal';
    public const DECISION_TYPE_REPORT_ADOPTION  = 'report-adoption';
    public const DECISION_TYPE_BEZWAAR          = 'bezwaar-beslissing';

    /**
     * Decision types for the remaining decision/advice flows delegated by
     * `procest-delegate-remaining-decisions-to-decidesk` (ADR-005 decisionType).
     */
    public const DECISION_TYPE_BEZWAAR_DECISION = 'bezwaar-decision';
    public const DECISION_TYPE_ADVICE           = 'advice';

    /**
     * The decidesk request-event FQN. Guarded by class_exists so procest stays
     * installable without decidesk (decidesk is an optional runtime dependency).
     */
    private const DECISION_REQUESTED_EVENT = '\\OCA\\Decidesk\\Event\\DecisionRequestedEvent';

    /**
     * Constructor.
     *
     * @param IEventDispatcher $eventDispatcher Nextcloud typed event dispatcher.
     * @param LoggerInterface  $logger          Logger.
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
     * @param string              $caseRef        The ZGW case reference (UUID) that owns this decision.
     * @param string              $contractRef    The contract object UUID.
     * @param string              $decisionType   Decision type slug (e.g. self::DECISION_TYPE_CONTRACT_RENEWAL).
     * @param array<string,mixed> $subject        Subject fields: subjectRegister, subjectSchema, subjectId, subjectLabel.
     * @param array<string,mixed> $mandateContext Mandate context: requestedBy, mandateRole, mandateScope.
     *
     * @return string The decidesk decisionRef (UUID) to persist on the case.
     *
     * @throws RuntimeException When decidesk is unavailable or the Decision could not be created.
     *
     * @spec openspec/changes/procest-delegation-via-events/specs/contract-decision-delegation/spec.md#requirement-req-pdcd-001-contract-decisions-are-raised-as-decidesk-decisions-via-events
     * @spec openspec/changes/procest-delegation-via-events/specs/contract-decision-delegation/spec.md#requirement-req-pdcd-002-delegation-fails-closed-when-decidesk-is-unavailable
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
                'subjectRegister' => (string) ($subject['subjectRegister'] ?? ''),
                'subjectSchema'   => (string) ($subject['subjectSchema'] ?? ''),
                'subjectId'       => (string) ($subject['subjectId'] ?? $contractRef),
                'subjectLabel'    => (string) ($subject['subjectLabel'] ?? ''),
            ],
            actorId: (string) ($mandateContext['requestedBy'] ?? ''),
            payload: [
                'title'   => (string) ($subject['subjectLabel'] ?? ''),
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
     * @param string              $decisionType      Decision type slug (ADR-005), e.g. self::DECISION_TYPE_ADVICE.
     * @param string              $externalReference The ZGW case/subject reference persisted on the decidesk Decision.
     * @param array<string,mixed> $subject           Subject fields: subjectRegister, subjectSchema, subjectId, subjectLabel.
     * @param array<string,mixed> $context           Optional decision context (disposition, reasoning, legalBasis, etc.).
     *
     * @return string The decidesk decisionRef (UUID) to persist on the case.
     *
     * @throws RuntimeException When decidesk is unavailable or the Decision could not be created.
     *
     * @spec openspec/changes/procest-delegation-via-events/specs/contract-decision-delegation/spec.md#requirement-req-pdcd-001-contract-decisions-are-raised-as-decidesk-decisions-via-events
     * @spec openspec/changes/procest-delegation-via-events/specs/contract-decision-delegation/spec.md#requirement-req-pdcd-002-delegation-fails-closed-when-decidesk-is-unavailable
     */
    public function raiseDecision(
        string $decisionType,
        string $externalReference,
        array $subject,
        array $context=[],
    ): string {
        return $this->dispatchDecisionRequest(
            decisionType: $decisionType,
            externalReference: $externalReference,
            subject: [
                'subjectRegister' => (string) ($subject['subjectRegister'] ?? ''),
                'subjectSchema'   => (string) ($subject['subjectSchema'] ?? ''),
                'subjectId'       => (string) ($subject['subjectId'] ?? ''),
                'subjectLabel'    => (string) ($subject['subjectLabel'] ?? ''),
            ],
            actorId: (string) ($context['actorId'] ?? ''),
            payload: [
                'title'   => (string) ($subject['subjectLabel'] ?? ''),
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
     * @param string              $decisionType      The decision type slug.
     * @param string              $externalReference The ZGW case/subject reference.
     * @param array<string,mixed> $subject           Subject fields (subjectRegister/Schema/Id/Label).
     * @param string              $actorId           The requesting actor id (may be empty).
     * @param array<string,mixed> $payload           Decision body payload (title/text/decisionDate/outcome/context).
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
        $eventClass = self::DECISION_REQUESTED_EVENT;

        // REQ-PDCD-002: fail closed when decidesk is not installed.
        if (class_exists($eventClass) === false) {
            $this->logger->error(
                'ContractDecisionDelegationService: decidesk is not installed (DecisionRequestedEvent missing); failing closed',
                ['externalReference' => $externalReference, 'decisionType' => $decisionType]
            );
            throw new RuntimeException('Decision service unavailable: decidesk is not installed. Decision cannot proceed.');
        }

        try {
            // Positional ctor args (decidesk contract): sourceApp, subjectRegister,
            // subjectSchema, subjectId, subjectLabel, decisionType, actorId,
            // payload, externalReference, correlationId.
            $event = new $eventClass(
                'procest',
                (string) $subject['subjectRegister'],
                (string) $subject['subjectSchema'],
                (string) $subject['subjectId'],
                (string) $subject['subjectLabel'],
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
            throw new RuntimeException('Decision service error: '.$e->getMessage(), 0, $e);
        }//end try

        // REQ-PDCD-002: the decidesk listener writes isHandled()/getDecisionId()
        // back onto the event synchronously. Anything else fails closed.
        $handled    = (bool) $event->isHandled();
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
            ['externalReference' => $externalReference, 'decisionType' => $decisionType, 'decisionRef' => (string) $decisionId]
        );

        return (string) $decisionId;
    }//end dispatchDecisionRequest()
}//end class
