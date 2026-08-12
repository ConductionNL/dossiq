<?php

/**
 * Procest CMMN Case-Plan Controller.
 *
 * REST surface for the CMMN adaptive-case engine (`CaseModelEngine`), the
 * counterpart to `StatusTransitionController` for CMMN-managed caseTypes:
 *
 *  - GET  /api/case/{caseId}/cmmn-plan
 *  - POST /api/case/{caseId}/cmmn-plan/enable    (body {itemId})
 *  - POST /api/case/{caseId}/cmmn-plan/complete  (body {itemId})
 *  - POST /api/case/{caseId}/cmmn-plan/terminate (body {itemId})
 *  - POST /api/case/{caseId}/cmmn-plan/signal    (body {updates: {caseFileItemId: value, ...}})
 *
 * Error responses use static messages — `$e->getMessage()` is NEVER returned.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
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
 * @link https://procest.nl
 *
 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-007
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\Cmmn\CaseModelEngine;
use OCA\Procest\Service\Cmmn\IllegalPlanItemTransitionException;
use OCA\Procest\Service\StatusTransitionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Controller for the CMMN case-plan engine endpoints.
 *
 * @spec openspec/changes/cmmn-adaptive-case/tasks.md#3
 */
class CmmnCaseController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The HTTP request.
	 * @param CaseModelEngine $engine The CMMN runtime engine.
	 * @param IUserSession $userSession The current session.
	 * @param IGroupManager $groupManager Group manager (OR-RBAC gate).
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CaseModelEngine $engine,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Get the current case plan: items, states, enable-able discretionary
	 * items, milestones, and the case-file snapshot.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-007
	 */
	public function plan(string $caseId): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse($this->engine->getCasePlan(caseId: $caseId));
		} catch (RuntimeException $e) {
			return $this->mapRuntimeError(e: $e, action: 'plan');
		} catch (Throwable $e) {
			$this->logger->error('CmmnCaseController: plan failed', ['exception' => $e->getMessage(), 'caseId' => $caseId]);
			return new JSONResponse(['error' => 'Could not load case plan'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end plan()

	/**
	 * Enable a discretionary plan item.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-004
	 */
	public function enable(string $caseId): JSONResponse {
		return $this->mutate(
			caseId: $caseId,
			perform: fn (string $itemId): array => $this->engine->enableDiscretionaryItem(caseId: $caseId, itemId: $itemId),
			action: 'enable',
		);
	}//end enable()

	/**
	 * Complete an active human task.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-007
	 */
	public function complete(string $caseId): JSONResponse {
		return $this->mutate(
			caseId: $caseId,
			perform: fn (string $itemId): array => $this->engine->completeTask(caseId: $caseId, itemId: $itemId),
			action: 'complete',
		);
	}//end complete()

	/**
	 * Terminate a human task.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-007
	 */
	public function terminate(string $caseId): JSONResponse {
		return $this->mutate(
			caseId: $caseId,
			perform: fn (string $itemId): array => $this->engine->terminateTask(caseId: $caseId, itemId: $itemId),
			action: 'terminate',
		);
	}//end terminate()

	/**
	 * Signal a case-file item change, tripping any dependent sentries.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-003
	 */
	public function signal(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$body = $this->readJsonBody();
		$updates = $body['updates'] ?? [];
		if (is_array($updates) === false || $updates === []) {
			return new JSONResponse(['error' => 'updates is required'], Http::STATUS_BAD_REQUEST);
		}

		try {
			return new JSONResponse($this->engine->signalCaseFileEvent(caseId: $caseId, updates: $updates));
		} catch (RuntimeException $e) {
			return $this->mapRuntimeError(e: $e, action: 'signal');
		} catch (Throwable $e) {
			$this->logger->error('CmmnCaseController: signal failed', ['exception' => $e->getMessage(), 'caseId' => $caseId]);
			return new JSONResponse(['error' => 'Could not signal case-file event'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end signal()

	/**
	 * Shared implementation for the item-mutating endpoints (enable/complete/
	 * terminate): authenticate, read `itemId`, enforce the item's
	 * OR-RBAC group-authorization gate, invoke the engine, map errors.
	 *
	 * @param string $caseId The case UUID.
	 * @param callable(string): array $perform The engine call to invoke, given the item id.
	 * @param string $action Action name, for logging only.
	 *
	 * @return JSONResponse
	 */
	private function mutate(string $caseId, callable $perform, string $action): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$body = $this->readJsonBody();
		$itemId = (string)($body['itemId'] ?? '');
		if ($itemId === '') {
			return new JSONResponse(['error' => 'itemId is required'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$authorized = $this->isAuthorizedForItem(caseId: $caseId, itemId: $itemId, userId: $user->getUID());
			if ($authorized === false) {
				$this->logger->info(
					'CmmnCaseController: mutate rejected (unauthorized)',
					['action' => $action, 'caseId' => $caseId, 'itemId' => $itemId],
				);
				return new JSONResponse(['error' => 'Not authorized'], Http::STATUS_FORBIDDEN);
			}

			return new JSONResponse($perform($itemId));
		} catch (IllegalPlanItemTransitionException $e) {
			return new JSONResponse(
				[
					'error' => 'Transition is not available',
					'from' => $e->getFromState(),
					'to' => $e->getToState(),
				],
				Http::STATUS_CONFLICT,
			);
		} catch (RuntimeException $e) {
			return $this->mapRuntimeError(e: $e, action: $action);
		} catch (Throwable $e) {
			$this->logger->error(
				'CmmnCaseController: mutate failed',
				['exception' => $e->getMessage(), 'action' => $action, 'caseId' => $caseId, 'itemId' => $itemId],
			);
			return new JSONResponse(['error' => 'Could not process case-plan action'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try
	}//end mutate()

	/**
	 * Enforce the plan item's optional `authorization: string[]` gate,
	 * mirroring `StatusTransitionService::isTransitionGroupAuthorized()`:
	 * an absent/empty list authorises everyone, an anonymous caller can
	 * never satisfy a group gate, admins bypass, otherwise the caller must
	 * belong to at least one listed group.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $itemId Plan-item id.
	 * @param string $userId Acting user UID.
	 *
	 * @return bool
	 *
	 * @throws RuntimeException Propagated from the engine's context load (case/item not found etc.).
	 */
	private function isAuthorizedForItem(string $caseId, string $itemId, string $userId): bool {
		$authorization = $this->engine->getPlanItemAuthorization(caseId: $caseId, itemId: $itemId);
		if ($authorization === []) {
			return true;
		}

		if ($userId === '') {
			return false;
		}

		if ($this->isAdmin(userId: $userId) === true) {
			return true;
		}

		foreach ($authorization as $groupId) {
			$groupId = (string)$groupId;
			if ($groupId === '') {
				continue;
			}

			try {
				if ($this->groupManager->isInGroup($userId, $groupId) === true) {
					return true;
				}
			} catch (Throwable $e) {
				$this->logger->error('CmmnCaseController: group membership check failed', ['exception' => $e->getMessage(), 'groupId' => $groupId]);
			}
		}

		return false;
	}//end isAuthorizedForItem()

	/**
	 * Check membership in the procest admin group or the global admin group.
	 *
	 * @param string $userId UID.
	 *
	 * @return bool
	 */
	private function isAdmin(string $userId): bool {
		try {
			if ($this->groupManager->isInGroup($userId, StatusTransitionService::ADMIN_GROUP_ID) === true) {
				return true;
			}

			return $this->groupManager->isInGroup($userId, 'admin');
		} catch (Throwable $e) {
			$this->logger->error('CmmnCaseController: admin check failed', ['exception' => $e->getMessage()]);
			return false;
		}
	}//end isAdmin()

	/**
	 * Map a engine RuntimeException code to an HTTP status.
	 *
	 * @param RuntimeException $e The exception.
	 * @param string $action Action name, for logging only.
	 *
	 * @return JSONResponse
	 */
	private function mapRuntimeError(RuntimeException $e, string $action): JSONResponse {
		$code = $e->getMessage();
		$status = match ($code) {
			'case_not_found', 'case_type_not_found', 'plan_item_not_found' => Http::STATUS_NOT_FOUND,
			'case_not_cmmn_managed', 'not_a_human_task' => Http::STATUS_CONFLICT,
			default => Http::STATUS_BAD_REQUEST,
		};

		$this->logger->info('CmmnCaseController: rejected', ['action' => $action, 'code' => $code]);
		return new JSONResponse(['error' => 'Could not process case-plan request'], $status);
	}//end mapRuntimeError()

	/**
	 * Decode a JSON request body safely (NC AppFramework auto-decodes into params).
	 *
	 * @return array<string, mixed>
	 */
	private function readJsonBody(): array {
		return $this->request->getParams();
	}//end readJsonBody()
}//end class
