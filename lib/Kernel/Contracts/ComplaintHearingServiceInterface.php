<?php

/**
 * Contract of HearingService
 *
 * The public surface of HearingService that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of HearingService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ComplaintHearingServiceInterface {

	/**
	 * List hearings for a complaint.
	 *
	 * @param string $complaintId Complaint UUID
	 *
	 * @return array<int, array<string, mixed>> List of hearings
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-03
	 */
	public function getHearingsForComplaint(string $complaintId): array;

	/**
	 * Schedule a new hearing for a complaint.
	 *
	 * @param string $complaintId Complaint UUID
	 * @param array<string, mixed> $data Hearing data (datum, locatie, type, deelnemers)
	 *
	 * @return array<string, mixed> Created hearing
	 *
	 * @throws \RuntimeException If required fields missing or OpenRegister unavailable
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-03
	 */
	public function scheduleHearing(string $complaintId, array $data): array;

	/**
	 * Record the outcome of a completed hearing.
	 *
	 * @param string $id Hearing UUID
	 * @param array<string, mixed> $outcome Outcome data (verslag, conclusie, aanwezigen, datumAfgerond)
	 *
	 * @return array<string, mixed> Updated hearing
	 *
	 * @throws \RuntimeException If verslag is missing or OpenRegister unavailable
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-03
	 */
	public function recordOutcome(string $id, array $outcome): array;
}//end interface
