<?php

/**
 * Contract of LhsMatrixDecisionTableMigrator
 *
 * The public surface of LhsMatrixDecisionTableMigrator that other components call, so they depend
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
 * Contract of LhsMatrixDecisionTableMigrator.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LhsMatrixDecisionTableMigratorInterface {

	/**
	 * Project every stored matrix onto a decision table.
	 *
	 * @param IUser   $user   The owner the tables are written as.
	 * @param boolean $dryRun Report without writing.
	 *
	 * @return array<string, mixed> The summary.
	 *
	 * @spec openspec/specs/lhs-decision-table/spec.md
	 */
	public function migrate(IUser $user, bool $dryRun): array;
}//end interface
