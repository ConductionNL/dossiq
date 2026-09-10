<?php

/**
 * What a status asks for, as actions the dispatcher already knows how to run.
 *
 * A case type says which statuses a case walks through; `statusType.checklist`
 * says what has to be done in each of them. This class is the one reader of
 * that list: it turns the items into `createTask` actions, so the work arrives
 * with the phase instead of living in a handler's memory.
 *
 * WHY THE LIST IS ON THE STATUS AND NOT ON THE TRANSITION. A status can be
 * reached over more than one transition, and by an admin's free-form move that
 * has no transition at all. Hanging the work off the road into the phase means
 * the same phase brings different work depending on how you got there.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
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
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

use OCA\Dossiq\Service\Task\EngineTaskInbox;
use Psr\Log\LoggerInterface;

/**
 * Reads a status's checklist and expands it into createTask actions.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
class StatusChecklist {

	/**
	 * Who a checklist task goes to.
	 *
	 * The status checklist has no per-item assignee to author, and a checklist
	 * task belongs to the case, so it goes to the case's handler. Written as
	 * the same template the shipped flow declarations use, so one resolver
	 * answers for both and there is one spelling to learn.
	 */
	private const CHECKLIST_ASSIGNEE = '{{ case.assignee }}';

	/**
	 * Constructor.
	 *
	 * @param EngineTaskInbox  $engineTasks      The engine's task reader.
	 * @param StatusTypeLookup $statusTypeLookup Resolves the status row.
	 * @param LoggerInterface  $logger           Logger.
	 */
	public function __construct(
		private readonly EngineTaskInbox $engineTasks,
		private readonly StatusTypeLookup $statusTypeLookup,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The checklist items a status declares.
	 *
	 * Items are normalised to `{title, required}` and an item without a title
	 * is dropped: it names no task, so it can neither be created nor completed,
	 * and a required one would hold the case forever on nothing.
	 *
	 * @param string $statusTypeId The statusType UUID.
	 *
	 * @return array<int, array{title: string, required: bool}> The items.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function itemsFor(string $statusTypeId): array {
		$raw = ($this->statusTypeLookup->rowFor(statusTypeId: $statusTypeId)['checklist'] ?? []);

		// The property is declared as an array, but a store that round-trips it
		// through a text column hands back the JSON. Reading only the array
		// shape is how a checklist silently becomes an empty list.
		if (is_string($raw) === true) {
			$decoded = json_decode($raw, true);
			$raw = [];
			if (is_array($decoded) === true) {
				$raw = $decoded;
			}
		}

		if (is_array($raw) === false) {
			return [];
		}

		$items = [];
		foreach ($raw as $item) {
			if (is_array($item) === false) {
				continue;
			}

			$title = trim((string)($item['title'] ?? ''));
			if ($title === '') {
				continue;
			}

			$items[] = ['title' => $title, 'required' => (bool)($item['required'] ?? false)];
		}

		return $items;
	}//end itemsFor()

	/**
	 * The createTask actions a case entering this status has yet to receive.
	 *
	 * Every action carries `workflowStepId`, so a task says which status made
	 * it — which is both what the Tasks pane groups on and what the second
	 * entry into the same status reads to know it has been here before.
	 *
	 * @param string               $statusTypeId The statusType UUID being entered.
	 * @param array<string, mixed> $case         The case entering it.
	 * @param string               $userId       The acting identity, needed by
	 *                                           the engine read that decides
	 *                                           what this status already put
	 *                                           on the case.
	 *
	 * Every action also names its assignee, in the same spelling the shipped
	 * flow declarations use. It is written out rather than left implicit
	 * because a checklist action that named nobody produced a task with an
	 * empty `assignee`, and the task schema addresses its `taskAssigned`
	 * notification to that field: the work appeared and nobody was told. What
	 * the spelling resolves to is `AssigneeResolver`'s answer, not this
	 * class's, so the checklist and the flow ask one question.
	 *
	 * @return array<int, array{type: string, title: string, workflowStepId: string, assignee: string}> The actions.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function actionsFor(string $statusTypeId, array $case, string $userId): array {
		$items = $this->itemsFor(statusTypeId: $statusTypeId);
		if ($items === []) {
			return [];
		}

		$existing = $this->existingTitles(statusTypeId: $statusTypeId, case: $case, userId: $userId);

		$actions = [];
		foreach ($items as $item) {
			// D3: open or completed, a task already standing for this item on
			// this status means the case has been here before. Sending a case
			// back to intake and forward again must not double its work.
			if (in_array($item['title'], $existing, true) === true) {
				continue;
			}

			$actions[] = [
				'type' => 'createTask',
				'title' => $item['title'],
				'workflowStepId' => $statusTypeId,
				'assignee' => self::CHECKLIST_ASSIGNEE,
			];
		}

		return $actions;
	}//end actionsFor()

	/**
	 * The titles of the tasks this status already put on this case.
	 *
	 * @param string               $statusTypeId The statusType UUID.
	 * @param array<string, mixed> $case         The case.
	 * @param string               $userId       The acting identity.
	 *
	 * @return array<int, string> The titles, whatever status the tasks are at.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function existingTitles(string $statusTypeId, array $case, string $userId): array {
		return array_values(
			array_map(
				static fn (array $task): string => trim((string)($task['title'] ?? '')),
				$this->tasksFor(statusTypeId: $statusTypeId, case: $case, userId: $userId)
			)
		);
	}//end existingTitles()

	/**
	 * The tasks this status put on this case, as the engine holds them.
	 *
	 * 🔴 THIS READ AND THE WRITE MUST NAME THE SAME STORE. `actionsFor()`
	 * emits `createTask`, `CreateTaskHandler` writes it to the ENGINE, and
	 * this used to read `caseTask` OBJECTS. The two halves disagreeing is
	 * not a cosmetic drift: it broke both callers at once. `existingTitles()`
	 * saw nothing, so every re-entry into a status raised the whole
	 * checklist again as duplicate tasks; and `StatusChecklistGuard` saw
	 * nothing completed, so a status with a required item could never be
	 * left. That is why this reads the engine now.
	 *
	 * The `workflowStepId` narrowing is made HERE rather than by the engine,
	 * which is the one place in this migration where that is right: the
	 * inbox has no criteria for it, and the window is one CASE's tasks, not
	 * a page of everybody's. A case with more tasks than the limit is not a
	 * case anyone is working.
	 *
	 * @param string               $statusTypeId The statusType UUID.
	 * @param array<string, mixed> $case         The case.
	 * @param string               $userId       The acting identity.
	 *
	 * @return array<int, array<string, mixed>> The tasks.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function tasksFor(string $statusTypeId, array $case, string $userId): array {
		$caseId = (string)($case['id'] ?? ($case['uuid'] ?? ''));
		if ($caseId === '' || $statusTypeId === '') {
			return [];
		}

		$tasks = [];
		foreach ($this->engineTasks->forCase(caseId: $caseId, actor: $userId) as $task) {
			if ((string)($task['workflowStepId'] ?? '') === $statusTypeId) {
				$tasks[] = $task;
			}
		}

		$failure = $this->engineTasks->lastError();
		if ($failure !== '') {
			// A read that cannot answer reads as "no tasks yet", which on a
			// re-entry means a second copy of the list and, for the guard, an
			// item that counts as not done. Both are the safe direction of the
			// design's own trade-off: a duplicate task is cheaper than a missed
			// one, and a guard that fails closed cannot open a hole. It is
			// logged because the duplicate is otherwise unattributable.
			$this->logger->error(
				'StatusChecklist: reading the status tasks failed',
				['engine' => $failure, 'statusType' => $statusTypeId],
			);
		}

		return $tasks;
	}//end tasksFor()
}//end class
