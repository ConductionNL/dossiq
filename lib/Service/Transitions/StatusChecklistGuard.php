<?php

/**
 * A required checklist item holds the case in its status.
 *
 * Guard type: `statusChecklist`. It takes no configuration, because it is not
 * a guard a template declares: it is evaluated on EVERY transition out of a
 * status, and reads what to check off the status the case is in.
 *
 * WHY IT IS IMPLICIT. A required item that only bites where somebody
 * remembered to write the guard onto a transition is a required item that
 * anybody can walk around by taking another road out of the phase. The list is
 * authored on the status, so the check belongs to the status too.
 *
 * A required item with NO task on the case counts as not done. That is the
 * free-form move that happened before the checklist existed, and a guard that
 * read "no task" as "nothing to do" would open exactly the hole the required
 * flag is there to close.
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

use OCP\IL10N;

/**
 * Guard: every required item of the current status has a completed task.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
class StatusChecklistGuard implements GuardEvaluatorInterface {

	/**
	 * The task status that counts as done.
	 */
	private const COMPLETED = 'completed';

	/**
	 * Constructor.
	 *
	 * @param StatusChecklist $checklist The status's checklist and its tasks.
	 * @param IL10N           $l10n      The localisation service.
	 */
	public function __construct(
		private readonly StatusChecklist $checklist,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Evaluate the implicit status-checklist guard.
	 *
	 * @param array<string, mixed> $guardConfig Guard configuration (unused: the status carries it).
	 * @param array<string, mixed> $case        The case being moved.
	 * @param string               $userId      Current user UID, the identity the engine is read as.
	 *
	 * @return GuardResult The verdict, naming the first item that is not done.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function evaluate(array $guardConfig, array $case, string $userId): GuardResult {
		$statusTypeId = (string)($case['status'] ?? '');
		$required = array_values(
			array_filter(
				$this->checklist->itemsFor(statusTypeId: $statusTypeId),
				static fn (array $item): bool => $item['required'] === true
			)
		);
		if ($required === []) {
			return new GuardResult(passed: true);
		}

		$completed = $this->completedTitles(statusTypeId: $statusTypeId, case: $case, actor: $userId);

		$open = [];
		foreach ($required as $item) {
			if (in_array($item['title'], $completed, true) === false) {
				$open[] = $item['title'];
			}
		}

		if ($open === []) {
			return new GuardResult(passed: true, details: ['required' => count($required)]);
		}

		return new GuardResult(
			passed: false,
			// One item is named, not all of them: the message is read on a
			// button, and the handler only needs to know what to do next.
			failureMessage: $this->l10n->t('Checklist item not done: %s', [$open[0]]),
			details: ['open' => $open, 'statusType' => $statusTypeId],
		);
	}//end evaluate()

	/**
	 * The titles of this status's tasks that are at `completed`.
	 *
	 * @param string               $statusTypeId The status the case is in.
	 * @param array<string, mixed> $case         The case.
	 * @param string               $actor        The identity the engine is read as.
	 *
	 * @return array<int, string> The completed titles.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function completedTitles(string $statusTypeId, array $case, string $actor): array {
		$titles = [];
		foreach ($this->checklist->tasksFor(statusTypeId: $statusTypeId, case: $case, actor: $actor) as $task) {
			if (strtolower(trim((string)($task['status'] ?? ''))) !== self::COMPLETED) {
				continue;
			}

			$titles[] = trim((string)($task['title'] ?? ''));
		}

		return $titles;
	}//end completedTitles()
}//end class
