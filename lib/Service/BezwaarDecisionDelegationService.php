<?php

/**
 * Procest Bezwaar Decision Delegation Service
 *
 * Delegates the beslissing-op-bezwaar (decision on objection) to decidesk via
 * the OpenRegister ADR-019 integration registry. procest keeps the Awb domain
 * rules and the ZGW Besluit recording (as a projection); decidesk owns the
 * *making* of the decision. This service is a thin sibling of
 * ContractDecisionDelegationService — it reuses that service's shared
 * registry-resolution + raiseDecision core and only fixes the
 * `bezwaar-decision` decisionType + provenance. It does NOT add a second
 * integration mechanism.
 *
 *  - raiseBezwaarDecision() — raise a decidesk `bezwaar-decision` Decision.
 *  - consumeOutcome()       — read the decided outcome (delegates to the core).
 *
 * FAILS CLOSED when the decidesk leaf is unavailable (never auto-decides).
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
 * @spec openspec/changes/procest-delegate-remaining-decisions-to-decidesk/specs/remaining-decision-delegation/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

/**
 * Raises and consumes the decidesk `bezwaar-decision` Decision.
 *
 * @spec openspec/changes/procest-delegate-remaining-decisions-to-decidesk/specs/remaining-decision-delegation/spec.md#requirement-req-pdrd-001-remaining-decisionadvice-flows-are-raised-as-decidesk-decisions
 */
class BezwaarDecisionDelegationService
{
    /**
     * Constructor.
     *
     * @param ContractDecisionDelegationService $core Shared registry-resolution + raiseDecision core (ADR-019).
     */
    public function __construct(
        private readonly ContractDecisionDelegationService $core,
    ) {
    }//end __construct()

    /**
     * Raise a decidesk `bezwaar-decision` Decision for a beslissing op bezwaar.
     *
     * The caller (Bezwaar/DecisionService) MUST have run the Awb validity
     * matrix (7:11 disposition set, 7:12 reasoning+legalBasis, proceskosten,
     * replacement guard) BEFORE invoking this — the domain rules stay in
     * procest. FAILS CLOSED when the decidesk leaf is unavailable.
     *
     * @param string              $bezwaarId The bezwaar/case reference (UUID) persisted on the decidesk Decision.
     * @param array<string,mixed> $payload   Decision payload: disposition, reasoning, legalBasis, replacementDecision, subjectLabel, subjectRegister, subjectSchema, subjectId.
     *
     * @return string The decidesk decisionRef (UUID) to persist on the case.
     *
     * @throws \RuntimeException When the decidesk leaf is unavailable or the Decision could not be created.
     *
     * @spec openspec/changes/procest-delegate-remaining-decisions-to-decidesk/specs/remaining-decision-delegation/spec.md#requirement-req-pdrd-001-remaining-decisionadvice-flows-are-raised-as-decidesk-decisions
     * @spec openspec/changes/procest-delegate-remaining-decisions-to-decidesk/specs/remaining-decision-delegation/spec.md#requirement-req-pdrd-002-delegation-fails-closed-when-decidesk-is-unavailable
     */
    public function raiseBezwaarDecision(string $bezwaarId, array $payload): string
    {
        return $this->core->raiseDecision(
            decisionType: ContractDecisionDelegationService::DECISION_TYPE_BEZWAAR_DECISION,
            externalReference: $bezwaarId,
            subject: [
                'subjectRegister' => (string) ($payload['subjectRegister'] ?? ''),
                'subjectSchema'   => (string) ($payload['subjectSchema'] ?? 'bezwaarDecision'),
                'subjectId'       => (string) ($payload['subjectId'] ?? $bezwaarId),
                'subjectLabel'    => (string) ($payload['subjectLabel'] ?? ''),
            ],
            context: [
                'disposition'         => (string) ($payload['dispositionType'] ?? ($payload['disposition'] ?? '')),
                'reasoning'           => (string) ($payload['reasoning'] ?? ''),
                'legalBasis'          => (string) ($payload['legalBasis'] ?? ''),
                'replacementDecision' => (string) ($payload['replacementDecision'] ?? ''),
            ],
        );
    }//end raiseBezwaarDecision()

    /**
     * Consume the outcome of a decidesk `bezwaar-decision` Decision.
     *
     * @param string $decisionRef The decidesk Decision UUID.
     *
     * @return array{result:string, decidedAt:string, motivering:string, signer:string, method:string, raw:array<string,mixed>}
     *
     * @throws \RuntimeException When the decidesk leaf is unavailable or the outcome cannot be read.
     *
     * @spec openspec/changes/procest-delegate-remaining-decisions-to-decidesk/specs/remaining-decision-delegation/spec.md#requirement-req-pdrd-003-the-zgw-besluitadvice-record-is-a-projection-of-the-decidesk-outcome
     */
    public function consumeOutcome(string $decisionRef): array
    {
        return $this->core->consumeOutcome(decisionRef: $decisionRef);
    }//end consumeOutcome()
}//end class
