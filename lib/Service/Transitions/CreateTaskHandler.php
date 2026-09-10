<?php

/**
 * Dossiq createTask action handler.
 *
 * Action config shape:
 * `{type: 'createTask', title?, assignee?, assigneeFallback?, dueIn?: '<duration>',
 * workflowStepId?}`. Creates a task linked to the case via OpenRegister
 * ObjectService.
 *
 * 🔴 THE ASSIGNEE IS RESOLVED, NOT COPIED. This handler used to write
 * `$actionConfig['assignee'] ?? ''` onto the task. That is wrong in two ways
 * and both are silent. A declaration writing `{{ case.assignee }}`, the
 * shipped spelling everywhere else, landed on the task as that literal, and no
 * real uid ever equals it. An absent assignee landed as '', and the task
 * schema's `taskAssigned` notification is addressed to the `assignee` field:
 * an empty one means nobody is told the task exists. The status checklist,
 * which names no assignee at all, created every one of its tasks that way.
 * Resolution now goes through `AssigneeResolver`, the same one the flow node
 * uses.
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

use OCA\Dossiq\Service\AssigneeResolver;
use OCA\Dossiq\Service\Task\EngineTaskGateway;
use Psr\Log\LoggerInterface;

/**
 * Built-in handler for `createTask` automatic actions.
 *
 * @spec openspec/changes/status-transition-engine/tasks.md#T08
 */
class CreateTaskHandler implements ActionHandlerInterface {
	/**
	 * Constructor.
	 *
	 * @param AssigneeResolver  $assignees       The app's one answer to who work goes to
	 * @param EngineTaskGateway $engineTasks     The dual-run seam onto OpenRegister's task engine
	 * @param LoggerInterface   $logger          Logger
	 */
	public function __construct(
		private readonly AssigneeResolver $assignees,
		private readonly EngineTaskGateway $engineTasks,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle the createTask action.
	 *
	 * @param array<string, mixed> $actionConfig Action configuration
	 * @param array<string, mixed> $case Case object
	 * @param array<string, mixed> $transitionContext Transition context
	 *
	 * @return ActionResult
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function handle(array $actionConfig, array $case, array $transitionContext): ActionResult {
		try {
			// The engine, not a register schema. Its own reachability check
			// names the reason, including the namespace-rename case a
			// duck-typed lookup would otherwise hide.
			$unavailable = $this->engineTasks->unavailableReason();
			if ($unavailable !== '') {
				$this->logger->error('CreateTaskHandler: the task engine is unavailable', ['reason' => $unavailable]);

				return new ActionResult(succeeded: false, error: 'storage_unavailable');
			}

			$caseId = $this->assignees->caseId(case: $case);
			$title = (string)($actionConfig['title'] ?? sprintf('Taak na transitie %s', $transitionContext['transitionLabel'] ?? ''));
			$task = [
				'title' => $title,
				'case' => $caseId,
				// The task schema's lifecycle starts at 'available' (enum:
				// available|active|completed|terminated|disabled). Writing 'open'
				// produced an object no transition could advance.
				'status' => 'available',
				'assignee' => $this->resolveAssignee(actionConfig: $actionConfig, case: $case, title: $title),
			];

			// The team is not a second guess at the person: a case can carry a
			// team, a personal assignee, or both, and the task schema has a
			// field for each. Carrying the case's team over is what keeps a
			// checklist task on somebody's queue when no person resolves.
			//
			// Read through referenceId, never a (string) cast: `assignedGroup`
			// is a $ref, so an expanded read casts to the literal "Array" and
			// writes a team that resolves to nothing.
			$team = $this->assignees->referenceId(value: ($case['assignedGroup'] ?? ''));
			if ($team !== '') {
				$task['assigneeGroup'] = $team;
			}

			// Which status asked for this task. Written only when the action
			// names one, because an empty string is a value the task lists and
			// the checklist reader would both have to special-case: a task
			// tagged with "no status" is not the same as an untagged task.
			$workflowStepId = trim((string)($actionConfig['workflowStepId'] ?? ''));
			if ($workflowStepId !== '') {
				$task['workflowStepId'] = $workflowStepId;
			}

			// THE ENGINE IS THE RECORD NOW, and the register write is gone.
			// The dual-run has served its purpose: every read surface moved
			// (#2357), the flow's own write and resume moved (#2337, #2362),
			// and 33 existing tasks were backfilled and reconciled. Keeping
			// the register write would leave a second store that nothing
			// reads, drifting quietly until somebody trusted it.
			//
			// `mirrorImport` rather than `mirrorCreate`: on the flow path the
			// engine's RegistryStepDispatcher runs this handler inside
			// `ObjectService::runAs()` as the run's acting identity
			// (openregister#3332), which is a trusted in-process caller, not
			// an HTTP one.
			//
			// A FAILURE NOW FAILS THE TRANSITION, deliberately. Under the
			// dual-run a failed mirror was swallowed because the register
			// task already existed; with no register task there is nothing
			// left, and a transition that silently created no task is the
			// worse outcome. A checklist step whose task never appeared
			// looks like a case that needs no work.
			$taskId = $this->engineTasks->mirrorImport(
				task: $task,
				caseId: $caseId,
				actor: null
			);

			if ($taskId === '') {
				$this->logger->error(
					'CreateTaskHandler: the engine refused the task',
					['reason' => $this->engineTasks->lastError(), 'case' => $caseId]
				);

				return new ActionResult(succeeded: false, error: 'create_task_failed');
			}

			return new ActionResult(succeeded: true, data: ['taskId' => $taskId]);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CreateTaskHandler failed',
				['exception' => $e->getMessage(), 'context' => $transitionContext],
			);
			return new ActionResult(succeeded: false, error: 'create_task_failed');
		}//end try
	}//end handle()

	/**
	 * Who this task goes to.
	 *
	 * Three declared sources, in order: the action's own assignee, the
	 * fallback it declares beside it, and the case's own handler. The third is
	 * not a guess. A task created by a status transition belongs to the case,
	 * and the case says who is handling it; before this, an action that named
	 * nobody produced a task nobody was told about, on a case with a named
	 * handler sitting one field away.
	 *
	 * 🔴 IT DOES NOT REFUSE, AND THE FLOW NODE DOES. The difference is real.
	 * An unassigned FLOW task can be resumed by anybody, so leaving one
	 * unassigned opens the case's progress; refusing there is the safe answer.
	 * A task created by a transition resumes nothing, and refusing here aborts
	 * a status change that has a team to fall back on. So this path creates the
	 * task and SAYS SO, which is a defined outcome rather than a silent empty
	 * string.
	 *
	 * @param array<string, mixed> $actionConfig The action configuration.
	 * @param array<string, mixed> $case         The case.
	 * @param string               $title        The task title, for the log line.
	 *
	 * @return string The principal, or '' when nothing names one.
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 */
	private function resolveAssignee(array $actionConfig, array $case, string $title): string {
		$assignee = $this->assignees->resolve(
			primary: (string)($actionConfig['assignee'] ?? ''),
			fallback: (string)($actionConfig['assigneeFallback'] ?? ''),
			case: $case
		);
		if ($assignee !== '') {
			return $assignee;
		}

		$own = $this->assignees->referenceId(value: ($case['assignee'] ?? ''));
		if ($own !== '') {
			return $own;
		}

		if ($this->assignees->referenceId(value: ($case['assignedGroup'] ?? '')) === '') {
			$this->logger->warning(
				'CreateTaskHandler: the task "{title}" names nobody and its case has no handler and no team, '
					. 'so nobody is notified that it exists',
				['title' => $title, 'case' => $this->assignees->caseId(case: $case)]
			);
		}

		return '';
	}//end resolveAssignee()
}//end class
