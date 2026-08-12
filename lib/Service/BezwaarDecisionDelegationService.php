<?php

/**
 * Procest Bezwaar Decision Delegation Service
 *
 * Delegates the beslissing-op-bezwaar (decision on objection) to decidesk via
 * the decidesk `DecisionRequestedEvent` (IEventDispatcher). procest keeps the
 * Awb domain rules and the ZGW Besluit recording (as a projection); decidesk
 * owns the *making* of the decision. This service is a thin sibling of
 * ContractDecisionDelegationService — it reuses that service's shared
 * event-dispatch raiseDecision core and only fixes the `bezwaar-decision`
 * decisionType + provenance. It does NOT add a second delegation mechanism.
 *
 *  - raiseBezwaarDecision() — raise a decidesk `bezwaar-decision` Decision.
 *
 * The terminal outcome is delivered by decidesk's `DecisionConcludedEvent`
 * (consumed by {@see \OCA\Procest\Listener\DecisionConcludedListener}), not by
 * a poll. FAILS CLOSED when decidesk is unavailable (never auto-decides).
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
 * @spec openspec/specs/remaining-decision-delegation/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

/**
 * Raises and consumes the decidesk `bezwaar-decision` Decision.
 *
 * @spec openspec/specs/remaining-decision-delegation/spec.md
 */
class BezwaarDecisionDelegationService {
	/**
	 * Constructor.
	 *
	 * @param ContractDecisionDelegationService $core Shared event-dispatch raiseDecision core.
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
	 * procest. FAILS CLOSED when decidesk is unavailable.
	 *
	 * @param string $bezwaarId The bezwaar/case reference (UUID) persisted on the decidesk Decision.
	 * @param array<string,mixed> $payload Decision payload: disposition, reasoning, legalBasis,
	 *                                     replacementDecision, subjectLabel, subjectRegister,
	 *                                     subjectSchema, subjectId.
	 *
	 * @return string The decidesk decisionRef (UUID) to persist on the case.
	 *
	 * @throws \RuntimeException When the decidesk leaf is unavailable or the Decision could not be created.
	 *
	 * @spec openspec/specs/remaining-decision-delegation/spec.md
	 * @spec openspec/specs/remaining-decision-delegation/spec.md#requirement-req-pdrd-002-delegation-fails-closed-when-decidesk-is-unavailable
	 */
	public function raiseBezwaarDecision(string $bezwaarId, array $payload): string {
		return $this->core->raiseDecision(
			decisionType: ContractDecisionDelegationService::DECISION_TYPE_BEZWAAR_DECISION,
			externalReference: $bezwaarId,
			subject: [
				'subjectRegister' => (string)($payload['subjectRegister'] ?? ''),
				'subjectSchema' => (string)($payload['subjectSchema'] ?? 'bezwaarDecision'),
				'subjectId' => (string)($payload['subjectId'] ?? $bezwaarId),
				'subjectLabel' => (string)($payload['subjectLabel'] ?? ''),
			],
			context: [
				'disposition' => (string)($payload['dispositionType'] ?? ($payload['disposition'] ?? '')),
				'reasoning' => (string)($payload['reasoning'] ?? ''),
				'legalBasis' => (string)($payload['legalBasis'] ?? ''),
				'replacementDecision' => (string)($payload['replacementDecision'] ?? ''),
			],
		);
	}//end raiseBezwaarDecision()
}//end class
