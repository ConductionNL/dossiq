<?php

/**
 * Dossiq workflow step config check.
 *
 * Runs StepConfigValidator over every step of one workflow definition, the
 * check process-step-configuration puts on publish. The validator judges one
 * step; this walks the definition's `steps` (a JSON string, as the
 * workflowTemplate schema stores it) and hands each step its position, so an
 * error names the step an administrator has to open.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Workflow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/process-step-configuration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Workflow;

use OCA\Dossiq\Service\StepConfigValidator;

/**
 * Every step config error one workflow definition carries.
 *
 * @spec openspec/specs/process-step-configuration/spec.md
 */
class StepConfigCheck {

	/**
	 * The step config errors of one definition, in step order.
	 *
	 * The validator is given no case type schema. The case type row carries
	 * neither `properties` nor `roleTypes`, which is where the validator reads
	 * field and role references from, so those two reference checks are
	 * skipped exactly as the validator documents for an empty schema. The
	 * shape rules (SLA, escalation timing, auto-action keys) all run.
	 *
	 * @param array<string, mixed> $definition The workflow definition row.
	 *
	 * @return array<int, array{path: string, code: string, message: string}> The errors; empty when every step holds.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) StepConfigValidator is a pure validator whose
	 *                                       contract is a static validate() (the spec's REQ-001).
	 *
	 * @spec openspec/specs/process-step-configuration/spec.md
	 */
	public function errorsFor(array $definition): array {
		$errors = [];
		foreach ((new WorkflowJsonProperty())->decodeList(raw: ($definition['steps'] ?? '')) as $index => $step) {
			if (is_array($step) === false) {
				continue;
			}

			$errors = array_merge($errors, StepConfigValidator::validate(step: $step, stepIndex: (int)$index));
		}

		return $errors;
	}//end errorsFor()
}//end class
