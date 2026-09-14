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
 *
 * A bulk transition is NOT here. It is one act of OpenRegister's bulk job,
 * declared by {@see \OCA\Dossiq\BulkAction\TransitionCasesAction}, which
 * calls this same engine once per case. What went with it is the loop.
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

use OCA\Dossiq\Controller\Support\TranslatesRefusals;
use OCA\Dossiq\Exception\RefusedException;
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
	use TranslatesRefusals;

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name
	 * @param IRequest $request The HTTP request
	 * @param StatusTransitionService $transitionEngine The engine service
	 * @param IUserSession $userSession The current session
	 * @param LoggerInterface $logger The logger
	 * @param CaseAccessGuard $caseAccessGuard Per-case authorization (fails closed)
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly StatusTransitionService $transitionEngine,
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
				[
					'message' => 'This move is blocked by a rule on the case.',
					'error' => 'transition-guard-failed',
					'code' => $e->getMessage(),
					'failedGuards' => $e->getFailedGuards(),
				],
				Http::STATUS_CONFLICT,
			);
		} catch (RefusedException $e) {
			return $this->refused(op: 'execute', e: $e);
		} catch (RuntimeException $e) {
			$code = $e->getMessage();
			$status = match ($code) {
				'case_not_found', 'transition_not_found' => Http::STATUS_NOT_FOUND,
				'forbidden_admin_only' => Http::STATUS_FORBIDDEN,
				default => Http::STATUS_BAD_REQUEST,
			};

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
		} catch (RefusedException $e) {
			return $this->refused(op: 'freeform', e: $e);
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
