<?php

/**
 * Contract of BezwaarDecisionDelegationService
 *
 * The public surface of BezwaarDecisionDelegationService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

/**
 * Contract of BezwaarDecisionDelegationService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BezwaarDecisionDelegationServiceInterface {

	/**
	 * Raise a decidesk `bezwaar-decision` Decision for a beslissing op bezwaar.
	 *
	 * The caller (Bezwaar/DecisionService) MUST have run the Awb validity
	 * matrix (7:11 disposition set, 7:12 reasoning+legalBasis, proceskosten,
	 * replacement guard) BEFORE invoking this — the domain rules stay in
	 * dossiq. FAILS CLOSED when decidesk is unavailable.
	 *
	 * @param string $objectionId The bezwaar/case reference (UUID) persisted on the decidesk Decision.
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
	public function raiseBezwaarDecision(string $objectionId, array $payload): string;
}//end interface
