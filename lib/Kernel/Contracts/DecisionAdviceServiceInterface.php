<?php

/**
 * Contract of DecisionAdviceService
 *
 * The public surface of DecisionAdviceService that other components call, so they depend
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
 * Contract of DecisionAdviceService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DecisionAdviceServiceInterface {

	/**
	 * Ask the case type's decision table for advice and store it on the case.
	 *
	 * @param string $caseId The case.
	 * @param array<string, mixed> $inputs The values entered, by input name.
	 * @param string $userId Who asks.
	 *
	 * @return array<string, mixed> The stored advice.
	 *
	 * @throws \OCA\Dossiq\Service\DecisionAdvice\DecisionAdviceRefusal When the case, its table or an input is missing, or the table gives no answer.
	 *
	 * @spec openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md#requirement-a-handler-asks-the-case-types-decision-table-for-advice-on-the-case-req-lhs-case-01
	 */
	public function recommend(string $caseId, array $inputs, string $userId): array;

	/**
	 * Read stored advice.
	 *
	 * @param string $adviceId The advice.
	 *
	 * @return array<string, mixed> The advice.
	 *
	 * @throws \OCA\Dossiq\Service\DecisionAdvice\DecisionAdviceRefusal When it cannot be found.
	 *
	 * @spec openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md#requirement-a-handler-asks-the-case-types-decision-table-for-advice-on-the-case-req-lhs-case-01
	 */
	public function find(string $adviceId): array;

	/**
	 * What the advice dialog asks on a case: the table the case type names, the
	 * inputs it declares with the values its rules use, and the outcomes a
	 * handler can choose. A case type that names no table answers unavailable,
	 * so the case page hides the action instead of offering one that refuses.
	 *
	 * @param string $caseId The case.
	 *
	 * @return array{available: bool, decisionTable: string, inputs: list<array{name: string, type: string, options: list<string>}>,
	 *     outcomes: list<string>}
	 *
	 * @throws \OCA\Dossiq\Service\DecisionAdvice\DecisionAdviceRefusal When the case is unknown or the table cannot be read.
	 *
	 * @spec openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md
	 */
	public function form(string $caseId): array;

	/**
	 * Record the handler's choice: the recommendation accepted, or another
	 * outcome with a reason.
	 *
	 * @param array<string, mixed> $advice The stored advice, as {@see find()} read it.
	 * @param string $outcome The chosen outcome.
	 * @param string $reason Why it deviates from the recommendation.
	 * @param string $userId Who decides.
	 *
	 * @return array<string, mixed> The stored advice with the choice.
	 *
	 * @throws \OCA\Dossiq\Service\DecisionAdvice\DecisionAdviceRefusal When the outcome is missing, a deviation has no reason, or the choice was already made.
	 *
	 * @spec openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md#requirement-a-handler-asks-the-case-types-decision-table-for-advice-on-the-case-req-lhs-case-01
	 */
	public function decide(array $advice, string $outcome, string $reason, string $userId): array;
}//end interface
