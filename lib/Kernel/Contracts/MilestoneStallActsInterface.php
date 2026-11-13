<?php

/**
 * Contract of MilestoneStallActs
 *
 * The public surface of MilestoneStallActs that other components call, so they depend
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
 * Contract of MilestoneStallActs.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MilestoneStallActsInterface {

	/**
	 * Read the case fresh.
	 *
	 * @param string $caseId The case id.
	 *
	 * @return array<string, mixed>|null The case, or null when it does not exist or nothing is configured.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function find(string $caseId): ?array;

	/**
	 * Tell the assignee, when the case still waits late on that milestone.
	 *
	 * @param array<string, mixed> $case       The case, read fresh.
	 * @param string               $identifier The milestone the timer was armed for; '' for any.
	 *
	 * @return bool True when a notification went out.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function notifyIfStalled(array $case, string $identifier = ''): bool;
}//end interface
