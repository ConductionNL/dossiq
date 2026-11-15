<?php

/**
 * Contract of PrimaryTeamService
 *
 * The public surface of PrimaryTeamService that other components call, so they depend
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
use Throwable;

/**
 * Contract of PrimaryTeamService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PrimaryTeamServiceInterface {

	/**
	 * Every team the user belongs to, the primary team first.
	 *
	 * @param IUser $user The user.
	 *
	 * @return array<int, array{groupId: string, name: string}> The teams; empty for a user in none.
	 *
	 * @throws Throwable When the organisation roles cannot be read.
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md#requirement-the-team-queue-is-the-unassigned-open-cases-of-the-readers-teams
	 */
	public function teamsOf(IUser $user): array;
}//end interface
