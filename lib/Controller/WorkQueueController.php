<?php

/**
 * Procest Work Queue Controller
 *
 * Exposes the intelligent work-queue endpoints: a user's urgency-scored
 * open cases/tasks, and (coordinator-only) a per-handler workload summary.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
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
 * @link https://procest.nl
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use DateTime;
use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\WorkQueueService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for the intelligent work-queue endpoints.
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */
class WorkQueueController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The HTTP request.
	 * @param IUserSession $userSession The user session.
	 * @param IGroupManager $groupManager The group manager (coordinator = NC admin guard).
	 * @param WorkQueueService $workQueueService The work queue scoring/aggregation service.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private WorkQueueService $workQueueService,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Return the authenticated user's urgency-scored open cases and tasks.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$items = $this->workQueueService->computeQueue($user->getUID());
			return new JSONResponse(
				[
					'items' => $items,
					'computedAt' => (new DateTime())->format(DateTime::ATOM),
				]
			);
		} catch (\Throwable $e) {
			$this->logger->error('WorkQueue: index failed', ['error' => $e->getMessage()]);
			return new JSONResponse(['error' => 'Failed to compute work queue'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end index()

	/**
	 * Return per-handler open-case counts. Coordinator-only (NC admin).
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
	 */
	#[NoAdminRequired]
	public function workload(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->isCoordinator(userId: $user->getUID()) === false) {
			return new JSONResponse(['error' => 'This action requires the coordinator role'], Http::STATUS_FORBIDDEN);
		}

		try {
			$handlers = $this->workQueueService->computeWorkload();
			return new JSONResponse(['handlers' => $handlers]);
		} catch (\Throwable $e) {
			$this->logger->error('WorkQueue: workload failed', ['error' => $e->getMessage()]);
			return new JSONResponse(['error' => 'Failed to compute workload'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end workload()

	/**
	 * Whether a user holds the procest coordinator role (NC admin).
	 *
	 * Coordinator authority is delegated to Nextcloud admin membership, the
	 * same model used elsewhere in procest (e.g.
	 * {@see \OCA\Procest\Controller\SubstitutionController::isCoordinator()}).
	 *
	 * @param string $userId The user id.
	 *
	 * @return bool
	 */
	private function isCoordinator(string $userId): bool {
		if ($userId === '') {
			return false;
		}

		return $this->groupManager->isAdmin($userId);
	}//end isCoordinator()
}//end class
