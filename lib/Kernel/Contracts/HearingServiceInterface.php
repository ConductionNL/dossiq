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
interface HearingServiceInterface {

	/**
	 * Resolve the bezwaar case a hearing session belongs to.
	 *
	 * `recordAttendance()` carries only a session id, so there is nothing in
	 * its signature to authorise against. This resolves the parent case so the
	 * controller can apply the ordinary per-case guard. Returns null — which
	 * the caller treats as DENY — whenever the session cannot be resolved, so
	 * an unknown id is not an existence oracle.
	 *
	 * @param string $sessionId UUID of the hearingSession
	 *
	 * @return string|null The parent case UUID, or null when unresolvable.
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function getCaseIdForSession(string $sessionId): ?string;

	/**
	 * Record attendance on a hearingSession (REQ-BH-5).
	 *
	 * Within the one-hour grace window after the hearing concludes
	 * attendance entries SHALL be appendable freely; after the window
	 * any correction SHALL carry a non-empty correctionReason that is
	 * logged as an awb-art-7:7 audit entry.
	 *
	 * @param string $sessionId UUID of the hearingSession
	 * @param array<int, array<string, mixed>> $entries Attendance entries: each {invitee, present, arrivalTime?, correctionReason?}
	 *
	 * @return array<string, mixed> The updated hearingSession record
	 *
	 * @throws RuntimeException When the session is not found, persistence fails, or a late correction lacks a reason.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function recordAttendance(
		string $sessionId,
		array $entries,
	): array;

	/**
	 * Listener entry-point: seed a default hearing session for a bezwaar
	 * that has just transitioned to "Hearing planned" (REQ-BH-2
	 * scheduling). The seed is intentionally minimal — invitees are
	 * empty, scheduledDate is fourteen days out — and only fires when
	 * no hearing session already exists for the case.
	 *
	 * @param string $objectionId The bezwaar (lifecycle) UUID
	 *
	 * @return array<string, mixed>|null Created hearing session, or null when one already exists / infra unavailable.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function seedDefaultHearing(string $objectionId): ?array;
}//end interface
