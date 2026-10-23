<?php

/**
 * Dossiq Required Field Guard evaluator.
 *
 * Guard config shape: `{type: 'requiredField', field: 'result'}`.
 * Passes when `case[field]` is non-null, non-empty-string, non-empty-array.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

use OCP\IL10N;

/**
 * Guard: verifies a named case field is non-empty.
 *
 * @spec openspec/changes/status-transition-engine/tasks.md#T05
 */
class RequiredFieldGuard implements GuardEvaluatorInterface {
	/**
	 * Constructor.
	 *
	 * @param IL10N                     $l10n      Translates the refusal.
	 * @param SensitiveAnswerCheck|null $sensitive Whether a sensitive field is answered, read as the system.
	 */
	public function __construct(
		private readonly IL10N $l10n,
		private readonly ?SensitiveAnswerCheck $sensitive = null,
	) {
	}//end __construct()

	/**
	 * Evaluate the required-field guard.
	 *
	 * @param array<string, mixed> $guardConfig Guard configuration
	 * @param array<string, mixed> $case Case object
	 * @param string $userId Current user UID (unused)
	 *
	 * @return GuardResult
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function evaluate(array $guardConfig, array $case, string $userId): GuardResult {
		$field = (string)($guardConfig['field'] ?? '');
		if ($field === '') {
			return new GuardResult(passed: false, failureMessage: 'Required-field guard missing field');
		}

		$value = ($case[$field] ?? $this->answeredProperty(case: $case, field: $field));
		// A sensitive field's answer is not in the mover's read of the case;
		// it is checked as the system, and only its presence comes back.
		if ($value === null && $this->sensitive?->isAnswered(caseId: (string)($case['id'] ?? ''), name: $field) === true) {
			$value = true;
		}

		if ($value === null || $value === '' || (is_array($value) === true && count($value) === 0)) {
			return new GuardResult(
				passed: false,
				failureMessage: $this->l10n->t('Required field missing: %s', [$field]),
				details: ['field' => $field],
			);
		}

		return new GuardResult(passed: true, details: ['field' => $field]);
	}//end evaluate()

	/**
	 * Read a case-type property the case answers in its `properties` array.
	 *
	 * A case type declares its own extra fields as propertyDefinitions, and a
	 * case answers them in `properties[]` as `{propertyDefinition, name,
	 * value}` — not as columns on the case. `stemuitslag` is declared exactly
	 * that way by all three besluitvorming bundles, so a guard that only
	 * looked at the top level could never see the answer and the transition
	 * out of "Vergadering" was unreachable. Looking in both places is what
	 * makes a guard on a case-type property mean what its author wrote.
	 *
	 * @param array<string, mixed> $case The case object.
	 * @param string $field The property name the guard requires.
	 *
	 * @return string|null The answer, or null when the case does not answer it.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function answeredProperty(array $case, string $field): ?string {
		$entries = ($case['properties'] ?? null);
		if (is_array($entries) === false) {
			return null;
		}

		foreach ($entries as $entry) {
			if (is_array($entry) === false || (string)($entry['name'] ?? '') !== $field) {
				continue;
			}

			$value = trim((string)($entry['value'] ?? ''));
			if ($value !== '') {
				return $value;
			}
		}

		return null;
	}//end answeredProperty()
}//end class
