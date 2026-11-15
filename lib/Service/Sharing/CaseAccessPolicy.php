<?php

/**
 * Dossiq case-access policy for the sharing surface.
 *
 * Answers "may this user act on this case?" for every share operation. Four
 * things grant access: the caller is an instance admin, the case names the
 * user as assignee (single-valued or in the `assignees` array), or the user
 * once minted a share for the case — which is standing evidence they had
 * access at that time.
 *
 * Split out of CaseSharingService so the fail-OPEN decisions this policy makes
 * are stated in one auditable place. Note the asymmetry, and that it is
 * deliberate: an unavailable OpenRegister or an unconfigured case schema
 * returns true, because the same-instance sharing feature must keep working on
 * basic setups where the caller is already authenticated by IUserSession. The
 * federation surface makes the opposite choice and fails closed — there is
 * nothing safe to fall back to once a request crosses an org boundary.
 *
 * A share lookup that throws is logged and read as "no share found" (deny),
 * never as a grant.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Sharing;

use OCA\Dossiq\Service\SettingsService;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

/**
 * Decides whether a user may act on a case for sharing purposes.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/case-management/spec.md
 */
class CaseAccessPolicy {
	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings service
	 * @param OpenRegisterSharingGateway $gateway OpenRegister resolution for the sharing surface
	 * @param IGroupManager $groupManager Answers whether the caller is an instance admin
	 * @param LoggerInterface $logger The logger
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly OpenRegisterSharingGateway $gateway,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Check whether a given user may access a case for sharing purposes.
	 *
	 * A user is permitted when any of the following holds:
	 *  - the caller is an NC admin (checked via group membership `admin`)
	 *  - the case's `assignee` field equals the user ID
	 *  - the user ID appears in `assignees` (array)
	 *  - the user ID appears as a `createdBy` on any caseShare linked to the case
	 *
	 * This gate is not read-only: it also guards `createShare`, `revokeShare`,
	 * `initiateTransfer` and `handleTransfer`. So an admin may also mint and
	 * revoke share links and initiate or accept a federated transfer on any
	 * case, as an NC admin may do anything else on the instance. The admin
	 * answer comes before the case is loaded, so for an admin a true does not
	 * mean the case exists.
	 *
	 * For a non-admin, returns true when the case cannot be loaded (fail-safe
	 * for missing OR config) to avoid breaking installations that have not
	 * configured the case schema. The caller must still authenticate via
	 * IUserSession.
	 *
	 * @param string $caseId The case UUID
	 * @param string $userId The caller's user ID
	 *
	 * @return bool True when the user may proceed
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function canUserAccessCase(string $caseId, string $userId): bool {
		// First, and before the case is loaded: an admin needs no assignment,
		// and this is the bullet the docblock has always claimed while nothing
		// checked it. Every case detail page eagerly loads its access links, so
		// an admin opening any case they were not assigned got a 403 and a red
		// "Could not load the links on this case" toast.
		//
		// `=== true` and not a cast: IGroupManager::isAdmin() carries no return
		// type, matching the other admin gates in this app.
		if ($this->groupManager->isAdmin($userId) === true) {
			return true;
		}

		$objectService = $this->gateway->objectService();
		if ($objectService === null) {
			// OR not available — fail-open so the feature still works on basic setups.
			return true;
		}

		$config = $this->caseConfig();
		if ($config === null) {
			return true;
		}

		[$register, $caseSchema] = $config;

		return $this->accessForLoadedCase(
			question: new CaseAccessQuestion(
				objectService: $objectService,
				register: $register,
				caseSchema: $caseSchema,
				caseId: $caseId,
				userId: $userId
			)
		);
	}//end canUserAccessCase()

	/**
	 * Load the case and decide whether the user is its assignee or a sharer.
	 *
	 * @param CaseAccessQuestion $question The case, the caller and where the case is stored
	 *
	 * @return bool True when the user may proceed
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	private function accessForLoadedCase(CaseAccessQuestion $question): bool {
		$caseData = $this->loadCaseForAccess(question: $question);
		if (is_bool($caseData) === true) {
			return $caseData;
		}

		return $this->isAssigneeOrSharer(question: $question, caseData: $caseData);
	}//end accessForLoadedCase()

	/**
	 * The configured register and case schema, or null when either is missing.
	 *
	 * @return array{0: string, 1: string}|null The register and case schema ids
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	private function caseConfig(): ?array {
		$register = $this->settingsService->getConfigValue('register');
		$caseSchema = $this->settingsService->getConfigValue('case_schema');

		if (empty($register) === true || empty($caseSchema) === true) {
			return null;
		}

		return [$register, $caseSchema];
	}//end caseConfig()

	/**
	 * Load the case, or the access answer when it cannot be loaded.
	 *
	 * @param CaseAccessQuestion $question The case, the caller and where the case is stored
	 *
	 * @return array<string, mixed>|bool The case data, false when the case is missing, true when loading failed
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	private function loadCaseForAccess(CaseAccessQuestion $question): array|bool {
		$caseId = $question->caseId;
		try {
			$caseObj = $question->objectService->find($caseId, register: (int)$question->register, schema: (int)$question->caseSchema);
			if ($caseObj === null) {
				// Case not found — deny (treated as 404 by callers).
				return false;
			}

			$caseData = $this->gateway->toArray(value: $caseObj);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'CaseSharingService: canUserAccessCase load failed',
				['caseId' => $caseId, 'exception' => $e->getMessage()]
			);
			return true;
		}

		return $caseData;
	}//end loadCaseForAccess()

	/**
	 * Whether the user is an assignee of the case, or created a share for it.
	 *
	 * @param CaseAccessQuestion $question The case, the caller and where the case is stored
	 * @param array<string, mixed> $caseData The case data
	 *
	 * @return bool True when the user may proceed
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	private function isAssigneeOrSharer(CaseAccessQuestion $question, array $caseData): bool {
		// Direct assignee field, then the assignees array.
		if ($this->isCaseAssignee(caseData: $caseData, userId: $question->userId) === true) {
			return true;
		}

		// Check existing caseShares: if this user created any share for this case they
		// already had access at that time.
		if ($this->hasCreatedShareForCase(question: $question) === true) {
			return true;
		}

		return false;
	}//end isAssigneeOrSharer()

	/**
	 * Whether a case names the given user as assignee.
	 *
	 * Accepts both the single-valued `assignee` field and membership of the
	 * `assignees` array; either one grants access.
	 *
	 * @param array<string, mixed> $caseData The case data
	 * @param string $userId The caller's user ID
	 *
	 * @return bool True when the user is an assignee of the case
	 */
	private function isCaseAssignee(array $caseData, string $userId): bool {
		// Direct assignee field (single user ID string).
		if (isset($caseData['assignee']) === true && (string)$caseData['assignee'] === $userId) {
			return true;
		}

		// Assignees array.
		$assignees = ($caseData['assignees'] ?? []);
		if (is_array($assignees) === true && in_array($userId, $assignees, true) === true) {
			return true;
		}

		return false;
	}//end isCaseAssignee()

	/**
	 * Whether the given user created any caseShare for the given case.
	 *
	 * A user who once minted a share for the case already had access at that
	 * time, so the share record is treated as standing evidence of access.
	 * A failed lookup is logged and treated as "no share found" (deny), never
	 * as a grant.
	 *
	 * @param CaseAccessQuestion $question The case, the caller and where the case is stored
	 *
	 * @return bool True when a share created by this user exists
	 */
	private function hasCreatedShareForCase(CaseAccessQuestion $question): bool {
		$objectService = $question->objectService;
		$caseId = $question->caseId;
		$userId = $question->userId;
		$register = (int)$question->register;
		$shareSchema = $this->settingsService->getConfigValue('case_share_schema');
		if (empty($shareSchema) === true) {
			return false;
		}

		try {
			$shares = $objectService->findAll(['filters' => ['register' => $register, 'schema' => (int)$shareSchema, 'caseId' => $caseId]]);

			return $this->anyCreatedBy(shares: $shares, userId: $userId);
		} catch (\Throwable $e) {
			$this->logger->debug('CaseSharingService: share lookup in canUserAccessCase failed', ['caseId' => $caseId, 'exception' => $e->getMessage()]);
		}//end try

		return false;
	}//end hasCreatedShareForCase()

	/**
	 * Whether any of the shares was created by the user.
	 *
	 * @param iterable $shares The case's share objects
	 * @param string   $userId The caller's user ID
	 *
	 * @return bool True when one share names the user as its creator
	 */
	private function anyCreatedBy(iterable $shares, string $userId): bool {
		foreach ($shares as $share) {
			$shareData = $this->gateway->toArray(value: $share);
			if (isset($shareData['createdBy']) === true && (string)$shareData['createdBy'] === $userId) {
				return true;
			}
		}

		return false;
	}//end anyCreatedBy()
}//end class
