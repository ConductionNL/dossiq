<?php

/**
 * Curated task tools an assistant may call on a case.
 *
 * Each method is an `#[McpTool]` that OpenRegister's attribute scanner
 * registers as `dossiq.<name>`. It runs as the calling user: the case is read
 * with the caller's own rights through the store a status change reads it with,
 * the caller must be allowed to change it, and the task is written by the same
 * handler a status change uses.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-204-guard-enforcing-write-tools-one-per-action-on-the-owning-service
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Mcp;

use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCA\Dossiq\Service\Transitions\CreateTaskHandler;
use OCA\OpenRegister\Mcp\Attribute\McpTool;
use OCA\OpenRegister\Mcp\Attribute\McpToolTitle;
use OCP\IUserSession;

/**
 * Tools that add work to a case.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-204-guard-enforcing-write-tools-one-per-action-on-the-owning-service
 */
class TaskTools {

	/**
	 * The longest lead time a task may get, in working days: ten years.
	 *
	 * @var int
	 */
	private const MAX_LEAD_TIME_DAYS = 3650;

	use ToolEnvelopes;

	/**
	 * Constructor.
	 *
	 * @param IUserSession      $userSession     The caller's session.
	 * @param CaseAccessGuard   $caseAccess      Answers whether the caller may change the case.
	 * @param CreateTaskHandler $handler         Writes the task, as a status change does.
	 * @param CaseStatusStore   $cases           Reads the case with the caller's own rights, as a status change does.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly CaseAccessGuard $caseAccess,
		private readonly CreateTaskHandler $handler,
		private readonly CaseStatusStore $cases,
	) {
	}//end __construct()

	/**
	 * Create a task on a case the caller may change.
	 *
	 * The tool takes only what a user sets on a new task. It is for the case
	 * handler, or for the caller; never for someone the caller names.
	 *
	 * @param string $caseId       The case UUID.
	 * @param string $title        What the task asks for.
	 * @param int    $leadTimeDays Working days until it is due; 0 for no due date.
	 * @param string $for          `handler` (the case handler) or `me` (the caller).
	 *
	 * @return array<string, mixed> `{taskId}`, or an error envelope.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-204-guard-enforcing-write-tools-one-per-action-on-the-owning-service
	 */
	#[McpTool(
		name: 'createTask',
		description: 'Create a task on a case the current user may change: a title, optionally the working days until it is due,'
			. ' and who it is for: "handler" (the case handler, the default) or "me" (the current user).',
		readOnlyHint: false,
		destructiveHint: false,
		scope: 'create',
		reach: 'instance',
		subject: 'task',
		action: 'create'
	)]
	#[McpToolTitle('Create a task')]
	public function createTask(string $caseId, string $title, int $leadTimeDays = 0, string $for = 'handler'): array {
		$run = new CreateTaskRun();
		$run->user = $this->userSession->getUser();
		$run->caseId = trim($caseId);
		$run->title = trim($title);
		$run->leadTimeDays = $leadTimeDays;
		$run->for = $for;

		$refusal = ($this->inputRefusal(run: $run) ?? $this->caseRefusal(run: $run));
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->created(run: $run);
	}//end createTask()

	/**
	 * Why the call cannot go on before the case is read: no session, or input out of bounds.
	 *
	 * @param CreateTaskRun $run The call.
	 *
	 * @return array<string, mixed>|null The error envelope, or null.
	 */
	private function inputRefusal(CreateTaskRun $run): ?array {
		if ($run->user === null) {
			return $this->error(code: 'not_authenticated', message: 'No user session.');
		}

		return ($this->invalidInput(title: $run->title, for: $run->for) ?? $this->invalidLeadTime(days: $run->leadTimeDays));
	}//end inputRefusal()

	/**
	 * Read the case into the call; why it cannot go on when the case is not found or may not be changed.
	 *
	 * @param CreateTaskRun $run The call, with its caller.
	 *
	 * @return array<string, mixed>|null The error envelope, or null.
	 */
	private function caseRefusal(CreateTaskRun $run): ?array {
		$run->openCase = $this->cases->loadCase(caseId: $run->caseId);
		if ($run->openCase === null) {
			return $this->error(code: 'not_found', message: 'The case was not found.');
		}

		if ($this->caseAccess->hasCaseMutationAccess(caseId: $run->caseId, user: $run->user) === false) {
			return $this->error(code: 'forbidden', message: 'You may not add work to this case.');
		}

		return null;
	}//end caseRefusal()

	/**
	 * Write the task through the handler, as a status change does.
	 *
	 * @param CreateTaskRun $run The checked call.
	 *
	 * @return array<string, mixed> `{taskId}`, or an error envelope.
	 */
	private function created(CreateTaskRun $run): array {
		$result = $this->handler->handle(
			actionConfig: self::taskAction(run: $run),
			case: $run->openCase,
			transitionContext: ['userId' => $run->user->getUID(), 'transitionLabel' => '']
		);
		if ($result->succeeded === false) {
			return $this->error(code: (string)$result->error, message: 'The task could not be created.');
		}

		return $result->data;
	}//end created()

	/**
	 * The createTask action: its title, the caller as assignee when it is for them, and a lead time when one was given.
	 *
	 * @param CreateTaskRun $run The checked call.
	 *
	 * @return array<string, mixed> The action configuration.
	 */
	private static function taskAction(CreateTaskRun $run): array {
		$action = ['type' => 'createTask', 'title' => $run->title];
		if ($run->for === 'me') {
			$action['assignee'] = $run->user->getUID();
		}

		if ($run->leadTimeDays > 0) {
			$action['declaration'] = ['leadTimeDays' => $run->leadTimeDays];
		}

		return $action;
	}//end taskAction()

	/**
	 * The error for input a user may not give, or null when it is fine.
	 *
	 * @param string $title The trimmed title.
	 * @param string $for   Who the task is for.
	 *
	 * @return array{error: string, message: string}|null
	 */
	private function invalidInput(string $title, string $for): ?array {
		if ($title === '') {
			return $this->error(code: 'title_required', message: 'Say what the task asks for.');
		}

		if (in_array($for, ['handler', 'me'], true) === false) {
			return $this->error(code: 'for_unknown', message: 'A task is for the case handler or for you.');
		}

		return null;
	}//end invalidInput()

	/**
	 * The error for a lead time outside 0 to {@see self::MAX_LEAD_TIME_DAYS} working days, or null.
	 *
	 * The due date is counted day by day, so an unbounded lead time would
	 * hold a worker.
	 *
	 * @param int $days The lead time in working days.
	 *
	 * @return array{error: string, message: string}|null
	 */
	private function invalidLeadTime(int $days): ?array {
		if ($days < 0 || $days > self::MAX_LEAD_TIME_DAYS) {
			return $this->error(code: 'lead_time_out_of_range', message: 'A task is due within ten years: 0 to 3650 working days.');
		}

		return null;
	}//end invalidLeadTime()
}//end class
