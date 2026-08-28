<?php

/**
 * A completed task wakes the run that asked for it.
 *
 * Tasks are ordinary OpenRegister objects edited through the generic object
 * API, so "completing a task" is an object update — there is no dossiq task
 * endpoint to hang this on. This listener is therefore where the human step
 * closes: it sees the update, recognises a task that a flow is waiting on, and
 * signals the run.
 *
 * 🔴 IT PERFORMS THE AUTHORIZATION CHECK ITSELF, AND MUST.
 *
 * OpenRegister's HTTP resume endpoint refuses a signal from anyone but the
 * step's assignee. This path does not go through that endpoint — it calls
 * `FlowRunService::signal()` directly — so the guard is NOT inherited. Without
 * the check here, any user who can write a task object could advance somebody
 * else's decision, and nothing about the resulting run would look wrong.
 *
 * The rule is not re-implemented: {@see FlowRunAssignee} is the same object the
 * HTTP endpoint consults. Two copies of one access rule drift, and the copy
 * that drifts is the one nobody looks at.
 *
 * WHY IT NEVER BLOCKS THE UPDATE. The task is already saved by the time this
 * runs. Refusing here cannot un-complete it, so a refusal means "the task is
 * completed but the run is not resumed" — recorded loudly, because the
 * alternative (resuming anyway) is the security hole this exists to close.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-flow-human-steps/specs/task-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Flow\FlowRunAssignee;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resumes a suspended flow run when the task it is waiting on is completed.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/case-flow-human-steps/specs/task-management/spec.md
 */
class TaskCompletionResumeListener implements IEventListener {
	/**
	 * The task status that means the person has answered.
	 *
	 * @var string
	 */
	private const STATUS_COMPLETED = 'completed';

	/**
	 * Constructor.
	 *
	 * @param FlowRunMapper    $runs        Resolves the run a task names.
	 * @param FlowRunService   $runner      Delivers the signal that resumes it.
	 * @param IUserSession     $userSession Identifies who completed the task.
	 * @param IGroupManager    $groups      Resolves group assignment.
	 * @param LoggerInterface  $logger      The logger.
	 * @param FlowRunAssignee|null $assignees The rule deciding who may answer. INJECTED
	 *                                        rather than constructed here so this class
	 *                                        can be tested against the real rule's
	 *                                        contract instead of a copy of it — dossiq's
	 *                                        suite resolves OpenRegister to stubs, so a
	 *                                        locally-built guard would have the test
	 *                                        validating a fake. Defaults to the real one.
	 */
	public function __construct(
		private readonly FlowRunMapper $runs,
		private readonly FlowRunService $runner,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groups,
		private readonly LoggerInterface $logger,
		private readonly ?FlowRunAssignee $assignees = null,
	) {
	}//end __construct()

	/**
	 * Resume the run this task was blocking, if it was blocking one.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-flow-human-steps/specs/task-management/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectUpdatedEvent) === false) {
			return;
		}

		try {
			$new = $event->getNewObject()->getObject();
			$old = $event->getOldObject()?->getObject();
		} catch (Throwable $e) {
			return;
		}

		if (is_array($new) === false) {
			return;
		}

		$runUuid = trim((string)($new['flowRun'] ?? ''));
		$nodeId = trim((string)($new['flowNode'] ?? ''));

		// Not a flow task. Completing it is a perfectly ordinary thing to do
		// and resumes nothing — the overwhelmingly common case, and not an
		// error.
		if ($runUuid === '' || $nodeId === '') {
			return;
		}

		if ($this->justCompleted(new: $new, old: $old) === false) {
			return;
		}

		try {
			$run = $this->runs->findByUuid($runUuid);
		} catch (Throwable $e) {
			// A task naming a run that no longer exists is completable; it
			// simply has nothing left to wake. Recorded rather than raised —
			// the person completing it did nothing wrong.
			$this->logger->info(
				'Dossiq: a completed task named flow run ' . $runUuid . ', which no longer exists',
				['task' => ($new['id'] ?? null)]
			);
			return;
		}

		$uid = $this->userSession->getUser()?->getUID();

		$assignees = ($this->assignees ?? new FlowRunAssignee(groupManager: $this->groups));
		if ($assignees->mayAnswer(run: $run, uid: $uid) === false) {
			// 🔴 The refusal that matters. The task is already saved; what is
			// withheld is the RESUME.
			$this->logger->warning(
				'Dossiq: refusing to resume flow run ' . $runUuid
					. ' — the user who completed the task is not the assignee of the awaiting step',
				['task' => ($new['id'] ?? null), 'user' => $uid, 'node' => $nodeId]
			);
			return;
		}

		try {
			$this->runner->signal(
				run: $run,
				payload: [
					// A resume with no `decision` is a nudge, not an answer, and
					// the awaiting node suspends again. Saying `completed`
					// explicitly is what makes this an answer.
					'decision' => 'completed',
					'node' => $nodeId,
					'taskId' => (string)($new['id'] ?? ''),
					'completedBy' => (string)$uid,
				]
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: could not resume flow run ' . $runUuid . ' after its task was completed',
				['error' => $e->getMessage(), 'task' => ($new['id'] ?? null)]
			);
		}
	}//end handle()

	/**
	 * Whether this update is the moment the task became completed.
	 *
	 * The transition matters, not the state. Any later edit of an
	 * already-completed task — a typo fixed in its description — is still an
	 * update whose status reads `completed`, and resuming on that would advance
	 * the run a second time.
	 *
	 * A missing previous state is treated as NOT a transition. Resuming on it
	 * would mean every unrelated write to a completed task re-signals the run,
	 * which is the more damaging of the two possible mistakes.
	 *
	 * @param array      $new The task after the update.
	 * @param array|null $old The task before it, when known.
	 *
	 * @return boolean True when the task has just become completed.
	 *
	 * @spec openspec/changes/case-flow-human-steps/specs/task-management/spec.md
	 */
	private function justCompleted(array $new, ?array $old): bool {
		if (strtolower(trim((string)($new['status'] ?? ''))) !== self::STATUS_COMPLETED) {
			return false;
		}

		if (is_array($old) === false) {
			return false;
		}

		return strtolower(trim((string)($old['status'] ?? ''))) !== self::STATUS_COMPLETED;
	}//end justCompleted()
}//end class
