<?php

/**
 * Contract of AutomaticActionFlowMigrator
 *
 * The public surface of AutomaticActionFlowMigrator that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

use OCP\IUser;

/**
 * Contract of AutomaticActionFlowMigrator.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AutomaticActionFlowMigratorInterface {

	/**
	 * Migrate every automaticAction to a flow, acting as the given user.
	 *
	 * @param IUser $user The user the created flows belong to.
	 * @param bool $dryRun Report what would happen without writing.
	 *
	 * @return array<string, mixed> The summary: total, created, updated, skipped, failed, rows.
	 *
	 * @spec openspec/changes/page-topology-cleanup/tasks.md
	 */
	public function migrate(IUser $user, bool $dryRun): array;
}//end interface
