<?php

/**
 * Dossiq createTask action handler.
 *
 * Action config shape:
 * `{type: 'createTask', title?, assignee?, assigneeFallback?, dueIn?: '<duration>',
 * workflowStepId?}`. Creates a task linked to the case via OpenRegister
 * ObjectService.
 *
 * WHO THE TASK GOES TO, in order:
 *
 * 1. `assignee`, the action's own, as a literal or a template such as
 *    `{{ case.assignee }}`;
 * 2. `assigneeFallback`, the declared second choice;
 * 3. the case's `assignee`, its handler, because a task created on a case is
 *    that case handler's work unless somebody said otherwise;
 * 4. nobody, and the task carries the case's team so it still reaches a queue.
 *
 * `assignee: "none"` is a RESERVED WORD, not a uid: it stops at step 1 and
 * leaves the task unclaimed, which is how an action authors a queue task that
 * the default must not hand to one person. The case's team still comes along.
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

use DateTimeImmutable;
use OCA\Dossiq\Service\AssigneeResolver;
use OCA\Dossiq\Service\Task\EngineTaskGateway;
use OCA\Dossiq\Service\WorkingDayCalculator;
use OCP\IUserSession;
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
	 * @param IUserSession|null $userSession Names the actor when the context does not.
	 * @param WorkingDayCalculator|null $workingDays Counts a declared lead time in
	 *                                               working days, on the administered
	 *                                               calendar the case terms use.
	 */
	public function __construct(
		private readonly AssigneeResolver $assignees,
		private readonly EngineTaskGateway $engineTasks,
		private readonly LoggerInterface $logger,
		private readonly ?IUserSession $userSession = null,
		private readonly ?WorkingDayCalculator $workingDays = null,
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
			// Read through the resolver, never a (string) cast: `assignedGroup`
			// is a $ref, so an expanded read casts to the literal "Array" and
			// writes a team that resolves to nothing.
			$team = $this->assignees->resolveTeam(case: $case);
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

			// What the case type declared about THIS task: its own due date,
			// the team it is offered to, the form it asks for, and what
			// completing it does. Everything here is a declaration dossiq
			// writes and the engine performs — there is no dossiq task store,
			// no dossiq task number and no dossiq lock, which is what
			// `remove-casetask` settled.
			$task = $this->declared(task: $task, actionConfig: $actionConfig);

			// THE ENGINE IS THE RECORD NOW, and the register write is gone.
			// The dual-run has served its purpose: every read surface moved
			// (#2357), the flow's own write and resume moved (#2337, #2362),
			// and 33 existing tasks were backfilled and reconciled. Keeping
			// the register write would leave a second store that nothing
			// reads, drifting quietly until somebody trusted it.
			//
			// `mirrorImport` rather than `mirrorCreate`: the engine's create
			// path is the HTTP one and pins the requester to whoever triggered
			// the transition, where `import()` is its trusted in-process entry.
			// It still authorizes, which is what the actor below is for.
			//
			// A FAILURE NOW FAILS THE TRANSITION, deliberately. Under the
			// dual-run a failed mirror was swallowed because the register
			// task already existed; with no register task there is nothing
			// left, and a transition that silently created no task is the
			// worse outcome. A checklist step whose task never appeared
			// looks like a case that needs no work.
			// 🔴 THIS WAS `actor: null`, AND THE ENGINE DENIES EVERY VERB WITH
			// NO ACTING IDENTITY, so no checklist task was ever created. See
			// {@see actor()} for what resolves one and why in that order.
			$taskId = $this->engineTasks->mirrorImport(
				task: $task,
				caseId: $caseId,
				actor: $this->actor(transitionContext: $transitionContext)
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
	 * Apply the case type's per-task declaration to the task being created.
	 *
	 * Four of the five settings land on the task; the fifth, `enabled`, has
	 * already been honoured by whoever built the action, because a task that
	 * does not run is not created rather than created and hidden.
	 *
	 * 🔑 THE DUE DATE IS THE TASK'S OWN. A lead time is counted in working
	 * days from today, through the same administered calendar the case terms
	 * use, and it OVERRIDES nothing: an action that named its own `dueIn` or a
	 * task whose declaration names no lead time keeps whatever it had. A task
	 * showing the case deadline is the failure this replaces — every task on a
	 * case appearing to be due on the same day tells a handler nothing about
	 * which one is late.
	 *
	 * 🔑 THE FORM AND THE EFFECTS GO INTO `metadata`, WHICH IS THE ENGINE'S
	 * OWN ROOM FOR THEM. `metadata.form` is the shape
	 * `OCA\OpenRegister\Service\Task\TaskFormResolver` reads for a run-less
	 * task, so declaring it here is what makes the form render and validate
	 * without dossiq owning a second form vocabulary. `metadata.dossiq.effects`
	 * is dossiq's own, read back by {@see \OCA\Dossiq\Listener\TaskEffectsListener}
	 * when the task completes.
	 *
	 * @param array<string, mixed> $task         The task being built.
	 * @param array<string, mixed> $actionConfig The action, carrying the declaration.
	 *
	 * @return array<string, mixed> The task, with the declaration applied.
	 *
	 * @spec openspec/changes/task-as-a-first-class-record/specs/process-step-configuration/spec.md
	 */
	private function declared(array $task, array $actionConfig): array {
		$declaration = ($actionConfig['declaration'] ?? null);
		if (is_array($declaration) === false) {
			return $task;
		}

		$due = $this->declaredDueDate(declaration: $declaration, task: $task);
		if ($due !== '') {
			$task['dueDate'] = $due;
		}

		foreach (['candidateGroups', 'candidateUsers'] as $key) {
			$candidates = ($declaration[$key] ?? []);
			if (is_array($candidates) === true && $candidates !== []) {
				$task[$key] = array_values($candidates);
			}
		}

		$metadata = self::declaredMetadata(declaration: $declaration);
		if ($metadata !== []) {
			$task['metadata'] = $metadata;
		}

		return $task;
	}//end declared()

	/**
	 * The form and the effects, in the shapes their readers expect.
	 *
	 * `form` is OpenRegister's own declaration shape, read by its task form
	 * resolver off `metadata.form`; `dossiq.effects` is dossiq's, read back by
	 * {@see \OCA\Dossiq\Listener\TaskCompletionEffectsListener} when the
	 * task completes. Neither is translated on the way in: a second
	 * description of the same declaration is one that can disagree.
	 *
	 * @param array<string, mixed> $declaration The per-task declaration.
	 *
	 * @return array<string, mixed> The metadata, empty when nothing is declared.
	 *
	 * @spec openspec/changes/task-as-a-first-class-record/specs/process-step-configuration/spec.md
	 */
	private static function declaredMetadata(array $declaration): array {
		$metadata = [];
		if (is_array(($declaration['form'] ?? null)) === true) {
			$metadata['form'] = $declaration['form'];
		}

		$effects = ($declaration['effects'] ?? []);
		if (is_array($effects) === true && $effects !== []) {
			$metadata['dossiq'] = ['effects' => array_values($effects)];
		}

		return $metadata;
	}//end declaredMetadata()

	/**
	 * The due date a declared lead time gives this task, or ''.
	 *
	 * Counted in working days from today, through the same administered
	 * calendar the case terms use. It OVERRIDES nothing: an action that named
	 * its own date keeps it, and a declaration naming no lead time leaves the
	 * task with whatever it had.
	 *
	 * @param array<string, mixed> $declaration The per-task declaration.
	 * @param array<string, mixed> $task        The task being built.
	 *
	 * @return string The ISO date-time, or '' when nothing declares one.
	 *
	 * @spec openspec/changes/task-as-a-first-class-record/specs/process-step-configuration/spec.md
	 */
	private function declaredDueDate(array $declaration, array $task): string {
		$leadTime = (int)($declaration['leadTimeDays'] ?? 0);
		if ($leadTime < 1 || $this->workingDays === null) {
			return '';
		}

		if (trim((string)($task['dueDate'] ?? '')) !== '') {
			return '';
		}

		return $this->workingDays->addWorkingDays(
			start: new DateTimeImmutable('today'),
			days: $leadTime
		)->format('c');
	}//end declaredDueDate()

	/**
	 * The identity the engine write is authorized as.
	 *
	 * The transition's own `userId` first: `StatusTransitionService` sets it on
	 * every context it dispatches, and it names the person who moved the case,
	 * which is who the resulting work belongs to. A flow run reaching this
	 * handler through `DossiqFlowNodeBase` passes the run's context instead,
	 * which need not carry one, so the session answers for that path.
	 *
	 * `TaskService::import()` hands this straight to
	 * `TaskAuthorizationService::assertMay()`, whose first guard rejects a
	 * blank uid with "Verb 'create' denied: no acting identity". The old
	 * comment here read `runAs()` as supplying it; it does not, because
	 * `import()` authorizes on the argument, so an in-process caller still has
	 * to name itself. `AskPersonTaskStore` resolves one for the same reason.
	 *
	 * Returns null when neither resolves, and null is refused by the engine.
	 * That is the right end: a task created with no acting identity is one no
	 * audit entry can attribute, and the caller fails the transition on it.
	 *
	 * @param array<string, mixed> $transitionContext The dispatch context.
	 *
	 * @return string|null The acting identity, or null when none resolves.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function actor(array $transitionContext): ?string {
		$fromContext = trim((string)($transitionContext['userId'] ?? ''));
		if ($fromContext !== '') {
			return $fromContext;
		}

		$fromSession = trim((string)($this->userSession?->getUser()?->getUID() ?? ''));
		if ($fromSession !== '') {
			return $fromSession;
		}

		return null;
	}//end actor()

	/**
	 * Who this task goes to.
	 *
	 * Three declared sources, in order: the action's own assignee, the
	 * fallback it declares beside it, and the case's own handler. All three now
	 * live in `AssigneeResolver`, where the flow node reads them too; this
	 * method used to do the third step itself, which meant a human step in a
	 * flow did not get it.
	 *
	 * `assignee: "none"` is the way out, and it is not the same as naming
	 * nobody: it keeps the task unclaimed on purpose, and the case's team still
	 * comes along, which is what a queue task is.
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
	 * @spec openspec/changes/task-defaults-to-case-handler/specs/task-management/spec.md
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

		// Authored to stay unclaimed. Saying so is not the same as forgetting,
		// so the warning below would be a lie here.
		$authored = trim((string)($actionConfig['assignee'] ?? ''));
		if (strcasecmp($authored, AssigneeResolver::UNASSIGNED) === 0) {
			return '';
		}

		if ($this->assignees->resolveTeam(case: $case) === '') {
			$this->logger->warning(
				'CreateTaskHandler: the task "{title}" names nobody and its case has no handler and no team, '
					. 'so nobody is notified that it exists',
				['title' => $title, 'case' => $this->assignees->caseId(case: $case)]
			);
		}

		return '';
	}//end resolveAssignee()
}//end class
