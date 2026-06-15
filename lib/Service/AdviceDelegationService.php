<?php

/**
 * Procest Advice Delegation Service
 *
 * Delegates advice/consultation decisions to decidesk via the OpenRegister
 * ADR-019 integration registry. Reused by three procest advice surfaces — the
 * BAC (bezwaarschriftencommissie) advice, the general adviesAanvraag, and the
 * consultatie/zienswijze — each of which maps to a decidesk `advice`
 * decisionType (ADR-005). It also exposes the voorstel→besluit
 * `report-adoption` raise. procest keeps its domain rules (BAC
 * panel-independence, advice IDOR gate) and consumes the outcome as a
 * projection; decidesk owns the *making* of the advice.
 *
 * This is a thin sibling of ContractDecisionDelegationService — it reuses that
 * service's shared registry-resolution + raiseDecision core and only fixes the
 * decisionType + provenance. It does NOT add a second integration mechanism.
 *
 * FAILS CLOSED when the decidesk leaf is unavailable (never auto-advises).
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
 * Raises and consumes decidesk `advice` / `report-adoption` Decisions.
 *
 * @spec openspec/changes/procest-delegate-remaining-decisions-to-decidesk/specs/remaining-decision-delegation/spec.md#requirement-req-pdrd-001-remaining-decisionadvice-flows-are-raised-as-decidesk-decisions
 */
class AdviceDelegationService
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
     * Raise a decidesk `advice` Decision for a BAC / adviesAanvraag / consultatie request.
     *
     * The caller MUST have run its procest domain rule (BAC panel-independence,
     * the advice IDOR gate) BEFORE invoking this. FAILS CLOSED when the
     * decidesk leaf is unavailable.
     *
     * @param string              $subjectSchema The procest subject schema (bacAdviceRequest, adviesAanvraag, consultation).
     * @param string              $subjectId     The subject object UUID.
     * @param array<string,mixed> $payload       Advice context + provenance: subjectRegister, subjectLabel, externalReference, question, adviceType, etc.
     *
     * @return string The decidesk decisionRef (UUID) to persist on the case.
     *
     * @throws \RuntimeException When the decidesk leaf is unavailable or the Decision could not be created.
     *
     * @spec openspec/changes/procest-delegate-remaining-decisions-to-decidesk/specs/remaining-decision-delegation/spec.md#requirement-req-pdrd-001-remaining-decisionadvice-flows-are-raised-as-decidesk-decisions
     * @spec openspec/changes/procest-delegate-remaining-decisions-to-decidesk/specs/remaining-decision-delegation/spec.md#requirement-req-pdrd-002-delegation-fails-closed-when-decidesk-is-unavailable
     */
    public function raiseAdviceDecision(string $subjectSchema, string $subjectId, array $payload = []): string
    {
        return $this->core->raiseDecision(
            decisionType: ContractDecisionDelegationService::DECISION_TYPE_ADVICE,
            externalReference: (string) ($payload['externalReference'] ?? $subjectId),
            subject: [
                'subjectRegister' => (string) ($payload['subjectRegister'] ?? ''),
                'subjectSchema'   => $subjectSchema,
                'subjectId'       => $subjectId,
                'subjectLabel'    => (string) ($payload['subjectLabel'] ?? ''),
            ],
            context: [
                'question'   => (string) ($payload['question'] ?? ''),
                'adviceType' => (string) ($payload['adviceType'] ?? ''),
                'adviseur'   => (string) ($payload['adviseur'] ?? ''),
            ],
        );
    }//end raiseAdviceDecision()

    /**
     * Raise a decidesk `report-adoption` Decision for a voorstel besluit-registration.
     *
     * The caller (voorstel besluit-registration node) keeps the parafeerroute
     * untouched; only the besluit *decision* is delegated. FAILS CLOSED when
     * the decidesk leaf is unavailable.
     *
     * @param string              $voorstelId The voorstel UUID.
     * @param array<string,mixed> $payload    Provenance + context: subjectRegister, subjectLabel, externalReference, title, governingBody.
     *
     * @return string The decidesk decisionRef (UUID) to persist on the case.
     *
     * @throws \RuntimeException When the decidesk leaf is unavailable or the Decision could not be created.
     *
     * @spec openspec/changes/procest-delegate-remaining-decisions-to-decidesk/specs/remaining-decision-delegation/spec.md#requirement-req-pdrd-001-remaining-decisionadvice-flows-are-raised-as-decidesk-decisions
     * @spec openspec/changes/procest-delegate-remaining-decisions-to-decidesk/specs/remaining-decision-delegation/spec.md#requirement-req-pdrd-002-delegation-fails-closed-when-decidesk-is-unavailable
     */
    public function raiseVoorstelBesluit(string $voorstelId, array $payload = []): string
    {
        return $this->core->raiseDecision(
            decisionType: ContractDecisionDelegationService::DECISION_TYPE_REPORT_ADOPTION,
            externalReference: (string) ($payload['externalReference'] ?? $voorstelId),
            subject: [
                'subjectRegister' => (string) ($payload['subjectRegister'] ?? ''),
                'subjectSchema'   => 'voorstel',
                'subjectId'       => $voorstelId,
                'subjectLabel'    => (string) ($payload['subjectLabel'] ?? ($payload['title'] ?? '')),
            ],
            context: [
                'title'         => (string) ($payload['title'] ?? ''),
                'governingBody' => (string) ($payload['governingBody'] ?? ''),
                'explanation'   => (string) ($payload['explanation'] ?? ''),
            ],
        );
    }//end raiseVoorstelBesluit()

    /**
     * Consume the outcome of a decidesk `advice` / `report-adoption` Decision.
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
