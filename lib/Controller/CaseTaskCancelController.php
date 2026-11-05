<?php

/**
 * Dossiq CaseTaskCancelController.
 *
 * `POST /api/case-tasks/{taskId}/cancel`: cancel one task through the task
 * engine, never by writing the object. The case the guard checks is read from
 * the task itself, as for complete and claim. A refusal answers 409 with the
 * engine's reason in a card notice, so the Tasks card shows it in place of a
 * toast.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
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
 * @spec openspec/changes/case-page-answers-in-card-notices/specs/case-management/spec.md#requirement-an-action-on-the-case-page-answers-with-a-card-notice
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Notice\CaseActNotices;
use OCA\Dossiq\Service\Task\CaseTaskActions;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use RuntimeException;
use Throwable;

/**
 * Cancels a task from the case it is on.
 *
 * @spec openspec/changes/case-page-answers-in-card-notices/specs/case-management/spec.md#requirement-an-action-on-the-case-page-answers-with-a-card-notice
 */
class CaseTaskCancelController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest        $request     The request.
	 * @param CaseTaskActions $tasks       Reads and cancels the task through the engine.
	 * @param CaseAccessGuard $caseAccess  Write access on the task's case, failing closed.
	 * @param CaseActNotices  $notices     The refusal notice.
	 * @param IUserSession    $userSession The caller.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly CaseTaskActions $tasks,
		private readonly CaseAccessGuard $caseAccess,
		private readonly CaseActNotices $notices,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Cancel one task.
	 *
	 * @param string $taskId The task UUID.
	 *
	 * @return JSONResponse `{ cancelled, task }`, or a refusal (409 with a notice when the engine refuses).
	 *
	 * @spec openspec/changes/case-page-answers-in-card-notices/specs/case-management/spec.md#requirement-an-action-on-the-case-page-answers-with-a-card-notice
	 */
	#[NoAdminRequired]
	public function cancel(string $taskId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'You are not signed in', 'error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$task = $this->tasks->find(taskId: $taskId);
		} catch (Throwable $e) {
			return new JSONResponse(['message' => 'The task engine could not be reached', 'error' => 'storage_unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$caseId = (string)($task['objectUuid'] ?? '');
		if ($task === null || $caseId === '') {
			return new JSONResponse(['message' => 'That task could not be found', 'error' => 'task_not_found'], Http::STATUS_NOT_FOUND);
		}

		if ($this->caseAccess->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['message' => 'You may not act on this case', 'error' => 'not_authorized'], Http::STATUS_FORBIDDEN);
		}

		try {
			return new JSONResponse(
				$this->tasks->cancel(
					taskId: (string)($task['id'] ?? $taskId),
					reason: (string)$this->request->getParam('reason', ''),
					actor: $user->getUID()
				)
			);
		} catch (RuntimeException $e) {
			[, $why] = array_pad(explode(':', $e->getMessage(), 2), 2, '');
			$notice  = $this->notices->cancelRefused(task: $this->tasks->titleOf(task: $task), reason: trim($why));

			return new JSONResponse(
				['message' => $notice['title'] . ' ' . $notice['text'], 'error' => 'cancel_refused', 'notice' => $notice],
				Http::STATUS_CONFLICT
			);
		}//end try
	}//end cancel()
}//end class
