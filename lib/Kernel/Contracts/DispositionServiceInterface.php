<?php

/**
 * Contract of DispositionService
 *
 * The public surface of DispositionService that other components call, so they depend
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
 * Contract of DispositionService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DispositionServiceInterface {

	/**
	 * Approve a disposition that was awaiting coordinator approval.
	 *
	 * @param string $dispositionId Disposition UUID
	 * @param string $approverId NC user ID of the approving coordinator
	 *
	 * @return array<string, mixed> Updated disposition
	 *
	 * @throws \RuntimeException If disposition not found or not awaiting approval
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-04
	 */
	public function approveDisposition(string $dispositionId, string $approverId): array;

	/**
	 * Generate a response letter for the complaint via Docudesk template rendering.
	 *
	 * @param string $complaintId Complaint UUID
	 * @param string $dispositionId Disposition UUID
	 *
	 * @return array<string, mixed> Letter generation result (document reference)
	 *
	 * @throws \RuntimeException If Docudesk is not available
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-04
	 */
	public function generateResponseLetter(string $complaintId, string $dispositionId): array;

	/**
	 * Get the disposition for a complaint.
	 *
	 * @param string $complaintId Complaint UUID
	 *
	 * @return array<string, mixed>|null Disposition or null if not yet submitted
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-04
	 */
	public function getDispositionForComplaint(string $complaintId): ?array;

	/**
	 * Submit a disposition for a complaint as final — no coordinator approval.
	 *
	 * @param string $complaintId Complaint UUID
	 * @param array<string, mixed> $data Disposition data
	 *
	 * @return array<string, mixed> Created disposition
	 *
	 * @throws \RuntimeException If validation fails or OpenRegister unavailable
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-04
	 */
	public function submitDisposition(string $complaintId, array $data): array;

	/**
	 * Submit a disposition that must first be approved by a coordinator.
	 *
	 * The created disposition carries goedkeuringStatus 'awaiting_approval'
	 * until {@see self::approveDisposition()} clears it.
	 *
	 * @param string $complaintId Complaint UUID
	 * @param array<string, mixed> $data Disposition data
	 *
	 * @return array<string, mixed> Created disposition
	 *
	 * @throws \RuntimeException If validation fails or OpenRegister unavailable
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-04
	 */
	public function submitDispositionForApproval(string $complaintId, array $data): array;
}//end interface
