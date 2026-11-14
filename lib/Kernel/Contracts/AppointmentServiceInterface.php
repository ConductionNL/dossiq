<?php

/**
 * Contract of AppointmentService
 *
 * The public surface of AppointmentService that other components call, so they depend
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
 * Contract of AppointmentService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AppointmentServiceInterface {

	/**
	 * Book an appointment linked to a case.
	 *
	 * @param string $caseId The case UUID.
	 * @param array<string, mixed> $data Appointment data (product, location, dateTime, citizen info).
	 *
	 * @return array<string, mixed> The stored appointment record or an error payload.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function bookAppointment(string $caseId, array $data): array;

	/**
	 * Cancel an appointment.
	 *
	 * @param string $appointmentId The appointment UUID.
	 *
	 * @return array<string, mixed> The updated appointment record.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function cancelAppointment(string $appointmentId): array;

	/**
	 * Validate cancel token and return appointment.
	 *
	 * @param string $token The appointment public token.
	 *
	 * @return array<string, mixed>|null The appointment data, or null if not found.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getAppointmentByToken(string $token): ?array;

	/**
	 * Get appointments for a case.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<int, mixed> List of appointments for the case.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getAppointmentsForCase(string $caseId): array;

	/**
	 * Resolve the case an appointment belongs to.
	 *
	 * `cancel()` and `noShow()` carry only an appointment id, so there is
	 * nothing in their signature to authorise against. This resolves the owning
	 * case so the controller can apply the same per-case guard as the rest of
	 * the file. An unresolvable appointment returns null, which the caller
	 * treats as DENY — so an unknown id is not an existence oracle.
	 *
	 * @param string $appointmentId The appointment UUID.
	 *
	 * @return string|null The owning case UUID, or null when unresolvable.
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function getCaseIdForAppointment(string $appointmentId): ?string;

	/**
	 * Get available timeslots via the configured backend.
	 *
	 * @param string $productId The product identifier.
	 * @param string $locationId The location identifier.
	 * @param string $date The date (YYYY-MM-DD).
	 *
	 * @return array<int, array<string, mixed>> List of available timeslots.
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function getTimeslots(string $productId, string $locationId, string $date): array;

	/**
	 * Mark an appointment as no-show.
	 *
	 * @param string $appointmentId The appointment UUID.
	 *
	 * @return array<string, mixed> The updated appointment record.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function markNoShow(string $appointmentId): array;
}//end interface
