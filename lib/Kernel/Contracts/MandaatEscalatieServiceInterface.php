<?php

/**
 * Contract of MandaatEscalatieService
 *
 * The public surface of MandaatEscalatieService that other components call, so they depend
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
 * Contract of MandaatEscalatieService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MandaatEscalatieServiceInterface {

	/**
	 * Approve an open escalation.
	 *
	 * @param string $escalationId Escalation id.
	 * @param string $mandaathouderUserId Approving user id (must match targetUserId).
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException When unauthorized or escalation missing.
	 *
	 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-03-escalation-engine/tasks.md
	 */
	public function approveEscalatie(string $escalationId, string $mandaathouderUserId): array;

	/**
	 * Create a new escalation.
	 *
	 * @param string $caseId Case id.
	 * @param string $decisionType Decision type.
	 * @param string $initiatorId Initiating user id.
	 * @param string $escalationReason Escalation reason.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-03-escalation-engine/tasks.md
	 */
	public function createEscalatie(string $caseId, string $decisionType, string $initiatorId, string $escalationReason): array;

	/**
	 * Reject an open escalation.
	 *
	 * @param string $escalationId Escalation id.
	 * @param string $reason Rejection reason.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException When the escalation is missing.
	 *
	 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-03-escalation-engine/tasks.md
	 */
	public function rejectEscalatie(string $escalationId, string $reason): array;
}//end interface
