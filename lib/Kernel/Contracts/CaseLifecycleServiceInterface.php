<?php

/**
 * Contract of CaseLifecycleService
 *
 * The public surface of CaseLifecycleService that other components call, so they depend
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
 * Contract of CaseLifecycleService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseLifecycleServiceInterface {

	/**
	 * Extend the processing term (verlenging, Awb 4:14).
	 *
	 * The new end date is the current one plus the case type's
	 * `extensionPeriod`, which the case type states as an ISO 8601 duration —
	 * unless the caller names an explicit `$newEndDate`, which the bulk
	 * Extend term action does. An explicit date still has to be LATER than
	 * the current end: an "extension" that shortens the term is a mistake,
	 * and refusing it per case is cheaper than explaining it afterwards.
	 * `extensionAllowed` and the reason are required either way, so naming a
	 * date buys the caller no authority the case type has not granted.
	 *
	 * @param string $caseId Case UUID
	 * @param string $reason Why the term is extended
	 * @param string $newEndDate Explicit new end date (Y-m-d); the case type's
	 *                           period is used when this is empty
	 *
	 * @return array<string, mixed> The lifecycle state after the write
	 *
	 * @throws RuntimeException When the case type forbids extension, states no
	 *                          period, the reason is empty, or the named date is
	 *                          unreadable or not after the current end date
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 * @spec openspec/specs/case-bulk-status-transition/spec.md
	 */
	public function extend(string $caseId, string $reason, string $newEndDate = ''): array;

	/**
	 * Reopen a closed case.
	 *
	 * The case goes back to its type's initial status and an audit row is
	 * written, so the reopening reads in the history like any other move.
	 * Whether the caller MAY reopen is the controller's question; this method
	 * refuses only what the case itself forbids.
	 *
	 * @param string $caseId Case UUID
	 * @param string $reason Why the case is reopened
	 *
	 * @return array<string, mixed> The lifecycle state after the write
	 *
	 * @throws RuntimeException When the case is not closed, the case type has no
	 *                          initial status, or the reason is empty
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function reopen(string $caseId, string $reason): array;

	/**
	 * Resume a suspended case (hervatting).
	 *
	 * @param string $caseId Case UUID
	 * @param string $reason Why the case resumes
	 *
	 * @return array<string, mixed> The lifecycle state after the write
	 *
	 * @throws RuntimeException When the case is not suspended or the reason is empty
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function resume(string $caseId, string $reason): array;

	/**
	 * What the case allows right now.
	 *
	 * The case page reads this to decide which lifecycle affordances are
	 * honest to offer, and to show the suspended marker.
	 *
	 * @param string $caseId Case UUID
	 *
	 * @return array{suspended: bool, canSuspend: bool, canResume: bool,
	 *               canExtend: bool, canReopen: bool, isFinalStatus: bool,
	 *               extensionCount: int, maxExtensions: int|null, deadline: string,
	 *               statusName: string, caseType: string}
	 *
	 * @throws RuntimeException When the case cannot be read
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 * @spec openspec/specs/template-library/spec.md
	 */
	public function state(string $caseId): array;

	/**
	 * Suspend the case (opschorting, Awb 4:5).
	 *
	 * A pause reason the case type declares may stand in for the free text:
	 * its key is stored on the pause, and its name is the rationale when no
	 * text was given. A key the case type does not declare is refused.
	 *
	 * @param string $caseId Case UUID
	 * @param string $reason Why the case is suspended, in the handler's words
	 * @param int $days How long the applicant is given, in days
	 * @param string $pauseReason The key of one of the case type's pause reasons, or ''
	 *
	 * @return array<string, mixed> The lifecycle state after the write
	 *
	 * @throws RuntimeException When the case type forbids suspension, the case
	 *                          is already suspended, the reason is empty, or the
	 *                          pause reason is not one the case type declares
	 *
	 * @spec openspec/specs/termijn-pause-extension/spec.md
	 */
	public function suspend(string $caseId, string $reason, int $days, string $pauseReason = ''): array;
}//end interface
