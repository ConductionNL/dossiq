<?php

/**
 * Procest ComplaintAccessGuard.
 *
 * Authorization and request-decoding collaborator for the complaint
 * (klacht) surface. Split out of ComplaintController when that controller was
 * divided along its sub-domains: the rules for who may read a complaint, who
 * may mutate one, and who counts as a coordinator are shared by all five
 * complaint controllers, so they live here once rather than being duplicated
 * per controller (ADR-022).
 *
 * The mutation and coordinator guards throw OCSForbiddenException, which the
 * Nextcloud middleware renders as a 403 — the same failure mode the endpoints
 * had before the split.
 *
 * @category Service
 * @package  OCA\Procest\Service\Complaint
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Complaint;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Shared authorization guard for the complaint controllers.
 *
 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
 */
class ComplaintAccessGuard {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request Request, for body decoding.
	 * @param IUserSession $userSession User session.
	 * @param IGroupManager $groupManager Group manager (admin checks).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * The signed-in user's UID, or an empty string when unauthenticated.
	 *
	 * @return string The current UID, empty when there is no session.
	 *
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
	 */
	public function currentUid(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return '';
		}

		return $user->getUID();
	}//end currentUid()

	/**
	 * The shared 401 response for an unauthenticated caller.
	 *
	 * @return JSONResponse The 401 response.
	 *
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
	 */
	public function notAuthenticated(): JSONResponse {
		return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
	}//end notAuthenticated()

	/**
	 * Parse JSON request body.
	 *
	 * @return array<string, mixed> Decoded request body
	 *
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
	 */
	public function parseBody(): array {
		$params = $this->request->getParams();
		if (empty($params) === false) {
			return $params;
		}

		return [];
	}//end parseBody()

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
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
	 */
	public function authorizeAccess(array $complaint, string $userId): void {
		// Read access is broadly allowed for authenticated users: the behandelaar
		// and coordinators/admins can always read. The complaint and userId are
		// retained in the signature so this guard can be tightened later without
		// touching every call site.
		unset($complaint, $userId);
	}//end authorizeAccess()

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
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
	 */
	public function authorizeMutation(array $complaint, string $userId): void {
		$handler = $complaint['handler'] ?? null;

		// The behandelaar or any admin may mutate.
		if ($handler !== null && $handler === $userId) {
			return;
		}

		// Admins can always mutate.
		$isAdmin = $this->groupManager->isAdmin($userId);
		if ($isAdmin === true) {
			return;
		}

		// If no behandelaar assigned yet, any authenticated case worker may mutate.
		if ($handler === null || $handler === '') {
			return;
		}

		throw new OCSForbiddenException('Not authorized to modify this complaint');
	}//end authorizeMutation()

	/**
	 * Require the current user to be a coordinator (admin).
	 *
	 * @param string $userId NC user ID
	 *
	 * @return void
	 *
	 * @throws OCSForbiddenException If not a coordinator
	 *
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
	 */
	public function requireCoordinator(string $userId): void {
		$isAdmin = $this->groupManager->isAdmin($userId);
		if ($isAdmin === false) {
			throw new OCSForbiddenException('This action requires coordinator (admin) privileges');
		}
	}//end requireCoordinator()
}//end class
