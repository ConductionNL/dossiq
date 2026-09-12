<?php

/**
 * Dossiq Checklist Guard evaluator.
 *
 * Guard config shape: `{type: 'checklist', taskId: <uuid>, requiredItems?: [<itemLabel>, ...]}`.
 * Loads the referenced task and verifies that every required checklist item
 * is marked `checked: true`. If `requiredItems` is omitted, all items on the
 * task must be checked.
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

use OCA\Dossiq\Service\Task\EngineTaskGateway;
use OCA\Dossiq\Service\Task\EngineTaskInbox;
use Psr\Log\LoggerInterface;

/**
 * Guard: verifies checklist items on the case's tasks are complete.
 *
 * `taskId` narrows the check to one task. A workflow TEMPLATE cannot know a
 * runtime task uuid, so a template's checklist guard names none and the guard
 * reads every task linked to the case — the same corpus the frontend's own
 * evaluator uses. `requiredItems` narrows that corpus to named labels, and a
 * named label the checklist does not carry counts as missing.
 *
 * @spec openspec/changes/status-transition-engine/tasks.md#T05
 */
class ChecklistGuard implements GuardEvaluatorInterface {

	/**
	 * Constructor.
	 *
	 * @param EngineTaskInbox $engineTasks The engine's task reader.
	 * @param EngineTaskGateway $engineTask The seam that reads one task.
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly EngineTaskInbox $engineTasks,
		private readonly EngineTaskGateway $engineTask,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Evaluate the checklist guard.
	 *
	 * @param array<string, mixed> $guardConfig Guard configuration
	 * @param array<string, mixed> $case Case object; its tasks are the corpus when no taskId is named
	 * @param string $userId Current user UID (unused)
	 *
	 * @return GuardResult
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function evaluate(array $guardConfig, array $case, string $userId): GuardResult {
		$taskId = (string)($guardConfig['taskId'] ?? '');
		if ($taskId !== '') {
			return $this->evaluateNamedTask(
				taskId: $taskId,
				requiredItems: ($guardConfig['requiredItems'] ?? null),
			);
		}

		return $this->evaluateCaseTasks(
			case: $case,
			userId: $userId,
			requiredItems: ($guardConfig['requiredItems'] ?? null),
		);
	}//end evaluate()

	/**
	 * Evaluate the checklist of the one task the guard names.
	 *
	 * @param string $taskId The task the guard names.
	 * @param mixed $requiredItems Optional allow-list of required labels.
	 *
	 * @return GuardResult
	 */
	private function evaluateNamedTask(string $taskId, mixed $requiredItems): GuardResult {
		$task = $this->engineTask->find(taskId: $taskId);
		if ($task === null) {
			// FAIL-CLOSED, and it matters here. A guard that passes because
			// the task could not be read is a guard that stops guarding the
			// moment the engine is unavailable, which is precisely when a
			// transition should not slip through.
			$this->logger->error(
				'ChecklistGuard: task load failed',
				['task' => $taskId, 'engine' => $this->engineTask->lastError()]
			);

			return new GuardResult(passed: false, failureMessage: 'Gekoppelde taak niet gevonden');
		}

		return $this->verdict(tasks: [$task], requiredItems: $requiredItems);
	}//end evaluateNamedTask()

	/**
	 * Evaluate the checklists of every task linked to the case.
	 *
	 * @param array<string, mixed> $case The case object.
	 * @param string $userId The acting identity the engine records.
	 * @param mixed $requiredItems Optional allow-list of required labels.
	 *
	 * @return GuardResult
	 */
	private function evaluateCaseTasks(array $case, string $userId, mixed $requiredItems): GuardResult {
		$caseId = (string)($case['id'] ?? ($case['uuid'] ?? ''));
		if ($caseId === '') {
			return new GuardResult(passed: false, failureMessage: 'Zaak niet herkend voor checklistcontrole');
		}

		$tasks = $this->engineTasks->forCase(caseId: $caseId, actor: $userId);

		// A case with no tasks and an engine that could not be read both
		// answer with an empty list, and the difference decides the
		// transition: no tasks means no unchecked items, which PASSES. So
		// the failure is asked for by name rather than inferred from the
		// count, and an unreadable engine fails closed.
		$failure = $this->engineTasks->lastError();
		if ($failure !== '') {
			$this->logger->error(
				'ChecklistGuard: case task list failed',
				['case' => $caseId, 'engine' => $failure]
			);

			return new GuardResult(passed: false, failureMessage: 'Taken van de zaak niet gevonden');
		}

		return $this->verdict(tasks: $tasks, requiredItems: $requiredItems);
	}//end evaluateCaseTasks()

	/**
	 * Turn a set of tasks into a guard verdict.
	 *
	 * @param array<int, mixed> $tasks The loaded task objects.
	 * @param mixed $requiredItems Optional allow-list of required labels.
	 *
	 * @return GuardResult
	 */
	private function verdict(array $tasks, mixed $requiredItems): GuardResult {
		$missing = $this->collectMissingItems(tasks: $tasks, requiredItems: $requiredItems);
		if ($missing === []) {
			return new GuardResult(passed: true);
		}

		return new GuardResult(
			passed: false,
			failureMessage: sprintf("%d checklistitem niet afgevinkt: '%s'", count($missing), $missing[0]),
			details: ['missing' => $missing],
		);
	}//end verdict()

	/**
	 * Collect the labels of checklist items that are not yet ticked off.
	 *
	 * When `requiredItems` is a non-empty array only those labels are
	 * considered, and a label the checklist does not carry at all counts as
	 * missing; otherwise every unchecked item with a label counts.
	 *
	 * @param array<int, mixed> $tasks The loaded task objects
	 * @param mixed $requiredItems Optional allow-list of required labels
	 *
	 * @return array<int, string>
	 */
	private function collectMissingItems(array $tasks, mixed $requiredItems): array {
		[$ticked, $unticked] = $this->labelStates(tasks: $tasks);

		if (is_array($requiredItems) === false || $requiredItems === []) {
			return array_values(array_unique($unticked));
		}

		// A named item that is nowhere to be found is missing, not satisfied:
		// an allow-list that silently passes when the checklist does not carry
		// the item at all is a guard that cannot fail.
		$missing = [];
		foreach ($requiredItems as $required) {
			$label = trim((string)$required);
			if ($label !== '' && in_array($label, $ticked, true) === false) {
				$missing[] = $label;
			}
		}

		return $missing;
	}//end collectMissingItems()

	/**
	 * Split every labelled checklist item across the tasks into ticked and not.
	 *
	 * @param array<int, mixed> $tasks The loaded task objects.
	 *
	 * @return array{0: array<int, string>, 1: array<int, string>} [ticked, unticked]
	 */
	private function labelStates(array $tasks): array {
		$ticked = [];
		$unticked = [];
		foreach ($tasks as $task) {
			if (is_array($task) === false) {
				continue;
			}

			foreach ($this->resolveItems(task: $task) as $item) {
				$label = '';
				if (is_array($item) === true) {
					$label = $this->itemLabel(item: $item);
				}

				if ($label === '') {
					continue;
				}

				if ((bool)($item['checked'] ?? false) === true) {
					$ticked[] = $label;
					continue;
				}

				$unticked[] = $label;
			}
		}//end foreach

		return [$ticked, $unticked];
	}//end labelStates()

	/**
	 * Read the checklist items off a task object, tolerating both shapes.
	 *
	 * @param array<string, mixed> $task The loaded task object
	 *
	 * @return array<int|string, mixed>
	 */
	private function resolveItems(array $task): array {
		// NO JSON-STRING BRANCH ANY MORE. `caseTask` stored `checklist` as a
		// JSON-encoded string and this method decoded it, because reading it
		// as an array yielded no items and every guard passed on the one
		// shape the store actually held. The engine refuses a string at write
		// time (`TaskBuilder::validChecklist`), so no engine row can carry
		// one, and tolerance nothing can produce is the kind of dead branch
		// that makes a guard look tested.
		$items = $task['checklist'] ?? ($task['items'] ?? []);

		if (is_array($items) === false) {
			return [];
		}

		return $items;
	}//end resolveItems()

	/**
	 * Read the display label off a single checklist item.
	 *
	 * @param array<string, mixed> $item A single checklist item
	 *
	 * @return string
	 */
	private function itemLabel(array $item): string {
		return (string)($item['label'] ?? ($item['name'] ?? ''));
	}//end itemLabel()

}//end class
