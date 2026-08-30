<?php

/**
 * Dossiq Case Sharing Service
 *
 * Service for managing case shares, token generation, and permission enforcement.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTime;
use OCA\Dossiq\Service\Sharing\CaseAccessPolicy;
use OCA\Dossiq\Service\Sharing\CaseTokenShareService;
use OCA\Dossiq\Service\Sharing\FederatedCaseShareService;
use OCA\Dossiq\Service\Sharing\OpenRegisterSharingGateway;
use Psr\Log\LoggerInterface;

/**
 * Entry point for case sharing, and the owner of the in-app partner hand-off.
 *
 * Dossiq shares a case in three distinct ways, each with its own trust model,
 * and this class is the seam between them:
 *
 *  - a PUBLIC token link, delegated to {@see CaseTokenShareService}, which
 *    mints nothing itself and defers entirely to OpenRegister's shares leaf;
 *  - a PARTNER-organisation hand-off, owned here, because org-to-org case
 *    hand-off inside one instance is zaak-domain logic and carries no public
 *    token (ADR-022);
 *  - a FEDERATED (OCM) share, delegated to {@see FederatedCaseShareService},
 *    which crosses an org boundary and therefore shares a redacted snapshot
 *    rather than the live case.
 *
 * Access decisions for all three live in {@see CaseAccessPolicy}, and every
 * reach into OpenRegister goes through {@see OpenRegisterSharingGateway}.
 *
 * @spec openspec/specs/federated-case-collaboration/spec.md
 */
class CaseSharingService {
	/**
	 * Hard-coded allow-list of case-summary fields that may ever cross a
	 * federation boundary. A field NOT in this list is rejected outright by
	 * {@see createFederatedShare()} — never silently dropped. `@self` and
	 * `relations` are deliberately never included: the fleet lesson is that
	 * a relations mirror can leak writeOnly fields, so it is excluded by
	 * construction rather than filtered after the fact.
	 *
	 * This constant stays on CaseSharingService: it is the documented source
	 * of truth that `src/utils/federatedShareHelpers.js` mirrors by name.
	 *
	 * @var string[]
	 */
	public const FEDERATION_ALLOWED_FIELDS = [
		'title',
		'description',
		'status',
		'caseType',
		'priority',
		'dueDate',
		'requestedDate',
	];

	/**
	 * Constructor for the CaseSharingService.
	 *
	 * @param SettingsService $settingsService The settings service
	 * @param OpenRegisterSharingGateway $gateway OpenRegister resolution for the sharing surface
	 * @param CaseAccessPolicy $accessPolicy Per-case access decisions
	 * @param CaseTokenShareService $tokenShares Public "track your case" token links
	 * @param FederatedCaseShareService $federatedShares Cross-org (OCM) case shares
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		private SettingsService $settingsService,
		private OpenRegisterSharingGateway $gateway,
		private CaseAccessPolicy $accessPolicy,
		private CaseTokenShareService $tokenShares,
		private FederatedCaseShareService $federatedShares,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Check whether a given user may access a case for sharing purposes.
	 *
	 * @param string $caseId The case UUID
	 * @param string $userId The caller's user ID
	 *
	 * @return bool True when the user may proceed
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function canUserAccessCase(string $caseId, string $userId): bool {
		return $this->accessPolicy->canUserAccessCase(caseId: $caseId, userId: $userId);
	}//end canUserAccessCase()

	/**
	 * Create a public "track your case" token link through OpenRegister's
	 * shares integration leaf.
	 *
	 * @param string $caseId The UUID of the case to share
	 * @param string $label Human-readable label for the link
	 * @param string $createdBy User ID of the creator (audit log)
	 * @param string|null $expiresAt ISO 8601 expiration datetime, or null
	 *                               for a non-expiring link
	 *
	 * @return array The minted token metadata + public resolve URL, or an
	 *               error array when the leaf is unavailable.
	 *
	 * @spec openspec/changes/migrate-public-share-to-shares-leaf/tasks.md#P1.2
	 */
	public function createTokenShare(
		string $caseId,
		string $label,
		string $createdBy,
		?string $expiresAt = null,
	): array {
		return $this->tokenShares->createTokenShare(
			caseId: $caseId,
			label: $label,
			createdBy: $createdBy,
			expiresAt: $expiresAt
		);
	}//end createTokenShare()

	/**
	 * Resolve whether a leaf-minted token belongs to the given case.
	 *
	 * @param string $tokenId The leaf token id (numeric) or opaque token.
	 * @param string $caseId The candidate case UUID.
	 *
	 * @return bool True when the token is one of the case's minted tokens.
	 *
	 * @spec openspec/changes/migrate-public-share-to-shares-leaf/tasks.md#P1.3
	 */
	public function tokenBelongsToCase(string $tokenId, string $caseId): bool {
		return $this->tokenShares->tokenBelongsToCase(tokenId: $tokenId, caseId: $caseId);
	}//end tokenBelongsToCase()

	/**
	 * Revoke a public "track your case" token link through the OR shares leaf.
	 *
	 * The caller MUST have already authorised the revoke against the owning
	 * case (see {@see tokenBelongsToCase()} + {@see canUserAccessCase()}).
	 *
	 * @param string $tokenId The token id (or the opaque token) minted by
	 *                        the leaf.
	 *
	 * @return bool True when the leaf accepted the revoke.
	 *
	 * @spec openspec/changes/migrate-public-share-to-shares-leaf/tasks.md#P1.3
	 */
	public function revokeTokenShare(string $tokenId): bool {
		return $this->tokenShares->revokeTokenShare(tokenId: $tokenId);
	}//end revokeTokenShare()

	/**
	 * Create a partner organization-based case share.
	 *
	 * @param string $caseId The UUID of the case to share
	 * @param string $partnerId The UUID of the partner organization
	 * @param string $permissionLevel The permission level slug
	 * @param string $createdBy User ID of the creator
	 *
	 * @return array The created share data
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function createPartnerShare(
		string $caseId,
		string $partnerId,
		string $permissionLevel,
		string $createdBy,
	): array {
		$objectService = $this->gateway->objectService();
		if ($objectService === null) {
			return ['error' => 'OpenRegister is not available'];
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_share_schema');

		// Partner-organisation handover is zaak-domain logic (org-to-org case
		// hand-off), NOT public token sharing — it stays in-app per ADR-022.
		// It carries no public token: the bespoke token mechanism moved to the
		// OR shares leaf (createTokenShare) and is the only public surface.
		$shareData = [
			'caseId' => $caseId,
			'shareType' => 'partner',
			'partnerId' => $partnerId,
			'permissionLevel' => $permissionLevel,
			'createdBy' => $createdBy,
		];

		$result = $objectService->saveObject(
			object: $shareData,
			register: (int)$register,
			schema: (int)$schema,
		);

		$this->logger->info(
			'Dossiq: Partner share created',
			[
				'caseId' => $caseId,
				'partnerId' => $partnerId,
				'shareId' => $result->getUuid(),
			]
		);

		return $result->jsonSerialize();
	}//end createPartnerShare()

	/**
	 * Look up the caseId for a given share UUID.
	 *
	 * Used by the controller to perform the per-case RBAC check before revocation.
	 * Returns null if the share cannot be found or OR is unavailable.
	 *
	 * @param string $shareId The share UUID
	 *
	 * @return string|null The caseId, or null when unavailable
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getCaseIdForShare(string $shareId): ?string {
		$objectService = $this->gateway->objectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue('register');
		$shareSchema = $this->settingsService->getConfigValue('case_share_schema');

		if (empty($register) === true || empty($shareSchema) === true) {
			return null;
		}

		try {
			$shareObj = $objectService->find($shareId, register: (int)$register, schema: (int)$shareSchema);
			if ($shareObj === null) {
				return null;
			}

			$shareData = $shareObj;
			if (is_array($shareObj) === false) {
				$shareData = $shareObj->jsonSerialize();
			}

			if (isset($shareData['caseId']) === true) {
				return (string)$shareData['caseId'];
			}

			return null;
		} catch (\Throwable $e) {
			$this->logger->debug(
				'CaseSharingService: getCaseIdForShare failed',
				['shareId' => $shareId, 'exception' => $e->getMessage()]
			);
			return null;
		}//end try
	}//end getCaseIdForShare()

	/**
	 * Revoke a case share by marking it as revoked in OpenRegister.
	 *
	 * @param string $shareId The UUID of the share to revoke
	 * @param string $userId The user ID performing the revocation
	 *
	 * @return array The updated share data
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function revokeShare(string $shareId, string $userId): array {
		$objectService = $this->gateway->objectService();
		if ($objectService === null) {
			return ['error' => 'OpenRegister is not available'];
		}

		$register = $this->settingsService->getConfigValue('register');
		$shareSchema = $this->settingsService->getConfigValue('case_share_schema');

		if (empty($register) === true || empty($shareSchema) === true) {
			return ['error' => 'Service unavailable'];
		}

		$shareObj = $objectService->find($shareId, register: (int)$register, schema: (int)$shareSchema);
		if ($shareObj === null) {
			return ['error' => 'Share not found'];
		}

		$shareData = $shareObj;
		if (is_array($shareObj) === false) {
			$shareData = $shareObj->jsonSerialize();
		}

		$shareData['status'] = 'revoked';
		$shareData['revokedBy'] = $userId;
		$shareData['revokedAt'] = (new DateTime())->format('c');

		$result = $objectService->saveObject(object: $shareData, register: (int)$register, schema: (int)$shareSchema);

		$this->logger->info(
			'Dossiq: Case share revoked',
			['shareId' => $shareId, 'revokedBy' => $userId]
		);

		if (is_array($result) === true) {
			return $result;
		}

		return $result->jsonSerialize();
	}//end revokeShare()

	/**
	 * Create a federated case share: a purpose-built, field-scoped snapshot
	 * of the case shared with a remote org over OpenRegister's OCM
	 * federation leaf.
	 *
	 * @param string $caseId The UUID of the case to share
	 * @param string $remoteCloudId The federated target (slug@host)
	 * @param array<string> $sharedFields Requested case field names
	 * @param array<string> $sharedDocuments Requested document references
	 * @param string $permissionLevel Permission level slug (informational; the OR grant is always 'read')
	 * @param string $createdBy User ID of the share creator
	 *
	 * @return array The created federated share data, or an error array
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#federated-case-share-is-a-redacted-snapshot-never-the-live-case
	 */
	public function createFederatedShare(
		string $caseId,
		string $remoteCloudId,
		array $sharedFields,
		array $sharedDocuments,
		string $permissionLevel,
		string $createdBy,
	): array {
		return $this->federatedShares->createFederatedShare(
			caseId: $caseId,
			remoteCloudId: $remoteCloudId,
			sharedFields: $sharedFields,
			sharedDocuments: $sharedDocuments,
			permissionLevel: $permissionLevel,
			createdBy: $createdBy
		);
	}//end createFederatedShare()

	/**
	 * Revoke a federated case share.
	 *
	 * @param string $shareId The UUID of the caseFederatedShare to revoke
	 * @param string $userId The user ID performing the revocation
	 *
	 * @return array The updated share data, or an error array
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#federated-share-revocation-is-immediate-and-single-sourced
	 */
	public function revokeFederatedShare(string $shareId, string $userId): array {
		return $this->federatedShares->revokeFederatedShare(shareId: $shareId, userId: $userId);
	}//end revokeFederatedShare()

	/**
	 * Look up the caseId for a given federated share UUID (for the
	 * controller's per-case RBAC check before revocation).
	 *
	 * @param string $shareId The federated share UUID
	 *
	 * @return string|null The caseId, or null when unavailable/not found
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#federated-share-revocation-is-immediate-and-single-sourced
	 */
	public function getCaseIdForFederatedShare(string $shareId): ?string {
		return $this->federatedShares->getCaseIdForFederatedShare(shareId: $shareId);
	}//end getCaseIdForFederatedShare()
}//end class
