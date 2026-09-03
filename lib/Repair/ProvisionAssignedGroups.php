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

use OCP\IGroup;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Creates the Nextcloud groups the shipped register data assigns work to.
 *
 * @spec openspec/specs/case-management/spec.md
 */
class ProvisionAssignedGroups implements IRepairStep {

	/**
	 * Every group the shipped register data assigns steps to.
	 *
	 * This list is the provisioning counterpart of the literals in
	 * lib/Settings/dossiq_register.json; ProvisionAssignedGroupsTest sweeps
	 * the shipped flows and fails when a group is assigned there that this
	 * list does not provision, so the two cannot drift apart silently.
	 *
	 * @var array<int, string>
	 */
	public const ASSIGNED_GROUPS = ['behandelaars'];

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

			$this->seedWithAdministrators(group: $group, groupId: $groupId, output: $output);
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
	 * @param IGroup $group The group that was just created.
	 * @param string $groupId The group id, for messages.
	 * @param IOutput $output Output sink.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	private function seedWithAdministrators(IGroup $group, string $groupId, IOutput $output): void {
		$administrators = [];
		$adminGroup = $this->groupManager->get('admin');
		if ($adminGroup !== null) {
			$administrators = $adminGroup->getUsers();
		}

		$added = [];
		foreach ($administrators as $administrator) {
			try {
				$group->addUser($administrator);
			} catch (Throwable $e) {
				$this->logger->warning(
					'Dossiq: could not seed an administrator into the assigned group',
					['group' => $groupId, 'user' => $administrator->getUID(), 'exception' => $e->getMessage()]
				);
				continue;
			}

			$added[] = $administrator->getUID();
		}

		if ($added === []) {
			$output->warning(
				'Dossiq: group "' . $groupId . '" was created but has no members, so the shipped flow\'s '
				. 'task assigned to it cannot be completed by anyone. Add a member with: '
				. 'occ group:adduser ' . $groupId . ' <user>'
			);
			$this->logger->warning('Dossiq: assigned group created empty', ['group' => $groupId]);
			return;
		}

		$output->info(
			'Dossiq: seeded group "' . $groupId . '" with ' . count($added) . ' administrator(s): '
			. implode(', ', $added) . '. Replace them with the real behandelaars in Users & groups.'
		);
		$this->logger->info(
			'Dossiq: seeded assigned group with administrators',
			['group' => $groupId, 'users' => $added]
		);
	}//end seedWithAdministrators()
}//end class
