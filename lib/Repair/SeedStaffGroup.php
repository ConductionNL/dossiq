<?php

/**
 * Put the people who work in dossiq today into its staff group, once.
 *
 * `case-access-by-handling-team` (Ruben, 2026-10-11) closes the dossiq register
 * to anybody outside `dossiq-medewerkers` (REQ-ACC-11). On the upgrade that
 * ships it, that group is new and empty but for the administrators
 * {@see ProvisionAssignedGroups} seeds into it, so every handler would lose
 * the case types, the documents and the rest of the register at once. This
 * step adds the people who demonstrably work in dossiq: every member of a
 * group that handles a case type, and every user a case is assigned to
 * (REQ-ACC-12).
 *
 * 🔴 ONCE PER INSTANCE. The step records that it ran (app config
 * `staff_group_seeded`) and never runs again. Running on every upgrade would
 * put back somebody an administrator took out of the group on purpose, which
 * turns a removal into a request that is silently undone a release later.
 *
 * 🔴 IT DOES NOT RECORD A RUN THAT COULD NOT READ. Without OpenRegister, or
 * without the group, nothing is seeded and the marker is left unset, so the
 * next upgrade tries again rather than reporting a seeding that never happened.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
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
 * @spec openspec/specs/case-access-control/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Access\CaseTeamAccessProjector;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Seeds `dossiq-medewerkers` with the current handling teams and assignees.
 *
 * @spec openspec/specs/case-access-control/spec.md
 */
class SeedStaffGroup implements IRepairStep {

	use SearchesObjects;

	/**
	 * The staff group the register grants (register.d/91-register-access.json).
	 */
	public const STAFF_GROUP = 'dossiq-medewerkers';

	/**
	 * The app config key that records the seeding ran.
	 */
	public const MARKER = 'staff_group_seeded';

	/**
	 * Rows per page when reading the cases.
	 */
	private const PAGE = 200;

	/**
	 * A ceiling on the paging, so a store that never answers a short page
	 * cannot keep an upgrade running for ever.
	 */
	private const MAX_PAGES = 500;

	/**
	 * Constructor.
	 *
	 * @param SettingsService         $settingsService Names the register and the case schema, and hands out the object service.
	 * @param CaseTeamAccessProjector $teamAccess      The one reader of every case type's handling teams.
	 * @param IGroupManager           $groupManager    Reads the teams and holds the staff group.
	 * @param IUserManager            $userManager     Resolves an assignee to an account.
	 * @param IAppConfig              $appConfig       Holds the marker.
	 * @param LoggerInterface         $logger          The logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseTeamAccessProjector $teamAccess,
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name in the upgrade output.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/specs/case-access-control/spec.md
	 */
	public function getName(): string {
		return 'Put the current handling teams and assignees into the dossiq staff group';
	}//end getName()

	/**
	 * Seed the group, once.
	 *
	 * @param IOutput $output The upgrade output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-access-control/spec.md
	 */
	public function run(IOutput $output): void {
		if ($this->appConfig->getValueBool(Application::APP_ID, self::MARKER, false) === true) {
			return;
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			$output->info('Staff group: OpenRegister unavailable, seeding left for the next upgrade.');
			return;
		}

		$group = $this->staffGroup();
		if ($group === null) {
			$output->warning('Staff group: "' . self::STAFF_GROUP . '" could not be created, seeding left for the next upgrade.');
			return;
		}

		try {
			$userIds = (array)$this->runAsSystemIfAvailable(
				objectService: $objectService,
				operation: fn (): array => array_merge(
					$this->handlingTeamMembers(),
					$this->assignees(objectService: $objectService)
				)
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: the staff group could not be seeded',
				['app' => Application::APP_ID, 'error' => $e->getMessage()]
			);
			$output->warning('Staff group: the case types or cases could not be read, seeding left for the next upgrade.');
			return;
		}

		$added = $this->addAll(group: $group, userIds: $userIds);
		$this->appConfig->setValueBool(Application::APP_ID, self::MARKER, true);
		$output->info('Staff group: ' . $added . ' people added to "' . self::STAFF_GROUP . '".');
	}//end run()

	/**
	 * The staff group, created when it is missing.
	 *
	 * @return IGroup|null The group, or null when the backend refuses it.
	 */
	private function staffGroup(): ?IGroup {
		$group = $this->groupManager->get(self::STAFF_GROUP);
		if ($group !== null) {
			return $group;
		}

		return $this->groupManager->createGroup(self::STAFF_GROUP);
	}//end staffGroup()

	/**
	 * The members of every group that handles a case type.
	 *
	 * @return array<int, string> User ids, possibly repeated.
	 */
	private function handlingTeamMembers(): array {
		$teams = [];
		foreach ($this->teamAccess->caseTypeTeams() as $caseType) {
			foreach ($caseType['teams'] as $team) {
				$teams[$team] = true;
			}
		}

		$userIds = [];
		foreach (array_keys($teams) as $team) {
			$group = $this->groupManager->get((string)$team);
			if ($group === null) {
				continue;
			}

			foreach ($group->getUsers() as $user) {
				$userIds[] = $user->getUID();
			}
		}

		return $userIds;
	}//end handlingTeamMembers()

	/**
	 * Every user a case is assigned to.
	 *
	 * @param object $objectService The OpenRegister object service.
	 *
	 * @return array<int, string> User ids, possibly repeated.
	 */
	private function assignees(object $objectService): array {
		$register = (string)$this->settingsService->getConfigValue('register');
		$caseSchema = (string)$this->settingsService->getConfigValue('case_schema');
		if ($register === '' || $caseSchema === '') {
			return [];
		}

		$userIds = [];
		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			$rows = $this->searchObjectsAsArraysUnscoped(
				objectService: $objectService,
				register: $register,
				schema: $caseSchema,
				filters: ['_limit' => self::PAGE, '_offset' => ($page * self::PAGE)]
			);

			foreach ($rows as $row) {
				$assignee = ($row['assignee'] ?? null);
				if (is_string($assignee) === true && trim($assignee) !== '') {
					$userIds[] = trim($assignee);
				}
			}

			if (count($rows) < self::PAGE) {
				break;
			}
		}

		return $userIds;
	}//end assignees()

	/**
	 * Add every existing account that is not a member yet.
	 *
	 * @param IGroup             $group   The staff group.
	 * @param array<int, string> $userIds The candidates.
	 *
	 * @return int How many were added.
	 */
	private function addAll(IGroup $group, array $userIds): int {
		$added = 0;
		foreach (array_unique($userIds) as $userId) {
			$user = $this->userManager->get($userId);
			if ($user === null || $group->inGroup($user) === true) {
				continue;
			}

			$group->addUser($user);
			$added++;
		}

		return $added;
	}//end addAll()
}//end class
