<?php

/**
 * Procest MCP case authorizer.
 *
 * The per-object read authorisation the Procest MCP tools apply before any
 * case data leaves the app (OWASP A01:2021 / ADR-005). Split out of
 * ProcestToolProvider so the rule — admin, assignee, or role-holder — has one
 * home and one caller-visible entry point instead of being interleaved with
 * tool dispatch.
 *
 * The check actually runs: it does NOT return true unconditionally and is NOT
 * wrapped in catch(\Throwable). A backend failure while resolving roles or
 * group membership denies the read.
 *
 * @category Mcp
 * @package  OCA\Procest\Mcp\Tool
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
 * @spec openspec/specs/mcp-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Mcp\Tool;

use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Support\SearchesObjects;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Decides whether the calling user may read a Procest case over MCP.
 *
 * @spec openspec/specs/mcp-integration/spec.md
 */
class ProcestCaseAuthorizer {
	use SearchesObjects;

	/**
	 * Dedicated procest admin group id (mirrors StatusTransitionService).
	 *
	 * @var string
	 */
	private const ADMIN_GROUP_ID = 'procest-admin';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The Procest settings service (OpenRegister bridge + config).
	 * @param IUserSession $userSession The current user session.
	 * @param IGroupManager $groupManager The group manager (for admin checks).
	 * @param LoggerInterface $logger The PSR-3 logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Check whether the calling user may read a case.
	 *
	 * Auth design (OWASP A01:2021 / ADR-005):
	 * - This helper actually runs — it does NOT return true unconditionally
	 *   and is NOT wrapped in catch(\Throwable).
	 * - An admin (procest admin group OR system admin group) may read any case.
	 * - A non-admin may read a case only when they are its assignee (primary
	 *   handler) or hold a role record linking them to the case.
	 *
	 * @param array<string, mixed> $case The case object as an associative array.
	 * @param string $caseUuid The case uuid, as resolved by the reader.
	 *
	 * @return bool True when the caller may read the case.
	 *
	 * @spec openspec/specs/mcp-integration/spec.md
	 */
	public function canReadCase(array $case, string $caseUuid): bool {
		$userId = $this->currentUserId();
		if ($userId === '') {
			return false;
		}

		if ($this->isAdmin(userId: $userId) === true) {
			return true;
		}

		$assignee = $case['assignee'] ?? null;
		if ($assignee !== null && (string)$assignee === $userId) {
			return true;
		}

		return $this->hasRoleOnCase(caseUuid: $caseUuid, userId: $userId);
	}//end canReadCase()

	/**
	 * Check whether the user holds a role record linking them to the case.
	 *
	 * @param string $caseUuid The case uuid.
	 * @param string $userId The Nextcloud user id.
	 *
	 * @return bool True when at least one role record links the user to the case.
	 *
	 * @spec openspec/specs/mcp-integration/spec.md
	 */
	private function hasRoleOnCase(string $caseUuid, string $userId): bool {
		if ($caseUuid === '') {
			return false;
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return false;
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$roleSchema = $this->settingsService->getConfigValue(key: 'role_schema');
		if ($register === '' || $roleSchema === '') {
			return false;
		}

		try {
			$roles = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $roleSchema,
				filters: [
					'case' => $caseUuid,
					'participant' => $userId,
					'_limit' => 1,
				],
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Procest MCP: hasRoleOnCase search failed',
				['caseUuid' => $caseUuid, 'exception' => $e->getMessage()]
			);
			return false;
		}

		return is_array($roles) === true && count($roles) > 0;
	}//end hasRoleOnCase()

	/**
	 * Resolve the current user id, or an empty string when unauthenticated.
	 *
	 * @return string The current user id, or '' when unauthenticated.
	 *
	 * @spec openspec/specs/mcp-integration/spec.md
	 */
	private function currentUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return '';
		}

		return $user->getUID();
	}//end currentUserId()

	/**
	 * Check whether the user is a Procest or Nextcloud system administrator.
	 *
	 * @param string $userId The Nextcloud user id.
	 *
	 * @return bool True when the user is an admin.
	 *
	 * @spec openspec/specs/mcp-integration/spec.md
	 */
	private function isAdmin(string $userId): bool {
		if ($userId === '') {
			return false;
		}

		try {
			if ($this->groupManager->isInGroup($userId, self::ADMIN_GROUP_ID) === true) {
				return true;
			}

			return $this->groupManager->isAdmin($userId);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Procest MCP: admin check failed',
				['userId' => $userId, 'exception' => $e->getMessage()]
			);
			return false;
		}
	}//end isAdmin()
}//end class
