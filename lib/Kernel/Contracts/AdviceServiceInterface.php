<?php

/**
 * Contract of AdviceService
 *
 * The public surface of AdviceService that other components call, so they depend
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
 * Contract of AdviceService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AdviceServiceInterface {

	/**
	 * Dispatch a reminder notification to the adviseur WITHOUT an
	 * authorization check.
	 *
	 * TRUST BOUNDARY: this is the system seam (`AdviceTimerFiredListener`, on the engine timer's fire), which
	 * runs with no user session. It must only be called from
	 * {@see self::dispatchReminderAsUser()} (which authorizes first) or from a
	 * code-driven background job. Never call it with user-supplied intent that
	 * has not been through `assertReminderAuthorized()`.
	 *
	 * @param string $adviceId The advice UUID
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function dispatchReminder(string $adviceId): void;

	/**
	 * Dispatch a reminder on behalf of the authenticated caller.
	 *
	 * The HTTP seam. `POST /api/advice/{id}/remind` used to call
	 * {@see self::dispatchReminder()} directly, which has no authorization —
	 * so any authenticated user could make any adviseur receive a reminder for
	 * any advice request, and the endpoint doubled as an existence oracle for
	 * advice UUIDs. This mirrors the split that already exists in this class
	 * between `transitionStatus()` (authorizes, then delegates) and
	 * `applyTransition()` (the system/cron seam).
	 *
	 * @param string $adviceId The advice UUID
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the advice is not accessible to the caller.
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function dispatchReminderAsUser(string $adviceId): void;

	/**
	 * Mark an advice request as expired (status -> verlopen).
	 *
	 * SYSTEM PATH — called by AdviceTimerFiredListener and AdviceTimerListener, which may run with NO user
	 * session. It therefore goes straight to applyTransition() and deliberately
	 * bypasses assertAdviceTransitionAuthorized(): that guard requires a session
	 * and would reject the cron with 'Not authenticated', silently breaking
	 * advice expiry. `verlopen` is unreachable over HTTP for the same reason —
	 * the guard denies it for every caller, so expiry stays a system-owned
	 * transition.
	 *
	 * The advice id originates from getOpenAdvice() (code-driven), never from
	 * user-supplied request data.
	 *
	 * @param string $adviceId The advice UUID
	 *
	 * @return array<string, mixed> Updated advice record
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function expireAdvice(string $adviceId): array;

	/**
	 * Get all advice requests linked to a case.
	 *
	 * Used by the workflow guard and by the case-detail "Adviezen" tab.
	 *
	 * @param string $caseId The case UUID
	 *
	 * @return array<int, array<string, mixed>> Advice records for the case
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getAdviceForCase(string $caseId): array;

	/**
	 * Load all open advice requests across the system (for the deadline job).
	 *
	 * @return array<int, array<string, mixed>> Open advice records
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getOpenAdvice(): array;

	/**
	 * Create an advice request for a VTH case.
	 *
	 * Stores the adviceRequest in the `adviceRequest` schema and sends a
	 * notification to the adviseur. Corresponds to tasks.md#task-6.
	 *
	 * @param string $caseId UUID of the case
	 * @param array<string, mixed> $data Advice request data (adviseur, deadline, vraag, etc.)
	 * @param string $requestedBy User UID of the requester
	 *
	 * @return array<string, mixed> Saved adviceRequest object
	 *
	 * @throws RuntimeException If OpenRegister is unavailable or decidesk fails closed
	 * @throws \InvalidArgumentException If the deadline is not a date this system can read
	 *
	 * @spec openspec/changes/archive/2026-06-13-vth-module/tasks.md#task-6
	 * @spec openspec/specs/remaining-decision-delegation/spec.md
	 * @spec openspec/specs/remaining-decision-delegation/spec.md#requirement-req-pdrd-002-delegation-fails-closed-when-decidesk-is-unavailable
	 */
	public function requestAdvice(string $caseId, array $data, string $requestedBy): array;

	/**
	 * Transition an advice request to a new status and fire notifications.
	 *
	 * Supported transitions:
	 *   - to=aangevraagd: notify the adviseur (used right after manifest create).
	 *   - to=ontvangen:   set receivedAt + optional adviesDocument; notify caller.
	 *   - to=verlopen:    mark expired (cron path).
	 *
	 * @param string $adviceId The advice UUID
	 * @param string $to Target status
	 * @param array<string, mixed> $payload Extra fields (adviesDocument, etc.)
	 *
	 * @return array<string, mixed> Updated advice record
	 *
	 * @throws \RuntimeException When OpenRegister unavailable / invalid status
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function transitionStatus(string $adviceId, string $to, array $payload = []): array;
}//end interface
