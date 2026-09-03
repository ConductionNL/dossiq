<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Test stub for OpenRegister's shared decision-table evaluator.
 *
 * dossiq injects this into DecisionTableController and EvaluateDecisionHandler.
 * Those tests mock it, so only the signature has to be right — and the
 * signature is the whole point: if the real evaluate() changes shape, dossiq's
 * suite is where that surfaces. Mirrors openregister
 * lib/Service/Dmn/DecisionTableEvaluator.php.
 *
 * The hit-policy behaviour is tested in openregister against the real class,
 * not here.
 *
 * @category Test
 * @package  OCA\OpenRegister\Service\Dmn
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Dmn;

/**
 * Stub of OpenRegister's DecisionTableEvaluator.
 */
class DecisionTableEvaluator {

	/**
	 * Constructor.
	 *
	 * @param UnaryTestEvaluator|null $evaluator The cell-expression evaluator.
	 */
	public function __construct(private readonly ?UnaryTestEvaluator $evaluator = null) {

	}//end __construct()

	/**
	 * Evaluate a decision table.
	 *
	 * @param array<string, mixed> $decisionTable The table definition.
	 * @param array<string, mixed> $inputs        Caller-supplied input values.
	 *
	 * @return array{outputs: array<string, mixed>, matchedRuleIds: array<int, string>, hitPolicy: string} The outcome.
	 */
	public function evaluate(array $decisionTable, array $inputs): array {
		return ['outputs' => [], 'matchedRuleIds' => [], 'hitPolicy' => 'UNIQUE'];

	}//end evaluate()

}//end class
