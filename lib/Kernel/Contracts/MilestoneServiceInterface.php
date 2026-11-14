<?php

/**
 * Contract of MilestoneService
 *
 * The public surface of MilestoneService that other components call, so they depend
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
 * Contract of MilestoneService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MilestoneServiceInterface {

	/**
	 * Get milestone progress for a specific case.
	 *
	 * @param string $caseId The case UUID
	 * @param string $caseTypeId The case type UUID
	 *
	 * @return array<string, mixed> Progress data with milestones, reached count, total, percentage
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getCaseProgress(string $caseId, string $caseTypeId): array;

	/**
	 * Progress for a case whose type the caller does not know.
	 *
	 * Resolves the case's own type, then delegates. This exists so a client does
	 * not have to pass a value the server can read for itself: requiring it made
	 * the caller's first render, before its record has loaded, send an empty
	 * path segment and 404.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed> Progress data, or an empty shape when the
	 *                              case has no resolvable type.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getCaseProgressForCase(string $caseId): array;

	/**
	 * Mark a milestone as reached for a case.
	 *
	 * @param string $caseId The case UUID
	 * @param string $definitionId The milestone definition UUID
	 * @param string $userId The user marking the milestone
	 * @param string $trigger How it was triggered (manual, workflow, auto)
	 *
	 * @return array<string, mixed> The created milestone record
	 *
	 * @throws \RuntimeException If OpenRegister unavailable
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function markMilestone(
		string $caseId,
		string $definitionId,
		string $userId,
		string $trigger = 'manual',
	): array;

	/**
	 * Reverse a milestone (with reason for audit trail).
	 *
	 * @param string $caseId The case UUID
	 * @param string $definitionId The milestone definition UUID
	 * @param string $userId The user reversing
	 * @param string $reason Reason for reversal
	 *
	 * @return bool True if reversed
	 *
	 * @throws \RuntimeException If OpenRegister unavailable
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function reverseMilestone(
		string $caseId,
		string $definitionId,
		string $userId,
		string $reason,
	): bool;
}//end interface
