<?php

/**
 * Dossiq Provision Assigned Groups Repair Step.
 *
 * The shipped case flow assigns its behandelaar step to the Nextcloud group
 * `behandelaars` (dossiq_register.json, node `task-behandelaar`), but nothing
 * ever created that group. On a fresh install the completion signal is then
 * refused fail-closed ("the user who completed the task is not the assignee
 * of the awaiting step"): the assignee gate resolves group membership, and
 * membership of a group that does not exist is false for everyone. Creating
 * the group is proven sufficient to unblock the shipped journey.
 *
 * Idempotent: an existing group is left exactly as it is (membership is the
 * administrator's, never this step's). The step deliberately does NOT assign
 * the flow to a different actor: reassigning shipped work to `admin` would
 * hide a provisioning gap behind an over-privileged default.
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

use OCP\IGroupManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

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
		}
	}//end run()
}//end class
