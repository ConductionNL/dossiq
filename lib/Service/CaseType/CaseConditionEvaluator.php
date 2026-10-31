<?php

/**
 * A JSONLogic condition over a case, evaluated by OpenRegister's flow expressions.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseType
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseType;

/**
 * The same evaluation a rule flow's filter does: the case under `json`.
 *
 * Null means OpenRegister's evaluator is not there, so the caller decides how
 * to read an unanswerable condition rather than this class guessing.
 *
 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
 */
class CaseConditionEvaluator {

	/**
	 * OpenRegister's expression class.
	 *
	 * @var string
	 */
	private const EXPRESSIONS = 'OCA\OpenRegister\Service\Flow\FlowExpression';

	/**
	 * Whether the condition holds for the case.
	 *
	 * @param array<string, mixed> $condition The JSONLogic expression, as RuleConditionLogic writes it.
	 * @param array<string, mixed> $case      The case.
	 *
	 * @return bool|null True or false, or null when no evaluator is available.
	 *
	 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
	 */
	public function holds(array $condition, array $case): ?bool {
		if (class_exists(self::EXPRESSIONS) === false) {
			return null;
		}

		return (bool)call_user_func([self::EXPRESSIONS, 'isTrue'], $condition, ['json' => $case]);
	}//end holds()
}//end class
