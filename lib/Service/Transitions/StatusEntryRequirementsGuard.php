<?php

/**
 * A status may require documents before a case enters it.
 *
 * Guard type: `statusEntryRequirements`. Not declared by a template: the engine
 * appends it to every transition with the status being entered as `toStatus`,
 * like the capacity guard. What a status requires is the case type's
 * `entryRequirements` block, keyed by statusType uuid, which the case type's
 * rules project at publish. Each requirement is a required document type,
 * checked by the same evaluator a template's `requiredDocument` guard uses.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

/**
 * Refuses entering a status while one of its required documents is missing.
 *
 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
 */
class StatusEntryRequirementsGuard implements GuardEvaluatorInterface {

	/**
	 * Constructor.
	 *
	 * @param CaseTypeReader        $caseTypes The case's case type.
	 * @param RequiredDocumentGuard $documents Whether the case carries a document of a type.
	 */
	public function __construct(
		private readonly CaseTypeReader $caseTypes,
		private readonly RequiredDocumentGuard $documents,
	) {
	}//end __construct()

	/**
	 * Evaluate the requirements of the status being entered.
	 *
	 * @param array<string, mixed> $guardConfig `{type, toStatus}`.
	 * @param array<string, mixed> $case        The case.
	 * @param string               $userId      The acting user.
	 *
	 * @return GuardResult The first unmet requirement's refusal, or a pass.
	 *
	 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
	 */
	public function evaluate(array $guardConfig, array $case, string $userId): GuardResult {
		$target = trim((string)($guardConfig['toStatus'] ?? ''));
		$caseTypeId = $this->caseTypeIdOf(case: $case);
		if ($target === '' || $caseTypeId === '') {
			return new GuardResult(passed: true);
		}

		$requirements = ($this->caseTypes->read(caseTypeId: $caseTypeId)['entryRequirements'][$target] ?? []);
		foreach ((array)$requirements as $requirement) {
			$documentType = trim((string)(((array)$requirement)['documentType'] ?? ''));
			if ($documentType === '') {
				continue;
			}

			$result = $this->documents->evaluate(
				guardConfig: ['type' => 'requiredDocument', 'documentType' => $documentType],
				case: $case,
				userId: $userId
			);
			if ($result->passed === false) {
				return $result;
			}
		}

		return new GuardResult(passed: true);
	}//end evaluate()

	/**
	 * The case type uuid a case names, expanded or not.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return string The uuid, or '' when the case names none.
	 */
	private function caseTypeIdOf(array $case): string {
		$caseType = ($case['caseType'] ?? '');
		if (is_array($caseType) === true) {
			return (string)($caseType['id'] ?? $caseType['uuid'] ?? '');
		}

		return (string)$caseType;
	}//end caseTypeIdOf()
}//end class
