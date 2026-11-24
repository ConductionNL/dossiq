<?php

/**
 * Dossiq workload scope.
 *
 * Which handlers a reader of the workload sees: a coordinator (Nextcloud
 * admin) sees every handler, a member of a team sees the people of their own
 * teams only, each with their display name.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller\Support
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
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCA\Dossiq\Kernel\Contracts\PrimaryTeamServiceInterface;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * The reader's teams, the coordinator check, and the workload rows those scope.
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md#requirement-coordinator-workload-endpoint-mvp
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class WorkloadScope {

	/**
	 * Constructor.
	 *
	 * @param IGroupManager               $groupManager The group manager (coordinator = NC admin guard, team members).
	 * @param IUserManager                $userManager  Resolves a handler's display name.
	 * @param PrimaryTeamServiceInterface $teams        The reader's teams, which scope the workload.
	 * @param LoggerInterface             $logger       Logger.
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
		private readonly PrimaryTeamServiceInterface $teams,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The groups of the reader's teams, or null (logged) when they cannot be read.
	 *
	 * @param IUser $user The reader.
	 *
	 * @return array<mixed>|null
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md#requirement-coordinator-workload-endpoint-mvp
	 */
	public function teamGroupIds(IUser $user): ?array {
		try {
			return array_column($this->teams->teamsOf(user: $user), 'groupId');
		} catch (\Throwable $e) {
			$this->logger->warning('WorkQueue: the reader\'s teams could not be read', ['error' => $e->getMessage()]);
			return null;
		}
	}//end teamGroupIds()

	/**
	 * Keep the handlers who are members of the given teams, a member without
	 * open cases with 0; every handler when there are no teams (only a
	 * coordinator gets that far without one).
	 *
	 * @param array<mixed>|null                                      $teamGroupIds The teams' groups, or null when unreadable.
	 * @param array<int, array{handler: string, openCaseCount: int}> $handlers     The per-handler counts.
	 *
	 * @return array<int, array<string, mixed>> The handlers of those teams.
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md#requirement-coordinator-workload-endpoint-mvp
	 */
	public function ofTeams(?array $teamGroupIds, array $handlers): array {
		if ($teamGroupIds === null || $teamGroupIds === []) {
			return $handlers;
		}

		return $this->withMembers(handlers: $handlers, members: $this->membersOf(groupIds: $teamGroupIds));
	}//end ofTeams()

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
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function isCoordinator(string $userId): bool {
		if ($userId === '') {
			return false;
		}

		return $this->groupManager->isAdmin($userId);
	}//end isCoordinator()

	/**
	 * Everyone in the given groups, as a set keyed by uid.
	 *
	 * @param array<mixed> $groupIds The groups.
	 *
	 * @return array<true>
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md#requirement-coordinator-workload-endpoint-mvp
	 */
	private function membersOf(array $groupIds): array {
		$members = [];
		foreach ($groupIds as $groupId) {
			foreach (array_keys($this->groupManager->displayNamesInGroup(gid: $groupId)) as $uid) {
				$members[(string)$uid] = true;
			}
		}

		return $members;
	}//end membersOf()

	/**
	 * The handler rows of the members, then a zero row for each member without one.
	 *
	 * @param array<int, array<string, mixed>> $handlers The workload rows.
	 * @param array<true>                      $members  The members, keyed by uid.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md#requirement-coordinator-workload-endpoint-mvp
	 */
	private function withMembers(array $handlers, array $members): array {
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
	}//end withMembers()

	/**
	 * Add each handler's display name, so a list can show a person rather than a user id.
	 *
	 * A handler that is no longer a user keeps the id as its name.
	 *
	 * @param array<int, array<string, mixed>> $handlers The per-handler counts.
	 *
	 * @return array<int, array<string, mixed>> The counts with names.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function withDisplayNames(array $handlers): array {
		foreach ($handlers as $index => $row) {
			$handlers[$index]['displayName'] = ($this->userManager->getDisplayName($row['handler']) ?? $row['handler']);
		}

		return $handlers;
	}//end withDisplayNames()
}//end class
