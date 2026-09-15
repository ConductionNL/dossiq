<?php

/**
 * Dossiq Case Task Controller.
 *
 * The things a handler does to a task from the case page, without leaving it:
 *
 *  - GET  /api/case/{caseId}/acts                          the two halves of "what may I do right now"
 *  - GET  /api/case-tasks/capabilities                     what the task engine on this instance answers
 *  - POST /api/case-tasks/{taskId}/complete                complete, with the form's answers
 *  - POST /api/case-tasks/{taskId}/claim                   take an unclaimed task
 *  - POST /api/case/{caseId}/tasks/{taskId}/attachments    hold a file against the open task
 *  - DELETE /api/case/{caseId}/tasks/{taskId}/attachments/{fileId}  take it off again
 *
 * Every method is `#[NoAdminRequired]` and every method that names a case
 * guards that case first, per ADR-005 rule 3. The task routes guard the case
 * the task is ON, read from the engine, so a caller cannot reach a task by its
 * uuid on a case they may not see.
 *
 * 🔑 THE ENGINE STILL RULES ON THE VERB. dossiq refuses what dossiq declared —
 * a required field left empty, an effect naming a handler nobody registered —
 * and the engine refuses what the engine owns: who may complete, who may
 * claim, what the form allows. Neither repeats the other, because a second
 * authority eventually refuses what the first allowed.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
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

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\CaseType\AlwaysAvailableActs;
use OCA\Dossiq\Service\Task\CaseTaskCompletion;
use OCA\Dossiq\Service\Task\EngineTaskGateway;
use OCA\Dossiq\Service\Task\TaskAttachmentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Complete, claim and attach to a task from the case it is on.
 *
 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
 */
class CaseTaskController extends Controller {

	/**
	 * The refusals that map to something other than 400.
	 *
	 * @var array<string, int>
	 */
	private const REFUSAL_STATUS = [
		'task_not_found' => Http::STATUS_NOT_FOUND,
		'case_not_found' => Http::STATUS_NOT_FOUND,
		'storage_unavailable' => Http::STATUS_SERVICE_UNAVAILABLE,
	];

	/**
	 * Constructor.
	 *
	 * @param string                $appName     The app name.
	 * @param IRequest              $request     The HTTP request.
	 * @param CaseTaskCompletion    $completion  Pre-checks and completes a task.
	 * @param EngineTaskGateway     $engineTasks The one seam onto the task engine.
	 * @param TaskAttachmentService $attachments Holds a file against an open task.
	 * @param AlwaysAvailableActs   $acts        The acts allowed in every phase.
	 * @param CaseAccessGuard       $caseAccess  Per-case authorization, fails closed.
	 * @param IUserSession          $userSession The current session.
	 * @param LoggerInterface       $logger      The logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CaseTaskCompletion $completion,
		private readonly EngineTaskGateway $engineTasks,
		private readonly TaskAttachmentService $attachments,
		private readonly AlwaysAvailableActs $acts,
		private readonly CaseAccessGuard $caseAccess,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * What the task engine on this instance can do.
	 *
	 * Asked rather than assumed, and ANSWERED TO THE SURFACE rather than only
	 * acted on. A claim button that does nothing because the engine has no
	 * claim act would be worse than none, and a case type screen that promised
	 * a candidate group the engine ignores would be worse still. So the
	 * surface renders the affordance when this says so, and states the gap
	 * when it does not.
	 *
	 * @return JSONResponse `{claim: bool}`.
	 *
	 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
	 */
	#[NoAdminRequired]
	public function capabilities(): JSONResponse {
		return new JSONResponse(['claim' => $this->engineTasks->supportsClaim()]);
	}//end capabilities()

	/**
	 * The acts available on this case, in two halves.
	 *
	 * The always-available half only: the phase's own acts are OpenRegister's
	 * `available-actions` answer, published by
	 * {@see \OCA\Dossiq\Lifecycle\CaseActionProvider}, and duplicating them
	 * here would be a second list that can disagree with the first. The
	 * surface asks for both and renders them together, marked.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse `{alwaysAvailable: [...]}`.
	 *
	 * @spec openspec/changes/task-as-a-first-class-record/specs/process-step-configuration/spec.md
	 */
	#[NoAdminRequired]
	public function acts(string $caseId): JSONResponse {
		return $this->guarded(
			caseId: $caseId,
			write: false,
			run: fn (): array => [
				'alwaysAvailable' => $this->acts->forCase(caseId: $caseId, userId: $this->currentUid()),
			],
		);
	}//end acts()

	/**
	 * Complete one task with the answers its form asked for.
	 *
	 * @param string $taskId The task UUID.
	 *
	 * @return JSONResponse What happened, or a refusal naming what was missing.
	 *
	 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
	 */
	#[NoAdminRequired]
	public function complete(string $taskId): JSONResponse {
		$data = $this->request->getParam('data', []);
		$outcome = (string)$this->request->getParam('outcome', 'done');

		return $this->onTask(
			taskId: $taskId,
			run: fn (string $caseId): array => $this->completion->complete(
				taskId: $taskId,
				data: (is_array($data) === true ? $data : []),
				outcome: ($outcome === '' ? 'done' : $outcome),
				actor: $this->currentUid()
			),
		);
	}//end complete()

	/**
	 * Take an unclaimed task that was offered to a team.
	 *
	 * @param string $taskId The task UUID.
	 *
	 * @return JSONResponse The outcome, or a refusal.
	 *
	 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
	 */
	#[NoAdminRequired]
	public function claim(string $taskId): JSONResponse {
		return $this->onTask(
			taskId: $taskId,
			run: function (string $caseId) use ($taskId): array {
				if ($this->engineTasks->claim(taskId: $taskId, actor: $this->currentUid()) === false) {
					throw new RuntimeException($this->engineTasks->lastError());
				}

				return ['claimed' => true, 'task' => $taskId, 'case' => $caseId];
			},
		);
	}//end claim()

	/**
	 * Hold one file against an open task.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $taskId The task UUID.
	 *
	 * @return JSONResponse The files now held against that task.
	 *
	 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
	 */
	#[NoAdminRequired]
	public function attach(string $caseId, string $taskId): JSONResponse {
		return $this->guarded(
			caseId: $caseId,
			write: true,
			run: fn (): array => [
				'held' => $this->attachments->bind(
					caseId: $caseId,
					taskId: $taskId,
					file: [
						'file' => (string)$this->request->getParam('file', ''),
						'title' => (string)$this->request->getParam('title', ''),
						'link' => (string)$this->request->getParam('link', ''),
					],
					uploader: $this->currentUid()
				),
			],
		);
	}//end attach()

	/**
	 * Take a file off an open task.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $taskId The task UUID.
	 * @param string $fileId The file.
	 *
	 * @return JSONResponse The files still held against that task.
	 *
	 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
	 */
	#[NoAdminRequired]
	public function detach(string $caseId, string $taskId, string $fileId): JSONResponse {
		return $this->guarded(
			caseId: $caseId,
			write: true,
			run: fn (): array => [
				'held' => $this->attachments->release(caseId: $caseId, taskId: $taskId, fileId: $fileId),
			],
		);
	}//end detach()

	/**
	 * Run a gesture on a task, behind the guard of the case it is on.
	 *
	 * The case is read FROM THE TASK rather than taken from the caller. A
	 * caseId in the URL beside a taskId would be two claims about the same
	 * relationship, and a caller could pass a case they may see to reach a
	 * task on one they may not.
	 *
	 * @param string                           $taskId The task.
	 * @param callable(string): array<string, mixed> $run The gesture, given the case id.
	 *
	 * @return JSONResponse The answer, or a refusal.
	 */
	private function onTask(string $taskId, callable $run): JSONResponse {
		$task = $this->engineTasks->find(taskId: $taskId);
		if ($task === null) {
			return new JSONResponse(
				['message' => 'That task could not be found', 'error' => 'task_not_found'],
				Http::STATUS_NOT_FOUND
			);
		}

		$caseId = (string)($task['objectUuid'] ?? '');
		if ($caseId === '') {
			return new JSONResponse(
				['message' => 'That task is not on a case', 'error' => 'case_not_found'],
				Http::STATUS_NOT_FOUND
			);
		}

		return $this->guarded(caseId: $caseId, write: true, run: static fn (): array => $run($caseId));
	}//end onTask()

	/**
	 * The signed-in user's uid.
	 *
	 * @return string The uid.
	 */
	private function currentUid(): string {
		return (string)($this->userSession->getUser()?->getUID() ?? '');
	}//end currentUid()

	/**
	 * Run one gesture behind the session and per-case guards.
	 *
	 * A refusal carries `{message, error}` per ADR-050, and a refusal that
	 * NAMES something — the empty field, the unresolvable handler — carries
	 * that name in both: the message for the person, the code for the client.
	 *
	 * @param string                                 $caseId The case UUID.
	 * @param boolean                                $write  Whether the gesture writes.
	 * @param callable(): array<string, mixed>       $run    The gesture.
	 *
	 * @return JSONResponse The gesture's answer, or a refusal.
	 */
	private function guarded(string $caseId, bool $write, callable $run): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['message' => 'You are not signed in', 'error' => 'not_authenticated'],
				Http::STATUS_UNAUTHORIZED
			);
		}

		if ($this->allowed(caseId: $caseId, user: $user, write: $write) === false) {
			return new JSONResponse(
				['message' => 'You may not act on this case', 'error' => 'not_authorized'],
				Http::STATUS_FORBIDDEN
			);
		}

		try {
			return new JSONResponse($run());
		} catch (RuntimeException $e) {
			return $this->refusal(code: $e->getMessage(), caseId: $caseId);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CaseTaskController: the gesture failed',
				['exception' => $e->getMessage(), 'caseId' => $caseId],
			);

			return new JSONResponse(
				['message' => 'That could not be done', 'error' => 'task_action_failed'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end guarded()

	/**
	 * Turn a named refusal into a response that says what was missing.
	 *
	 * @param string $code   The refusal, possibly `<code>:<name>`.
	 * @param string $caseId The case, for the log.
	 *
	 * @return JSONResponse The refusal.
	 */
	private function refusal(string $code, string $caseId): JSONResponse {
		$this->logger->info('CaseTaskController: gesture refused', ['code' => $code, 'caseId' => $caseId]);

		[$reason, $named] = array_pad(explode(':', $code, 2), 2, '');

		if ($reason === 'required_field') {
			return new JSONResponse(
				[
					'message' => sprintf('Fill in %s before completing this task', $named),
					'error' => 'required_field',
					'field' => $named,
				],
				Http::STATUS_BAD_REQUEST
			);
		}

		if ($reason === 'unresolvable_effect') {
			return new JSONResponse(
				[
					'message' => sprintf(
						'Completing this task should run %s, and nothing answers to that name. The task stays open.',
						$named
					),
					'error' => 'unresolvable_effect',
					'effect' => $named,
				],
				Http::STATUS_CONFLICT
			);
		}

		return new JSONResponse(
			['message' => ($code === '' ? 'That could not be done' : $code), 'error' => ($reason === '' ? 'refused' : $reason)],
			(self::REFUSAL_STATUS[$reason] ?? Http::STATUS_BAD_REQUEST)
		);
	}//end refusal()

	/**
	 * Whether this user may run this gesture on this case.
	 *
	 * @param string  $caseId The case UUID.
	 * @param IUser   $user   The caller.
	 * @param boolean $write  Whether the gesture writes.
	 *
	 * @return boolean True when the guard allows it.
	 */
	private function allowed(string $caseId, IUser $user, bool $write): bool {
		if ($write === true) {
			return $this->caseAccess->hasCaseMutationAccess(caseId: $caseId, user: $user);
		}

		return $this->caseAccess->hasCaseReadAccess(caseId: $caseId, user: $user);
	}//end allowed()
}//end class
