<?php

/**
 * Curated tools an assistant may call on one case.
 *
 * Each method is an `#[McpTool]` that OpenRegister's attribute scanner
 * registers as `dossiq.<name>`. It runs the gate the matching controller runs
 * and then calls the owning service, so the agent path and the human path are
 * the same path. Nothing here reads or writes an object directly.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Mcp
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
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-202-curated-aggregation-reads
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Mcp;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\CaseAssignmentService;
use OCA\Dossiq\Service\StatusTransitionService;
use OCA\Dossiq\Service\Task\CaseTaskActions;
use OCA\Dossiq\Service\Transitions\GuardFailedException;
use OCA\OpenRegister\Mcp\Attribute\McpTool;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use RuntimeException;

/**
 * Tools on a single case.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-202-curated-aggregation-reads
 */
class CaseTools {

	use ToolEnvelopes;

	/**
	 * Constructor.
	 *
	 * @param IUserSession            $userSession  The caller's session.
	 * @param CaseAccessGuard         $caseAccess   Answers whether the caller may read or change the case.
	 * @param StatusTransitionService $transitions  Owns the status machine.
	 * @param CaseAssignmentService   $assignment   Owns who holds a case.
	 * @param CaseTaskActions         $tasks        The engine's task verbs, as the case page uses them.
	 * @param IGroupManager           $groupManager Answers the coordinator (administrator) check.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly CaseAccessGuard $caseAccess,
		private readonly StatusTransitionService $transitions,
		private readonly CaseAssignmentService $assignment,
		private readonly CaseTaskActions $tasks,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * The status changes the caller may make on a case now.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed> The current status and the allowed transitions, or an error envelope.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-202-curated-aggregation-reads
	 */
	#[McpTool(
		name: 'listAvailableTransitions',
		description: 'The status a case is in and the status changes the current user may make on it now, with any guard that blocks one.',
		readOnlyHint: true,
		scope: 'read',
		reach: 'user',
		subject: 'case',
		action: 'listTransitions'
	)]
	public function listAvailableTransitions(string $caseId): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->error(code: 'not_authenticated', message: 'No user session.');
		}

		// The same check StatusTransitionController::available() runs: without
		// it the current status of any case would be readable by anyone.
		if ($this->caseAccess->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return $this->error(code: 'forbidden', message: 'You may not read this case.');
		}

		return $this->transitions->getAvailableTransitions(caseId: $caseId, userId: $user->getUID());
	}//end listAvailableTransitions()

	/**
	 * Move a case to another status through the status machine.
	 *
	 * The same engine call StatusTransitionController::execute() makes, so
	 * guards, the status record, automatic actions and term recalculation all
	 * run. A free-form transition is never offered (design D3).
	 *
	 * @param string $caseId       The case UUID.
	 * @param string $transitionId The transition id, from listAvailableTransitions.
	 * @param string $comment      Optional comment for the status record.
	 * @param string $resultTypeId The result the case closes with, when the target status is final.
	 *
	 * @return array<string, mixed> The engine's answer, or an error envelope.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-204-guard-enforcing-write-tools-one-per-action-on-the-owning-service
	 */
	#[McpTool(
		name: 'transitionCase',
		description: 'Move a case on with one of its allowed transitions (see listAvailableTransitions). A closing status needs a result type.',
		readOnlyHint: false,
		destructiveHint: false,
		scope: 'update',
		reach: 'instance',
		subject: 'case',
		action: 'transition'
	)]
	public function transitionCase(string $caseId, string $transitionId, string $comment = '', string $resultTypeId = ''): array {
		$user = $this->mutator(caseId: $caseId);
		if (is_array($user) === true) {
			return $user;
		}

		if (trim($transitionId) === '') {
			return $this->error(code: 'transition_required', message: 'Name the transition to make.');
		}

		try {
			return $this->transitions->execute(
				caseId: $caseId,
				transitionId: $transitionId,
				comment: $this->orNull(value: $comment),
				userId: $user->getUID(),
				resultTypeId: $this->orNull(value: $resultTypeId),
			);
		} catch (GuardFailedException $e) {
			return [
				'error' => 'transition-guard-failed',
				'message' => 'This move is blocked by a rule on the case.',
				'failedGuards' => $e->getFailedGuards(),
			];
		} catch (RefusedException | RuntimeException $e) {
			return $this->refusal(e: $e);
		}
	}//end transitionCase()

	/**
	 * Hand a case to another handler.
	 *
	 * A coordinator act, as the caseload release is
	 * (SubstitutionController::releaseCaseload()).
	 *
	 * @param string $caseId The case UUID.
	 * @param string $toUser The Nextcloud user id of the receiving handler.
	 *
	 * @return array<string, mixed> The new assignment, or an error envelope.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-204-guard-enforcing-write-tools-one-per-action-on-the-owning-service
	 */
	#[McpTool(
		name: 'reassignCase',
		description: 'Hand one case to another handler, who is notified. Coordinators only.',
		readOnlyHint: false,
		destructiveHint: false,
		scope: 'update',
		reach: 'instance',
		subject: 'case',
		action: 'reassign'
	)]
	public function reassignCase(string $caseId, string $toUser): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->error(code: 'not_authenticated', message: 'No user session.');
		}

		if ($this->groupManager->isAdmin($user->getUID()) === false) {
			return $this->error(code: 'forbidden', message: 'This needs the coordinator role.');
		}

		try {
			return $this->assignment->reassign(caseId: $caseId, toUser: $toUser, actorId: $user->getUID());
		} catch (RuntimeException $e) {
			return $this->refusal(e: $e);
		}
	}//end reassignCase()

	/**
	 * Complete a task on a case through the workflow engine.
	 *
	 * The path CaseTaskController::complete() takes: the engine task is read,
	 * the caller must be allowed to change its case, and the engine's own
	 * complete verb advances the step. A task whose form has required fields
	 * is refused with the field named; this tool fills in no form.
	 *
	 * @param string $taskId  The engine task id.
	 * @param string $outcome The outcome to complete with.
	 *
	 * @return array<string, mixed> The completed task, or an error envelope.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-204-guard-enforcing-write-tools-one-per-action-on-the-owning-service
	 */
	#[McpTool(
		name: 'completeTask',
		description: 'Complete a task on a case with an outcome (default "done"). A task with required form fields is refused and the field is named.',
		readOnlyHint: false,
		destructiveHint: false,
		scope: 'update',
		reach: 'instance',
		subject: 'task',
		action: 'complete'
	)]
	public function completeTask(string $taskId, string $outcome = 'done'): array {
		try {
			$task = $this->tasks->find(taskId: $taskId);
		} catch (\Throwable) {
			return $this->error(code: 'storage_unavailable', message: 'The task engine could not be reached.');
		}

		$caseId = (string)($task['objectUuid'] ?? '');
		if ($task === null || $caseId === '') {
			return $this->error(code: 'task_not_found', message: 'That task could not be found on a case.');
		}

		$user = $this->mutator(caseId: $caseId);
		if (is_array($user) === true) {
			return $user;
		}

		if (trim($outcome) === '') {
			$outcome = 'done';
		}

		try {
			return $this->tasks->complete(
				taskId: (string)$task['id'],
				data: [],
				outcome: $outcome,
				actor: $user->getUID(),
			);
		} catch (RuntimeException $e) {
			return $this->refusal(e: $e);
		}
	}//end completeTask()

	/**
	 * An optional tool argument as the service expects it: null when empty.
	 *
	 * @param string $value The argument.
	 *
	 * @return string|null
	 */
	private function orNull(string $value): ?string {
		if (trim($value) === '') {
			return null;
		}

		return $value;
	}//end orNull()

	/**
	 * The signed-in user when they may change the case, otherwise the refusal.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return IUser|array{error: string, message: string}
	 */
	private function mutator(string $caseId): IUser|array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->error(code: 'not_authenticated', message: 'No user session.');
		}

		if ($this->caseAccess->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return $this->error(code: 'forbidden', message: 'You may not change this case.');
		}

		return $user;
	}//end mutator()
}//end class
