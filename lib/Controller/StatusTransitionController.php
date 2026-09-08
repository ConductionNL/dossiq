<?php

/**
 * Dossiq Status Transition Controller.
 *
 * REST surface for the status-transition engine. CRUD on `statusRecord`
 * objects is delegated to the manifest renderer (OpenRegister); this
 * controller exposes the engine endpoints:
 *
 *  - GET  /api/case/{caseId}/available-transitions
 *  - POST /api/case/{caseId}/transition           (body {transitionId, comment?})
 *  - POST /api/case/{caseId}/transition-freeform  (admin only; body {toStatusId, comment?})
 *  - GET  /api/case/{caseId}/transition-history
 *  - POST /api/cases/bulk-transition/preview      (body {caseIds[], transitionId})
 *  - POST /api/cases/bulk-transition/execute      (body {caseIds[], transitionId, comment?})
 *
 * Error responses use static messages — `$e->getMessage()` is NEVER returned.
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

use OCA\Dossiq\Service\BulkStatusTransitionService;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\StatusTransitionService;
use OCA\Dossiq\Service\Transitions\GuardFailedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Controller for status-transition engine endpoints.
 *
 * @spec openspec/changes/status-transition-engine/tasks.md#T11
 */
class StatusTransitionController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app name
	 * @param IRequest $request The HTTP request
	 * @param StatusTransitionService $transitionEngine The engine service
	 * @param BulkStatusTransitionService $bulkEngine The bulk wrapper service
	 * @param IUserSession $userSession The current session
	 * @param LoggerInterface $logger The logger
	 * @param CaseAccessGuard $caseAccessGuard Per-case authorization (fails closed)
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly StatusTransitionService $transitionEngine,
		private readonly BulkStatusTransitionService $bulkEngine,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly CaseAccessGuard $caseAccessGuard,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * List the transitions available to the current user on a case.
	 *
	 * @param string $caseId The case UUID
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function available(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		// Only PARTLY unguarded before this: the transition list itself is
		// role-filtered inside the engine, but the case's current status was
		// emitted unconditionally, so any authenticated user could read the
		// status of any case.
		if ($this->caseAccessGuard->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['error' => 'Not authorized'], Http::STATUS_FORBIDDEN);
		}

		try {
			$result = $this->transitionEngine->getAvailableTransitions(caseId: $caseId);
			return new JSONResponse($result);
		} catch (\Throwable $e) {
			$this->logger->error(
				'StatusTransitionController: available failed',
				['exception' => $e->getMessage(), 'caseId' => $caseId],
			);
			return new JSONResponse(
				['error' => 'Could not load available transitions'],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}
	}//end available()

	/**
	 * Execute a guarded transition.
	 *
	 * @param string $caseId The case UUID
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function execute(string $caseId): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$body = $this->readJsonBody();
		$transitionId = (string)($body['transitionId'] ?? '');
		$comment = null;
		if (isset($body['comment']) === true) {
			$comment = (string)$body['comment'];
		}

		// REQ-STE-12: the result the case closes with, when the target status
		// is final. The engine decides whether it is needed; the controller
		// only carries it.
		$resultTypeId = null;
		if (isset($body['resultTypeId']) === true) {
			$resultTypeId = (string)$body['resultTypeId'];
		}

		if ($transitionId === '') {
			return new JSONResponse(
				['error' => 'transitionId is required'],
				Http::STATUS_BAD_REQUEST,
			);
		}

		try {
			$result = $this->transitionEngine->execute(
				caseId: $caseId,
				transitionId: $transitionId,
				comment: $comment,
				resultTypeId: $resultTypeId,
			);
			return new JSONResponse($result);
		} catch (GuardFailedException $e) {
			return new JSONResponse(
				['error' => 'Transition is not available', 'failedGuards' => $e->getFailedGuards()],
				Http::STATUS_CONFLICT,
			);
		} catch (RuntimeException $e) {
			$code = $e->getMessage();
			$status = match ($code) {
				'case_not_found', 'transition_not_found' => Http::STATUS_NOT_FOUND,
				'forbidden_admin_only' => Http::STATUS_FORBIDDEN,
				'result_type_required' => Http::STATUS_UNPROCESSABLE_ENTITY,
				default => Http::STATUS_BAD_REQUEST,
			};

			// The one refusal the caller can act on: pick a result and retry.
			// Every other code stays behind the static message, per this
			// controller's contract.
			if ($code === 'result_type_required') {
				return new JSONResponse(
					['error' => 'A result is required to close this case', 'code' => $code],
					$status,
				);
			}

			$this->logger->info('StatusTransitionController: execute rejected', ['code' => $code]);
			return new JSONResponse(['error' => 'Could not execute transition'], $status);
		} catch (\Throwable $e) {
			$this->logger->error(
				'StatusTransitionController: execute failed',
				['exception' => $e->getMessage(), 'caseId' => $caseId, 'transitionId' => $transitionId],
			);
			return new JSONResponse(
				['error' => 'Could not execute transition'],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}//end try
	}//end execute()

	/**
	 * Execute an admin-only free-form transition.
	 *
	 * @param string $caseId The case UUID
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function freeform(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => 'Authentication required'],
				Http::STATUS_FORBIDDEN,
			);
		}

		$body = $this->readJsonBody();
		$toStatusId = (string)($body['toStatusId'] ?? '');
		$comment = null;
		if (isset($body['comment']) === true) {
			$comment = (string)$body['comment'];
		}

		if ($toStatusId === '') {
			return new JSONResponse(
				['error' => 'toStatusId is required'],
				Http::STATUS_BAD_REQUEST,
			);
		}

		try {
			$result = $this->transitionEngine->executeFreeForm(
				caseId: $caseId,
				toStatusId: $toStatusId,
				comment: $comment,
			);
			return new JSONResponse($result);
		} catch (RuntimeException $e) {
			$code = $e->getMessage();
			$status = match ($code) {
				'forbidden_admin_only' => Http::STATUS_FORBIDDEN,
				'case_not_found', 'case_type_not_found' => Http::STATUS_NOT_FOUND,
				default => Http::STATUS_BAD_REQUEST,
			};

			$this->logger->info('StatusTransitionController: freeform rejected', ['code' => $code]);
			return new JSONResponse(['error' => 'Could not execute free-form transition'], $status);
		} catch (\Throwable $e) {
			$this->logger->error(
				'StatusTransitionController: freeform failed',
				['exception' => $e->getMessage(), 'caseId' => $caseId],
			);
			return new JSONResponse(
				['error' => 'Could not execute free-form transition'],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}//end try
	}//end freeform()

	/**
	 * Return the chronological transition history of a case.
	 *
	 * @param string $caseId The case UUID
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function history(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->caseAccessGuard->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['error' => 'Not authorized'], Http::STATUS_FORBIDDEN);
		}

		try {
			$result = $this->transitionEngine->replay(caseId: $caseId);
			return new JSONResponse($result);
		} catch (\Throwable $e) {
			$this->logger->error(
				'StatusTransitionController: history failed',
				['exception' => $e->getMessage(), 'caseId' => $caseId],
			);
			return new JSONResponse(
				['error' => 'Could not load transition history'],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}
	}//end history()

	/**
	 * Preview a bulk gesture across multiple cases: per case, is it available
	 * and does the case allow it right now? Read-only — nothing on this path
	 * writes.
	 *
	 * Two gestures share the endpoint. Without a `gesture` (or with
	 * `gesture: "transition"`) this previews a status transition through the
	 * engine, unchanged. With `suspend`, `resume` or `extend` it previews the
	 * matching lifecycle gesture through `CaseLifecycleService::state()`.
	 * One endpoint rather than four because the PREVIEW, the per-case result
	 * map and the partial-failure reporting are the part worth keeping equal
	 * across all four bulk actions — which is also why one dialog serves them.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/case-bulk-status-transition/spec.md
	 * @spec openspec/changes/one-case-list/specs/case-bulk-status-transition/spec.md
	 */
	public function bulkPreview(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$body = $this->readJsonBody();
		$caseIds = $this->readCaseIds(body: $body);
		$transitionId = (string)($body['transitionId'] ?? '');
		$gesture = $this->readGesture(body: $body);

		try {
			if ($gesture !== self::GESTURE_TRANSITION) {
				return new JSONResponse(
					$this->bulkEngine->previewLifecycle(caseIds: $caseIds, gesture: $gesture),
				);
			}

			$result = $this->bulkEngine->preview(caseIds: $caseIds, transitionId: $transitionId);
			return new JSONResponse($result);
		} catch (RuntimeException $e) {
			$this->logger->info('StatusTransitionController: bulkPreview rejected', ['code' => $e->getMessage()]);
			return new JSONResponse(['error' => 'Could not preview bulk transition'], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->error(
				'StatusTransitionController: bulkPreview failed',
				['exception' => $e->getMessage(), 'transitionId' => $transitionId],
			);
			return new JSONResponse(
				['error' => 'Could not preview bulk transition'],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}
	}//end bulkPreview()

	/**
	 * Execute a bulk gesture across multiple cases. Loops the matching single
	 * write path once per case — the status engine's `execute()` for a
	 * transition, `CaseLifecycleService`'s `suspend()` / `resume()` /
	 * `extend()` for the three lifecycle gestures — so every guard and every
	 * automatic action a single case gets, a bulk case gets too. Partial
	 * success is allowed and reported per case, never silently swallowed.
	 *
	 * A lifecycle gesture REQUIRES a reason and answers 400 without one.
	 * Suspending, resuming and extending are statutory acts (Awb 4:5 and
	 * 4:14) that someone has to justify later; doing twenty of them at once
	 * is precisely when the justification is most likely to go unwritten, so
	 * the endpoint refuses rather than recording twenty blank ones.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/case-bulk-status-transition/spec.md
	 * @spec openspec/changes/one-case-list/specs/case-bulk-status-transition/spec.md
	 */
	public function bulkExecute(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$body = $this->readJsonBody();
		$caseIds = $this->readCaseIds(body: $body);
		$transitionId = (string)($body['transitionId'] ?? '');
		$gesture = $this->readGesture(body: $body);
		$comment = null;
		if (isset($body['comment']) === true) {
			$comment = (string)$body['comment'];
		}

		try {
			if ($gesture !== self::GESTURE_TRANSITION) {
				$reason = trim((string)($body['reason'] ?? ''));
				if ($reason === '') {
					return new JSONResponse(['error' => 'A reason is required'], Http::STATUS_BAD_REQUEST);
				}

				return new JSONResponse(
					$this->bulkEngine->executeLifecycle(
						caseIds: $caseIds,
						gesture: $gesture,
						reason: $reason,
						days: (int)($body['days'] ?? 0),
						newEndDate: (string)($body['newEndDate'] ?? ''),
					),
				);
			}

			$result = $this->bulkEngine->execute(caseIds: $caseIds, transitionId: $transitionId, comment: $comment);
			return new JSONResponse($result);
		} catch (RuntimeException $e) {
			$this->logger->info('StatusTransitionController: bulkExecute rejected', ['code' => $e->getMessage()]);
			return new JSONResponse(['error' => 'Could not execute bulk transition'], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->error(
				'StatusTransitionController: bulkExecute failed',
				['exception' => $e->getMessage(), 'transitionId' => $transitionId],
			);
			return new JSONResponse(
				['error' => 'Could not execute bulk transition'],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}
	}//end bulkExecute()

	/**
	 * The gesture a bulk body asks for when it names none: a status
	 * transition, which is what both endpoints did before the three
	 * lifecycle gestures joined them.
	 */
	private const GESTURE_TRANSITION = 'transition';

	/**
	 * The gestures a bulk body may name.
	 */
	private const GESTURES = [self::GESTURE_TRANSITION, 'suspend', 'resume', 'extend'];

	/**
	 * Read the requested gesture from a decoded request body.
	 *
	 * An absent or unrecognised gesture reads as `transition`, which keeps
	 * every existing caller — the workflow board's dialog, which sends no
	 * `gesture` at all — on exactly the path it was on.
	 *
	 * @param array<string, mixed> $body Decoded request body
	 *
	 * @return string One of the GESTURES
	 */
	private function readGesture(array $body): string {
		$gesture = (string)($body['gesture'] ?? self::GESTURE_TRANSITION);
		if (in_array($gesture, self::GESTURES, true) === false) {
			return self::GESTURE_TRANSITION;
		}

		return $gesture;
	}//end readGesture()

	/**
	 * Read and normalise the `caseIds` array from a decoded request body.
	 *
	 * @param array<string, mixed> $body Decoded request body
	 *
	 * @return array<int, string>
	 */
	private function readCaseIds(array $body): array {
		$caseIds = $body['caseIds'] ?? [];
		if (is_array($caseIds) === false) {
			return [];
		}

		$list = [];
		foreach ($caseIds as $caseId) {
			$list[] = (string)$caseId;
		}

		return $list;
	}//end readCaseIds()

	/**
	 * Decode a JSON request body safely.
	 *
	 * @return array<string, mixed>
	 */
	private function readJsonBody(): array {
		// Nextcloud's AppFramework auto-decodes a JSON request body and merges
		// it into the request params, exposed via the PUBLIC getParams(). The
		// raw getContent() accessor is PROTECTED on OC\AppFramework\Http\Request
		// and calling it from a controller raises a fatal "Call to protected
		// method" (HTTP 500) — which is exactly what broke the transition POST.
		return $this->request->getParams();
	}//end readJsonBody()
}//end class
