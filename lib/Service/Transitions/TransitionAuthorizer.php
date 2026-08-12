<?php

/**
 * Procest Transition Authorizer.
 *
 * The OR-RBAC group gate for `case.status` transitions. Split out of
 * StatusTransitionService so that service keeps only the transition
 * mechanics: the question "may THIS user perform THIS transition?" — and the
 * single trusted IGroupManager membership check that answers it — lives here
 * and nowhere else.
 *
 * Consumes the `authorization` array frozen onto a transition at publish time
 * (literal NC group ids resolved from `roleType.ncGroupId`). Semantics mirror
 * OpenRegister's `PermissionHandler::isTransitionAuthorized`:
 *   - an absent or empty list authorises everyone (open transition);
 *   - an anonymous caller can never satisfy a group gate;
 *   - admins bypass;
 *   - otherwise the caller MUST belong to at least one listed group.
 *
 * A failing membership lookup is logged and treated as "not a member" — the
 * gate is fail-closed, never fail-open.
 *
 * @category Service
 * @package  OCA\Procest\Service\Transitions
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Transitions;

use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

/**
 * Enforces a transition's OR-RBAC group authorization list.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
class TransitionAuthorizer {

	/**
	 * Group ID used to gate admin-only free-form transitions. Matches the
	 * naming used elsewhere in Procest for the admin role.
	 */
	public const ADMIN_GROUP_ID = 'procest-admin';

	/**
	 * Constructor.
	 *
	 * @param IGroupManager $groupManager Group manager (admin + membership gate).
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Check if the given user is in the procest admin group.
	 *
	 * @param string $userId UID.
	 *
	 * @return bool True when the user is a procest or instance admin.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function isAdmin(string $userId): bool {
		if ($userId === '') {
			return false;
		}

		try {
			// Accept membership in either the dedicated procest admin group OR the global admin group.
			if ($this->groupManager->isInGroup($userId, self::ADMIN_GROUP_ID) === true) {
				return true;
			}

			return $this->groupManager->isInGroup($userId, 'admin');
		} catch (\Throwable $e) {
			$this->logger->error('StatusTransitionService: admin check failed', ['exception' => $e->getMessage()]);
			return false;
		}
	}//end isAdmin()

	/**
	 * Enforce a transition's OR-RBAC group authorization list.
	 *
	 * @param array<string, mixed> $transition The transition spec.
	 * @param string $userId The acting user UID.
	 *
	 * @return bool True when the caller may perform the transition.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function isTransitionGroupAuthorized(array $transition, string $userId): bool {
		$authorization = ($transition['authorization'] ?? []);
		if (is_array($authorization) === false || $authorization === []) {
			return true;
		}

		if ($userId === '') {
			return false;
		}

		if ($this->isAdmin(userId: $userId) === true) {
			return true;
		}

		foreach ($authorization as $groupId) {
			$groupId = (string)$groupId;
			if ($groupId === '') {
				continue;
			}

			try {
				if ($this->groupManager->isInGroup($userId, $groupId) === true) {
					return true;
				}
			} catch (\Throwable $e) {
				$this->logger->error(
					'StatusTransitionService: group membership check failed',
					['exception' => $e->getMessage(), 'groupId' => $groupId],
				);
			}
		}

		return false;
	}//end isTransitionGroupAuthorized()
}//end class
