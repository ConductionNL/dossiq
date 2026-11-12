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
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Controller for status-transition engine endpoints.
 *
 * @spec openspec/changes/archive/2026-05-11-status-transition-engine/tasks.md#T11
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
	 * @param IL10N|null $l10n The translator a refusal's sentence is written in; the app's own when left out
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly StatusTransitionService $transitionEngine,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly CaseAccessGuard $caseAccessGuard,
		private readonly ?IL10N $l10n = null,
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
		if ($transitionId === '') {
			return new JSONResponse(
				['error' => 'transitionId is required'],
				Http::STATUS_BAD_REQUEST,
			);
		}

		return $this->executeTransition(caseId: $caseId, body: $body);
	}//end execute()

	/**
	 * Hand the transition to the engine and answer with its result or its refusal.
	 *
	 * @param string               $caseId The case UUID.
	 * @param array<string, mixed> $body   The request body, carrying a transitionId.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function executeTransition(string $caseId, array $body): JSONResponse {
		$transitionId = (string)($body['transitionId'] ?? '');

		try {
			// REQ-STE-12: the result the case closes with, when the target status
			// is final. The engine decides whether it is needed; the controller
			// only carries it.
			$result = $this->transitionEngine->execute(
				caseId: $caseId,
				transitionId: $transitionId,
				comment: $this->optionalString(body: $body, key: 'comment'),
				resultTypeId: $this->optionalString(body: $body, key: 'resultTypeId'),
			);
			return new JSONResponse($result);
		} catch (GuardFailedException $e) {
			return $this->guardFailed(e: $e);
		} catch (RefusedException $e) {
			return $this->refused(op: 'execute', e: $e);
		} catch (RuntimeException $e) {
			return $this->executeRejected(e: $e);
		} catch (\Throwable $e) {
			$this->logger->error(
				'StatusTransitionController: execute failed',
				['exception' => $e->getMessage(), 'caseId' => $caseId, 'transitionId' => $transitionId],
			);
			return $this->serverError(error: 'Could not execute transition');
		}//end try
	}//end executeTransition()

	/**
	 * A body field as a string, or null when the body does not carry it.
	 *
	 * @param array<string, mixed> $body The request body.
	 * @param string               $key  The field.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function optionalString(array $body, string $key): ?string {
		if (isset($body[$key]) === true) {
			return (string)$body[$key];
		}

		return null;
	}//end optionalString()

	/**
	 * The 409 a move blocked by a rule on the case answers with.
	 *
	 * @param GuardFailedException $e The failed guards.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function guardFailed(GuardFailedException $e): JSONResponse {
		return new JSONResponse(
			[
				'message' => 'This move is blocked by a rule on the case.',
				'error' => 'transition-guard-failed',
				'code' => $e->getMessage(),
				'failedGuards' => $e->getFailedGuards(),
			],
			Http::STATUS_CONFLICT,
		);
	}//end guardFailed()

	/**
	 * The answer to a guarded transition the engine rejected with a code.
	 *
	 * @param RuntimeException $e The engine's rejection.
	 *
	 * @return JSONResponse 404 for an unknown case or transition, 403 for an admin-only move, else 400.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function executeRejected(RuntimeException $e): JSONResponse {
		$code = $e->getMessage();
		$status = match ($code) {
			'case_not_found', 'transition_not_found' => Http::STATUS_NOT_FOUND,
			'forbidden_admin_only' => Http::STATUS_FORBIDDEN,
			default => Http::STATUS_BAD_REQUEST,
		};

		$this->logger->info('StatusTransitionController: execute rejected', ['code' => $code]);
		return new JSONResponse(['error' => 'Could not execute transition'], $status);
	}//end executeRejected()

	/**
	 * A 500 with the endpoint's error.
	 *
	 * @param string $error The error the endpoint answers with.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function serverError(string $error): JSONResponse {
		return new JSONResponse(
			['error' => $error],
			Http::STATUS_INTERNAL_SERVER_ERROR,
		);
	}//end serverError()

	/**
	 * Execute an admin-only free-form transition.
	 *
	 * 🔑 THIS IS THE HAND-SET PATH, and REQ-LIFE-03 is enforced on it inside
	 * {@see StatusTransitionService::executeFreeForm()} rather than here. The
	 * engine owns the write, so any other caller it grows gets the rule too,
	 * and this controller keeps one collaborator fewer.
	 *
	 * @param string $caseId The case UUID
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 * @spec openspec/specs/case-status-machinery/spec.md
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
		if ($toStatusId === '') {
			return new JSONResponse(
				['error' => 'toStatusId is required'],
				Http::STATUS_BAD_REQUEST,
			);
		}

		return $this->executeFreeForm(caseId: $caseId, body: $body);
	}//end freeform()

	/**
	 * Hand the free-form move to the engine and answer with its result or its refusal.
	 *
	 * @param string               $caseId The case UUID.
	 * @param array<string, mixed> $body   The request body, carrying a toStatusId.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/case-status-machinery/spec.md
	 */
	private function executeFreeForm(string $caseId, array $body): JSONResponse {
		$toStatusId = (string)($body['toStatusId'] ?? '');

		try {
			$result = $this->transitionEngine->executeFreeForm(
				caseId: $caseId,
				toStatusId: $toStatusId,
				comment: $this->optionalString(body: $body, key: 'comment'),
			);
			return new JSONResponse($result);
		} catch (RefusedException $e) {
			return $this->refused(op: 'freeform', e: $e);
		} catch (RuntimeException $e) {
			return $this->freeformRejected(e: $e);
		} catch (\Throwable $e) {
			$this->logger->error(
				'StatusTransitionController: freeform failed',
				['exception' => $e->getMessage(), 'caseId' => $caseId],
			);
			return $this->serverError(error: 'Could not execute free-form transition');
		}//end try
	}//end executeFreeForm()

	/**
	 * The answer to a free-form transition the engine rejected with a code.
	 *
	 * @param RuntimeException $e The engine's rejection.
	 *
	 * @return JSONResponse 403 for a caller who is not an admin, 404 for an unknown case or case type, else 400.
	 *
	 * @spec openspec/specs/case-status-machinery/spec.md
	 */
	private function freeformRejected(RuntimeException $e): JSONResponse {
		$code = $e->getMessage();
		$status = match ($code) {
			'forbidden_admin_only' => Http::STATUS_FORBIDDEN,
			'case_not_found', 'case_type_not_found' => Http::STATUS_NOT_FOUND,
			default => Http::STATUS_BAD_REQUEST,
		};

		$this->logger->info('StatusTransitionController: freeform rejected', ['code' => $code]);
		return new JSONResponse(['error' => 'Could not execute free-form transition'], $status);
	}//end freeformRejected()

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

	/**
	 * The translator a refusal's sentence is written in.
	 *
	 * @return IL10N|null The injected translator, or null for the app's.
	 *
	 * @spec openspec/specs/frontend-locale-completeness/spec.md
	 */
	protected function refusalL10n(): ?IL10N {
		return $this->l10n;
	}//end refusalL10n()
}//end class
