<?php

/**
 * What a case type's rules are projected from: the rule flow document, the case type resolver and the condition logic.
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

use OCA\Dossiq\Service\CaseTypeResolver;

/**
 * What a case type's rules are projected from: the rule flow document, the case type resolver and the condition logic.
 *
 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
 */
final class CaseTypeRuleSources {
	/**
	 * Constructor.
	 *
	 * @param CaseTypeRuleFlowDocument $documents Builds the flow of one action.
	 * @param CaseTypeResolver|null    $resolver  The case type's blueprint, for its property definition names.
	 * @param RuleConditionLogic       $logic     Writes a requirement's condition.
	 */
	public function __construct(
		public readonly CaseTypeRuleFlowDocument $documents,
		public readonly ?CaseTypeResolver $resolver = null,
		public readonly RuleConditionLogic $logic = new RuleConditionLogic(),
	) {
	}//end __construct()
}//end class
