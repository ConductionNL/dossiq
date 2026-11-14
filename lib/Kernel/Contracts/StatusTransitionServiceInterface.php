<?php

/**
 * Contract of StatusTransitionService
 *
 * The public surface of StatusTransitionService that other components call, so they depend
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
 * Contract of StatusTransitionService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface StatusTransitionServiceInterface {

	/**
	 * Execute a guarded transition.
	 *
	 * @param string $caseId Case UUID
	 * @param string $transitionId Transition id from the workflow the case runs on
	 * @param string|null $comment Optional free-form comment
	 * @param string|null $userId Optional explicit user UID; defaults to IUserSession
	 * @param string|null $resultTypeId ResultType chosen for a closing transition
	 *
	 * @return array{
	 *     status: string,
	 *     statusRecord: array<string, mixed>,
	 *     dispatchedActions: array<int, array<string, mixed>>,
	 *     failedActions: array<int, array{type: string, error: string}>,
	 *     version: int,
	 * }
	 *         `status` is `ok`, or `partial` when the case moved and an action it
	 *         should have brought did not run. See `actionOutcome()`.
	 *
	 * @throws RuntimeException A GuardFailedException when server-side re-evaluation fails
	 *                          any guard; a RefusedException when the from-status or group
	 *                          gate rejects; otherwise when case/transition/template are not
	 *                          found, or a closing transition arrives without a result type
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function execute(
		string $caseId,
		string $transitionId,
		?string $comment,
		?string $userId = null,
		?string $resultTypeId = null,
	): array;

	/**
	 * Execute an admin-only free-form transition for caseTypes without an active workflow template.
	 *
	 * @param string $caseId Case UUID
	 * @param string $toStatusId Target statusType UUID
	 * @param string|null $comment Optional free-form comment
	 * @param string|null $userId Optional explicit user UID; defaults to IUserSession
	 *
	 * @return array{
	 *     status: string,
	 *     statusRecord: array<string, mixed>,
	 *     dispatchedActions: array<int, array<string, mixed>>,
	 *     failedActions: array<int, array{type: string, error: string}>,
	 * }
	 *
	 * @throws RuntimeException When the caller is not in the admin group or the target is invalid
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function executeFreeForm(string $caseId, string $toStatusId, ?string $comment, ?string $userId = null): array;

	/**
	 * Compute the set of transitions available to a user on a case.
	 *
	 * @param string $caseId Case UUID
	 * @param string|null $userId Optional explicit user UID; defaults to IUserSession
	 *
	 * @return array{transitions: array<int, array<string, mixed>>, current: array<string, mixed>}
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function getAvailableTransitions(string $caseId, ?string $userId = null): array;

	/**
	 * Check if the current (or given) user is in the dossiq admin group.
	 *
	 * @param string $userId UID
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function isAdmin(string $userId): bool;

	/**
	 * Return the chronological history of transitions for a case.
	 *
	 * @param string $caseId Case UUID
	 *
	 * @return array{history: array<int, array<string, mixed>>, replayable: bool}
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function replay(string $caseId): array;
}//end interface
