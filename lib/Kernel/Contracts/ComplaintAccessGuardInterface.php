<?php

/**
 * Contract of ComplaintAccessGuard
 *
 * The public surface of ComplaintAccessGuard that other components call, so they depend
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

use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;

/**
 * Contract of ComplaintAccessGuard.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ComplaintAccessGuardInterface {

	/**
	 * Authorize access to a complaint for the given user.
	 *
	 * Read access is granted to the behandelaar or any authenticated user
	 * (coordinators see all); write access is narrower (authorizeMutation).
	 *
	 * @param array<string, mixed> $complaint Complaint data
	 * @param string $userId NC user ID
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-06
	 */
	public function authorizeAccess(array $complaint, string $userId): void;

	/**
	 * Authorize mutation of a complaint for the given user.
	 *
	 * Only the assigned behandelaar or an admin/coordinator may mutate.
	 *
	 * @param array<string, mixed> $complaint Complaint data
	 * @param string $userId NC user ID
	 *
	 * @return void
	 *
	 * @throws OCSForbiddenException If not authorized
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-06
	 */
	public function authorizeMutation(array $complaint, string $userId): void;

	/**
	 * The signed-in user's UID, or an empty string when unauthenticated.
	 *
	 * @return string The current UID, empty when there is no session.
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-06
	 */
	public function currentUid(): string;

	/**
	 * The shared 401 response for an unauthenticated caller.
	 *
	 * @return JSONResponse The 401 response.
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-06
	 */
	public function notAuthenticated(): JSONResponse;

	/**
	 * Parse JSON request body.
	 *
	 * @return array<string, mixed> Decoded request body
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-06
	 */
	public function parseBody(): array;

	/**
	 * Require the current user to be a coordinator (admin).
	 *
	 * @param string $userId NC user ID
	 *
	 * @return void
	 *
	 * @throws OCSForbiddenException If not a coordinator
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-06
	 */
	public function requireCoordinator(string $userId): void;
}//end interface
