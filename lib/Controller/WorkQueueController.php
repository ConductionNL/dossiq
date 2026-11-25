<?php

/**
 * Dossiq Work Queue Controller
 *
 * Exposes the intelligent work-queue endpoints: a user's urgency-scored
 * open cases/tasks, and (coordinator-only) a per-handler workload summary.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
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
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use DateTime;
use OCA\Dossiq\Controller\Support\WorkloadScope;
use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\CoveredWorkInterface;
use OCA\Dossiq\Kernel\Contracts\WorkQueueServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for the intelligent work-queue endpoints.
 *
 * The queue and the covered work read their own collaborator; which handlers
 * the workload shows is the workload scope's answer.
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class WorkQueueController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The HTTP request.
	 * @param IUserSession $userSession The user session.
	 * @param WorkQueueServiceInterface $workQueueService The work queue scoring/aggregation service.
	 * @param LoggerInterface $logger Logger.
	 * @param CoveredWorkInterface $coveredWork The cases the caller covers for an absent colleague.
	 * @param WorkloadScope $scope The reader's teams and coordinator role, which scope the workload.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private WorkQueueServiceInterface $workQueueService,
		private LoggerInterface $logger,
		private CoveredWorkInterface $coveredWork,
		private WorkloadScope $scope,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Return the authenticated user's urgency-scored open cases and tasks.
	 *
	 * With `covering=1` the queue also holds the open cases the caller covers
	 * for an absent colleague (board DqAanMijToegewezen, "Inclusief werk
	 * waarvoor u waarneemt"), each marked `coveredFor`, and `coveredCount`
	 * says how many. Only cases the caller may already read are added.
	 *
	 * @param string $covering `1` to include the work the caller covers.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	#[NoAdminRequired]
	public function index(string $covering = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$covered = [];
			if (in_array(strtolower(trim($covering)), ['1', 'true'], true) === true) {
				$covered = $this->coveredWork->casesFor(user: $user);
			}

			$items = $this->workQueueService->computeQueue($user->getUID(), null, $covered);
			return new JSONResponse(
				[
					'items' => $items,
					'computedAt' => (new DateTime())->format(DateTime::ATOM),
					'coveredCount' => count(array_filter($items, static fn (array $item): bool => ($item['coveredFor'] ?? null) !== null)),
				]
			);
		} catch (\Throwable $e) {
			$this->logger->error('WorkQueue: index failed', ['error' => $e->getMessage()]);
			return new JSONResponse(['error' => 'Failed to compute work queue'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end index()

	/**
	 * Return per-handler open-case counts: for a coordinator (NC admin), and
	 * for a member of a team, who sees the people of their own teams only.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	#[NoAdminRequired]
	public function workload(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$teamGroupIds = $this->scope->teamGroupIds(user: $user);
		$inATeam      = ($teamGroupIds !== null && $teamGroupIds !== []);
		if ($inATeam === false && $this->scope->isCoordinator(userId: $user->getUID()) === false) {
			return new JSONResponse(
				['error' => 'This action requires the coordinator role or a team'],
				Http::STATUS_FORBIDDEN
			);
		}

		try {
			$handlers = $this->scope->withDisplayNames(
				handlers: $this->scope->ofTeams(teamGroupIds: $teamGroupIds, handlers: $this->workQueueService->computeWorkload())
			);
			return new JSONResponse(['handlers' => $handlers]);
		} catch (\Throwable $e) {
			$this->logger->error('WorkQueue: workload failed', ['error' => $e->getMessage()]);
			return new JSONResponse(['error' => 'Failed to compute workload'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end workload()
}//end class
