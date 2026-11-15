<?php

/**
 * Contract of ContractDecisionDelegationService
 *
 * The public surface of ContractDecisionDelegationService that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of ContractDecisionDelegationService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ContractDecisionDelegationServiceInterface {

	/**
	 * Decision types supported by the contract delegation surface.
	 */
	public const DECISION_TYPE_CONTRACT_RENEWAL = 'contract-renewal';

	public const DECISION_TYPE_REPORT_ADOPTION = 'report-adoption';

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
	 * @spec openspec/changes/archive/2026-09-09-dossiq-delegation-via-events/specs/contract-decision-delegation/spec.md#requirement-req-pdcd-001-contract-decisions-are-raised-as-decidesk-decisions-via-events
	 * @spec openspec/changes/archive/2026-09-09-dossiq-delegation-via-events/specs/contract-decision-delegation/spec.md#requirement-req-pdcd-002-delegation-fails-closed-when-decidesk-is-unavailable
	 */
	public function raiseContractDecision(
		string $caseRef,
		string $contractRef,
		string $decisionType,
		array $subject,
		array $mandateContext,
	): string;
}//end interface
