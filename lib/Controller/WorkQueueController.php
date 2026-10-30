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
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Substitution\CoveredWork;
use OCA\Dossiq\Service\Team\PrimaryTeamService;
use OCA\Dossiq\Service\WorkQueueService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for the intelligent work-queue endpoints.
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) — the queue, the workload and
 *  the covered work each read their own collaborator; the workload also needs
 *  the reader's teams.
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
	 * @param IUserManager $userManager Resolves a handler's display name.
	 * @param CoveredWork $coveredWork The cases the caller covers for an absent colleague.
	 * @param PrimaryTeamService $teams The reader's teams, which scope the workload.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private WorkQueueService $workQueueService,
		private LoggerInterface $logger,
		private IUserManager $userManager,
		private CoveredWork $coveredWork,
		private PrimaryTeamService $teams,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
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
			$handlers = $this->withDisplayNames(
				handlers: $this->ofReadersTeams(user: $user, handlers: $this->workQueueService->computeWorkload())
			);
			return new JSONResponse(['handlers' => $handlers]);
		} catch (\Throwable $e) {
			$this->logger->error('WorkQueue: workload failed', ['error' => $e->getMessage()]);
			return new JSONResponse(['error' => 'Failed to compute workload'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end workload()

	/**
	 * Keep the handlers who are members of one of the reader's teams, and list
	 * a member without open cases with 0, so a free colleague shows; every
	 * handler for a reader in no team.
	 *
	 * @param IUser                                                  $user     The reader.
	 * @param array<int, array{handler: string, openCaseCount: int}> $handlers The per-handler counts.
	 *
	 * @return array<int, array{handler: string, openCaseCount: int}> The handlers of the reader's teams.
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md#requirement-coordinator-workload-endpoint-mvp
	 */
	private function ofReadersTeams(IUser $user, array $handlers): array {
		try {
			$teamGroupIds = array_column($this->teams->teamsOf(user: $user), 'groupId');
		} catch (\Throwable $e) {
			$this->logger->warning('WorkQueue: the reader\'s teams could not be read', ['error' => $e->getMessage()]);
			return $handlers;
		}

		if ($teamGroupIds === []) {
			return $handlers;
		}

		$members = [];
		foreach ($teamGroupIds as $groupId) {
			foreach (array_keys($this->groupManager->displayNamesInGroup(gid: $groupId)) as $uid) {
				$members[(string)$uid] = true;
			}
		}

		$listed = [];
		$result = [];
		foreach ($handlers as $row) {
			if (isset($members[$row['handler']]) === true) {
				$result[] = $row;
				$listed[$row['handler']] = true;
			}
		}

		foreach (array_keys($members) as $uid) {
			if (isset($listed[$uid]) === false) {
				$result[] = ['handler' => $uid, 'openCaseCount' => 0];
			}
		}

		return $result;
	}//end ofReadersTeams()

	/**
	 * Add each handler's display name, so a list can show a person rather than a user id.
	 *
	 * A handler that is no longer a user keeps the id as its name.
	 *
	 * @param array<int, array{handler: string, openCaseCount: int}> $handlers The per-handler counts.
	 *
	 * @return array<int, array{handler: string, openCaseCount: int, displayName: string}> The counts with names.
	 */
	private function withDisplayNames(array $handlers): array {
		foreach ($handlers as $index => $row) {
			$handlers[$index]['displayName'] = ($this->userManager->getDisplayName($row['handler']) ?? $row['handler']);
		}

		return $handlers;
	}//end withDisplayNames()

	/**
	 * Whether a user holds the dossiq coordinator role (NC admin).
	 *
	 * Coordinator authority is delegated to Nextcloud admin membership, the
	 * same model used elsewhere in dossiq (e.g.
	 * {@see \OCA\Dossiq\Controller\SubstitutionController::isCoordinator()}).
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
