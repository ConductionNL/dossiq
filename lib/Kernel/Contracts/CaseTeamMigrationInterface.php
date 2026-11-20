<?php

/**
 * Contract of CaseTeamMigration
 *
 * The public surface of CaseTeamMigration that other components call, so they depend
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

/**
 * Contract of CaseTeamMigration.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseTeamMigrationInterface {

	/**
	 * Walk every case and convert what can be converted.
	 *
	 * @param bool $apply False reports what would happen and writes nothing.
	 *
	 * @return array{
	 *     ran: bool,
	 *     skippedBecause: string,
	 *     converted: int,
	 *     alreadyGroup: int,
	 *     failed: int,
	 *     unmapped: list<array{case: string, title: string, value: string, reason: string, roleName: string, hints: list<string>}>
	 * } The report. `ran` is false when OpenRegister or the case schema is not
	 *   configured, and `skippedBecause` says which.
	 *
	 * @spec openspec/specs/role-routing-via-or-rbac/spec.md#requirement-existing-cases-move-to-the-group-their-role-names-req-team-03
	 */
	public function run(bool $apply): array;
}//end interface
