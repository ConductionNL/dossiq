<?php

/**
 * Contract of ConsultationService
 *
 * The public surface of ConsultationService that other components call, so they depend
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
 * Contract of ConsultationService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ConsultationServiceInterface {

	/**
	 * Approve a deadline extension and update the deadline.
	 *
	 * @param string $consultationId The consultation UUID
	 * @param string $newDeadline The new deadline date (Y-m-d format)
	 *
	 * @return array<string, mixed> Updated consultation summary
	 *
	 * @throws \RuntimeException If OpenRegister is unavailable or date format invalid
	 *
	 * @spec openspec/changes/archive/2026-06-13-consultation-management/tasks.md#TASK-CN-02
	 */
	public function approveExtension(string $consultationId, string $newDeadline): array;

	/**
	 * Create a consultation linked to a parent case.
	 *
	 * Generates a unique consultation number in the format ADV-{year}-{seq}.
	 *
	 * @param array<string, mixed> $data Consultation data
	 *
	 * @return array<string, mixed> Created consultation with ID and number
	 *
	 * @throws \RuntimeException If OpenRegister unavailable, required fields missing, or decidesk fails closed
	 *
	 * @spec openspec/changes/archive/2026-06-13-consultation-management/tasks.md#TASK-CN-02
	 * @spec openspec/specs/remaining-decision-delegation/spec.md
	 * @spec openspec/specs/remaining-decision-delegation/spec.md#requirement-req-pdrd-002-delegation-fails-closed-when-decidesk-is-unavailable
	 */
	public function createConsultation(array $data): array;

	/**
	 * Delete a consultation by ID.
	 *
	 * @param string $consultationId The consultation UUID
	 *
	 * @return bool True on success
	 *
	 * @throws \RuntimeException If OpenRegister is unavailable
	 *
	 * @spec openspec/changes/archive/2026-06-13-consultation-management/tasks.md#TASK-CN-02
	 */
	public function deleteConsultation(string $consultationId): bool;

	/**
	 * Get all consultations for a case.
	 *
	 * @param string $caseId The parent case UUID
	 *
	 * @return array<int, array<string, mixed>> List of consultations
	 *
	 * @spec openspec/changes/archive/2026-06-13-consultation-management/tasks.md#TASK-CN-02
	 */
	public function getConsultationsForCase(string $caseId): array;

	/**
	 * Get overdue consultations (past deadline with open/in_behandeling status).
	 *
	 * @param callable|null $mayRead Keeps a request the caller may see; asked of each before the list is built.
	 *
	 * @return array<int, array<string, mixed>> List of overdue consultations
	 *
	 * @spec openspec/changes/archive/2026-06-13-consultation-management/tasks.md#TASK-CN-02
	 */
	public function getOverdueConsultations(?callable $mayRead = null): array;

	/**
	 * Request a deadline extension for a consultation.
	 *
	 * Records the extension request timestamp and justification.
	 *
	 * @param string $consultationId The consultation UUID
	 * @param string $justification The justification for the extension request
	 *
	 * @return array<string, mixed> Updated consultation summary
	 *
	 * @throws \RuntimeException If OpenRegister is unavailable
	 *
	 * @spec openspec/changes/archive/2026-06-13-consultation-management/tasks.md#TASK-CN-02
	 */
	public function requestExtension(string $consultationId, string $justification): array;

	/**
	 * Submit advice response to a consultation.
	 *
	 * @param string $consultationId The consultation UUID
	 * @param array<string, mixed> $response Response data (advies, toelichting, voorwaarden)
	 *
	 * @return array<string, mixed> Updated consultation summary
	 *
	 * @throws \RuntimeException If invalid response type or OpenRegister unavailable
	 *
	 * @spec openspec/changes/archive/2026-06-13-consultation-management/tasks.md#TASK-CN-02
	 */
	public function submitResponse(string $consultationId, array $response): array;

	/**
	 * Update consultation status with transition validation.
	 *
	 * @param string $consultationId The consultation UUID
	 * @param string $newStatus The new status
	 *
	 * @return array<string, mixed> Updated consultation summary
	 *
	 * @throws \RuntimeException If invalid status, invalid transition, or OpenRegister unavailable
	 *
	 * @spec openspec/changes/archive/2026-06-13-consultation-management/tasks.md#TASK-CN-02
	 */
	public function updateStatus(string $consultationId, string $newStatus): array;
}//end interface
