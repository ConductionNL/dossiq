<?php

/**
 * Contract of ComplaintService
 *
 * The public surface of ComplaintService that other components call, so they depend
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
 * Contract of ComplaintService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ComplaintServiceInterface {

	/**
	 * Create a new complaint.
	 *
	 * @param array<string, mixed> $data Complaint data
	 *
	 * @return array<string, mixed> Created complaint
	 *
	 * @throws \RuntimeException If validation fails or OpenRegister unavailable
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-02
	 */
	public function createComplaint(array $data): array;

	/**
	 * Get a single complaint by ID.
	 *
	 * @param string $id Complaint UUID
	 *
	 * @return array<string, mixed>|null Complaint or null if not found
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-02
	 */
	public function getComplaint(string $id): ?array;

	/**
	 * Get complaints approaching or past their deadlines.
	 *
	 * The window is counted in CALENDAR days, which is what the body does. The
	 * docblock used to say working days and the code never did; this corrects
	 * the comment rather than the behaviour, because an advisory alert window
	 * is not a statutory term.
	 *
	 * @param int $warningDays Warn when the deadline is within this many
	 *                         calendar days
	 *
	 * @return array<string, array<int, array<string, mixed>>> Grouped overdue/warning complaints
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-02
	 */
	public function getDeadlineAlerts(int $warningDays = 3): array;

	/**
	 * Link a complaint to an escalated formal case.
	 *
	 * @param string $complaintId Complaint UUID
	 * @param string $caseId Case UUID
	 *
	 * @return array<string, mixed> Updated complaint
	 *
	 * @throws \RuntimeException If complaint not found
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-02
	 */
	public function linkEscalatedCase(string $complaintId, string $caseId): array;

	/**
	 * List complaints with optional filters.
	 *
	 * @param array<string, mixed> $filters Filter parameters
	 *
	 * @return array<int, array<string, mixed>> List of complaints
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-02
	 */
	public function listComplaints(array $filters = []): array;

	/**
	 * Request a verdaging (deadline extension) per Awb chapter 9.
	 *
	 * @param string $id Complaint UUID
	 * @param string $justification Written justification (required by Awb)
	 *
	 * @return array<string, mixed> Updated complaint
	 *
	 * @throws \RuntimeException If extension not available or invalid
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-02
	 */
	public function requestVerdaging(string $id, string $justification): array;

	/**
	 * Transition a complaint to a new status.
	 *
	 * @param string $id Complaint UUID
	 * @param string $newStatus Target status
	 *
	 * @return array<string, mixed> Updated complaint
	 *
	 * @throws \RuntimeException If transition not allowed
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-02
	 */
	public function transitionStatus(string $id, string $newStatus): array;

	/**
	 * Update a complaint.
	 *
	 * @param string $id Complaint UUID
	 * @param array<string, mixed> $data Updated data
	 *
	 * @return array<string, mixed> Updated complaint
	 *
	 * @throws \RuntimeException If OpenRegister unavailable
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-02
	 */
	public function updateComplaint(string $id, array $data): array;
}//end interface
