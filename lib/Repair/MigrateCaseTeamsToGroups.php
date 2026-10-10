<?php

/**
 * Move existing cases from an organisation role to the Nextcloud group it names.
 *
 * Since one-team-model `case.assignedGroup` is a Nextcloud group id. This runs
 * {@see CaseTeamMigration} on upgrade, after the register import (so the case
 * schema already accepts a group id) and before `BackfillCaseCustody` (so a
 * holding it opens records the group, not the role).
 *
 * A case it cannot map keeps its value. The step says how many there are and
 * which command lists them, and never fails the upgrade: an unmapped team is
 * an administrator's question, not a broken install.
 *
 * POST-MIGRATION only, like `BackfillCaseCustody`: a fresh install has no
 * cases holding a role.
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
 * @spec openspec/specs/role-routing-via-or-rbac/spec.md#requirement-existing-cases-move-to-the-group-their-role-names-req-team-03
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Service\Team\CaseTeamMigration;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs the team migration on upgrade and reports what it could not map.
 *
 * @spec openspec/specs/role-routing-via-or-rbac/spec.md#requirement-existing-cases-move-to-the-group-their-role-names-req-team-03
 */
class MigrateCaseTeamsToGroups implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param CaseTeamMigration $migration The one service that moves a case's team.
	 * @param LoggerInterface   $logger    Logger.
	 *
	 * @spec openspec/specs/role-routing-via-or-rbac/spec.md#requirement-existing-cases-move-to-the-group-their-role-names-req-team-03
	 */
	public function __construct(
		private readonly CaseTeamMigration $migration,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The name of this repair step.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/specs/role-routing-via-or-rbac/spec.md#requirement-existing-cases-move-to-the-group-their-role-names-req-team-03
	 */
	public function getName(): string {
		return 'Move the team of existing cases from an organisation role to its Nextcloud group';
	}//end getName()

	/**
	 * Run the migration.
	 *
	 * @param IOutput $output Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/role-routing-via-or-rbac/spec.md#requirement-existing-cases-move-to-the-group-their-role-names-req-team-03
	 */
	public function run(IOutput $output): void {
		try {
			$report = $this->migration->run(apply: true);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq team migration did not run; run occ dossiq:teams:migrate to retry',
				['exception' => $e->getMessage()],
			);
			$output->warning('Team migration did not run: ' . $e->getMessage());
			return;
		}

		if ($report['ran'] === false) {
			$output->info('Team migration skipped: ' . $report['skippedBecause'] . '.');
			return;
		}

		$unmapped = count($report['unmapped']);
		$output->info(
			'Team migration: ' . $report['converted'] . ' cases moved to their group, '
			. $report['alreadyGroup'] . ' already held a group, '
			. $unmapped . ' kept their value, ' . $report['failed'] . ' failed.'
		);

		if ($unmapped > 0) {
			$message = $unmapped . ' cases still name an organisation role as their team, because the role names no '
				. 'existing Nextcloud group. Set ncGroupId on the role, then run occ dossiq:teams:migrate to list and move them.';
			$output->warning($message);
			$this->logger->warning('Dossiq team migration: ' . $message);
		}
	}//end run()
}//end class
