<?php

/**
 * Dossiq Provision Assigned Groups Repair Step.
 *
 * The shipped case flow assigns its behandelaar step to the Nextcloud group
 * `behandelaars` (dossiq_register.json, node `task-behandelaar`), but nothing
 * ever created that group. On a fresh install the completion signal is then
 * refused fail-closed ("the user who completed the task is not the assignee
 * of the awaiting step"): the assignee gate resolves group membership, and
 * membership of a group that does not exist is false for everyone.
 *
 * 🔴 CREATING THE GROUP IS NOT SUFFICIENT, AND THIS DOCBLOCK USED TO CLAIM IT
 * WAS. Measured on two fresh rigs: the group existed with ZERO members, the
 * employee-task signal was refused exactly as before, and the live-journey
 * suite sat at 6 of 9. It reached 9 of 9 after a single
 * `occ group:adduser behandelaars admin`. An empty group answers "is this
 * user a member" with false for every user, which is the same answer a
 * missing group gives — so an empty group is a group that does not exist,
 * as far as the gate is concerned.
 *
 * So a group this step CREATES is also seeded, with the instance's
 * administrators. That is the one membership an install can know: the
 * accounts that exist at install time and can already do everything the
 * shipped journey needs. It grants them nothing they did not have, and it is
 * visible in Users & groups, where an administrator removes it in one click
 * once real behandelaars exist.
 *
 * Idempotent, and the seeding is bounded by the SAME condition as the
 * creation: an EXISTING group is left exactly as it is, membership and all.
 * Membership of a group an administrator manages is never this step's to
 * change — only membership of a group this step just minted, which nobody
 * else has had the chance to curate.
 *
 * The step deliberately does NOT reassign the flow to a different actor:
 * pointing shipped work at `admin` would hide a provisioning gap behind an
 * over-privileged default. Seeding a group dossiq itself created is the
 * opposite move — the assignment stays where it is, and the principal it
 * names becomes real.
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Kernel\Contracts\ProvisionAssignedGroupsInterface;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Creates the Nextcloud groups the shipped register data assigns work to.
 *
 * @spec openspec/specs/case-management/spec.md
 */
class ProvisionAssignedGroups implements IRepairStep, ProvisionAssignedGroupsInterface {

	/**
	 * Constructor.
	 *
	 * @param IGroupManager $groupManager Group manager used to provision the groups.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the repair-step display name.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function getName(): string {
		return 'Provision the Nextcloud groups Dossiq\'s shipped flows assign work to';
	}//end getName()

	/**
	 * Create each missing assigned group.
	 *
	 * @param IOutput $output Output sink.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function run(IOutput $output): void {
		foreach (self::ASSIGNED_GROUPS as $groupId) {
			if ($this->groupManager->groupExists($groupId) === true) {
				continue;
			}

			$group = $this->groupManager->createGroup($groupId);
			if ($group === null) {
				// A backend can refuse group creation (e.g. LDAP-only setups).
				// That must be loud: without the group the shipped flow's
				// completion signal is refused for every actor.
				$output->warning(
					'Dossiq: could not create group "' . $groupId . '"; shipped flow steps assigned to it cannot be completed until an admin creates it.'
				);
				$this->logger->warning(
					'Dossiq: group provisioning refused by the backend',
					['group' => $groupId]
				);
				continue;
			}

			$output->info('Dossiq: created group "' . $groupId . '" for shipped flow assignments.');
			$this->logger->info('Dossiq: provisioned assigned group', ['group' => $groupId]);

			$this->seedWithAdministrators(minted: new MintedGroup(group: $group, groupId: $groupId), output: $output);
		}
	}//end run()

	/**
	 * Give a freshly created group its first members.
	 *
	 * Only ever called for a group this step just minted, so it cannot touch
	 * a membership list an administrator curated. See the class docblock for
	 * why an empty group is measurably no better than a missing one.
	 *
	 * A group that ends up empty anyway — no administrators resolve, or the
	 * backend refuses the additions — is reported as a warning naming the occ
	 * command that fixes it. Reporting "created" over a group nothing can
	 * complete work through is the shape of failure this whole step exists to
	 * remove.
	 *
	 * @param MintedGroup $minted The group that was just created, and its id for messages.
	 * @param IOutput     $output Output sink.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	private function seedWithAdministrators(MintedGroup $minted, IOutput $output): void {
		$groupId = $minted->groupId;
		$added = $this->addAdministrators(minted: $minted, administrators: $this->administrators());

		if ($added === []) {
			$this->reportEmptyGroup(groupId: $groupId, output: $output);
			return;
		}

		$output->info(
			'Dossiq: seeded group "' . $groupId . '" with ' . count($added) . ' administrator(s): '
			. implode(', ', $added) . '. Replace them with the real members in Users & groups.'
		);
		$this->logger->info(
			'Dossiq: seeded assigned group with administrators',
			['group' => $groupId, 'users' => $added]
		);
	}//end seedWithAdministrators()

	/**
	 * The members of the admin group, or none when it does not exist.
	 *
	 * @return IUser[] The administrators.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	private function administrators(): array {
		$administrators = [];
		$adminGroup = $this->groupManager->get('admin');
		if ($adminGroup !== null) {
			$administrators = $adminGroup->getUsers();
		}

		return $administrators;
	}//end administrators()

	/**
	 * Add each administrator to the group.
	 *
	 * @param MintedGroup $minted The group that was just created, and its id for messages.
	 * @param IUser[]     $administrators The administrators.
	 *
	 * @return string[] The uids that were added.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	private function addAdministrators(MintedGroup $minted, array $administrators): array {
		$added = [];
		foreach ($administrators as $administrator) {
			if ($this->addAdministrator(minted: $minted, administrator: $administrator) === true) {
				$added[] = $administrator->getUID();
			}
		}

		return $added;
	}//end addAdministrators()

	/**
	 * Add one administrator to the group, warning when the backend refuses.
	 *
	 * @param MintedGroup $minted The group that was just created, and its id for messages.
	 * @param IUser       $administrator The administrator.
	 *
	 * @return boolean Whether the administrator was added.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	private function addAdministrator(MintedGroup $minted, IUser $administrator): bool {
		try {
			$minted->group->addUser($administrator);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: could not seed an administrator into the assigned group',
				['group' => $minted->groupId, 'user' => $administrator->getUID(), 'exception' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end addAdministrator()

	/**
	 * Warn that the group was created without members.
	 *
	 * @param string $groupId The group id.
	 * @param IOutput $output Output sink.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	private function reportEmptyGroup(string $groupId, IOutput $output): void {
		$output->warning(
			'Dossiq: group "' . $groupId . '" was created but has no members, so the shipped flow\'s '
			. 'task assigned to it cannot be completed by anyone. Add a member with: '
			. 'occ group:adduser ' . $groupId . ' <user>'
		);
		$this->logger->warning('Dossiq: assigned group created empty', ['group' => $groupId]);
	}//end reportEmptyGroup()
}//end class
