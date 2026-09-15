<?php

/**
 * The declaration a given task on a given case was created under.
 *
 * `TaskDeclaration` normalises one block; this resolves WHICH block. A task is
 * named by its status and its title, because that is the pair the runtime
 * already carries: `StatusChecklist` expands a status's checklist into tasks
 * and stamps each one with `workflowStepId`, and the title is what the item
 * and the step agree on.
 *
 * 🔑 THE DEFINITION IS THE CASE'S, NOT THE CASE TYPE'S HEAD. A case keeps the
 * workflow version it was bound to, so a task declared on a case opened in
 * March keeps March's lead time and March's form even after a newer version is
 * published. `getDefinitionForCase()` already answers that question for every
 * other reader; asking the case type instead would quietly re-date the work on
 * every open case the day an administrator publishes.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Task
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

namespace OCA\Dossiq\Service\Task;

use OCA\Dossiq\Service\Workflow\WorkflowJsonProperty;
use OCA\Dossiq\Service\WorkflowDefinitionService;

/**
 * Resolves the per-task declaration a case's workflow holds for one task.
 *
 * @spec openspec/changes/task-as-a-first-class-record/specs/process-step-configuration/spec.md
 */
class TaskDeclarationReader {

	/**
	 * Constructor.
	 *
	 * @param WorkflowDefinitionService $definitions Resolves the case's bound workflow.
	 * @param WorkflowJsonProperty      $json        Decodes the JSON-encoded `steps` property.
	 */
	public function __construct(
		private readonly WorkflowDefinitionService $definitions,
		private readonly WorkflowJsonProperty $json,
	) {
	}//end __construct()

	/**
	 * The steps of the workflow this case is bound to.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<int, array<string, mixed>> The steps, or an empty list.
	 *
	 * @spec openspec/changes/task-as-a-first-class-record/specs/process-step-configuration/spec.md
	 */
	public function stepsForCase(string $caseId): array {
		if (trim($caseId) === '') {
			return [];
		}

		// 🔴 A WORKFLOW THAT CANNOT BE READ IS NOT A WORKFLOW THAT DECLARES
		// NOTHING, and the difference is a task created with the WRONG lead
		// time, the wrong team and none of its effects. That task looks
		// exactly like a correct one, and the case runs on its date. So a
		// storage failure travels up and the transition refuses, rather than
		// being caught here and turned into the unconfigured defaults.
		//
		// An ABSENT definition is a different answer and an ordinary one: a
		// case type that declares no workflow declares no per-task block
		// either, and every such task keeps the behaviour it had before this
		// block existed.
		$definition = $this->definitions->getDefinitionForCase(caseId: trim($caseId));
		if ($definition === null) {
			return [];
		}

		return self::stepsOf(definition: $definition, json: $this->json);
	}//end stepsForCase()

	/**
	 * The declaration for one task of one case.
	 *
	 * @param string $caseId       The case uuid.
	 * @param string $statusTypeId The statusType the task belongs to.
	 * @param string $title        The task's title.
	 *
	 * @return array<string, mixed> The normalised declaration; the unconfigured default when none matches.
	 *
	 * @spec openspec/changes/task-as-a-first-class-record/specs/process-step-configuration/spec.md
	 */
	public function forTask(string $caseId, string $statusTypeId, string $title): array {
		$step = self::matching(
			steps: $this->stepsForCase(caseId: $caseId),
			statusTypeId: $statusTypeId,
			title: $title
		);
		if ($step === null) {
			return TaskDeclaration::NONE;
		}

		return TaskDeclaration::of(step: $step);
	}//end forTask()

	/**
	 * The step a status and a title name, or null when none does.
	 *
	 * The status is matched only when the step declares one: a step with no
	 * status belongs to the whole process, and refusing to match it on a
	 * status it never named would make its declaration unreachable.
	 *
	 * @param array<int, array<string, mixed>> $steps        The steps.
	 * @param string                           $statusTypeId The statusType uuid.
	 * @param string                           $title        The task title.
	 *
	 * @return array<string, mixed>|null The step.
	 *
	 * @spec openspec/changes/task-as-a-first-class-record/specs/process-step-configuration/spec.md
	 */
	public static function matching(array $steps, string $statusTypeId, string $title): ?array {
		$wanted = trim($title);
		foreach ($steps as $step) {
			if (is_array($step) === false || TaskDeclaration::titleOf(step: $step) !== $wanted) {
				continue;
			}

			$status = TaskDeclaration::statusOf(step: $step);
			if ($status === '' || $status === trim($statusTypeId)) {
				return $step;
			}
		}

		return null;
	}//end matching()

	/**
	 * A definition's steps, decoded.
	 *
	 * Static so the publish validator can read the steps of a definition it
	 * already holds without a second lookup, and so the decoding rule lives
	 * in one place rather than in every caller.
	 *
	 * @param array<string, mixed>     $definition The workflow definition row.
	 * @param WorkflowJsonProperty     $json       The decoder.
	 *
	 * @return array<int, array<string, mixed>> The steps.
	 *
	 * @spec openspec/changes/task-as-a-first-class-record/specs/process-step-configuration/spec.md
	 */
	public static function stepsOf(array $definition, WorkflowJsonProperty $json): array {
		$steps = [];
		foreach ($json->decodeList(raw: ($definition['steps'] ?? '')) as $step) {
			if (is_array($step) === true) {
				$steps[] = $step;
			}
		}

		return $steps;
	}//end stepsOf()
}//end class
