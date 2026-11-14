<?php

/**
 * Contract of CaseSharingService
 *
 * The public surface of CaseSharingService that other components call, so they depend
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


/**
 * Contract of CaseSharingService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseSharingServiceInterface {

	/**
	 * Check whether a given user may access a case for sharing purposes.
	 *
	 * @param string $caseId The case UUID
	 * @param string $userId The caller's user ID
	 *
	 * @return bool True when the user may proceed
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function canUserAccessCase(string $caseId, string $userId): bool;

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
	): array;

	/**
	 * Create a partner organization-based case share.
	 *
	 * @param string $caseId The UUID of the case to share
	 * @param string $partnerId The UUID of the partner organization
	 * @param string $permissionLevel The permission level slug
	 * @param string $createdBy User ID of the creator
	 * @param string $message An optional note to the receiving organisation
	 * @param array<string> $fieldExclusions Case fields the partner does not see, from FEDERATION_ALLOWED_FIELDS
	 *
	 * @return array The created share data, or an error array
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function createPartnerShare(
		string $caseId,
		string $partnerId,
		string $permissionLevel,
		string $createdBy,
		string $message='',
		array $fieldExclusions=[],
	): array;

	/**
	 * Share a case with somebody who has no account, by minting an
	 * OpenRegister access link over it.
	 *
	 * Each document named on the share mints its own `file` link, so an
	 * outsider who needs one report does not receive the dossier.
	 *
	 * @param string $caseId The UUID of the case to share
	 * @param string $label Human-readable label for the link
	 * @param string $createdBy User ID of the creator
	 * @param string|null $expiresAt ISO 8601 date the share stops opening
	 * @param array<int, string> $capabilities What the holder may do
	 * @param string|null $password An optional password, checked at use
	 * @param array<int, string> $sharedDocuments File ids to share beside the case
	 * @param array<string, mixed> $extra Extra fields to record on the share
	 *
	 * @return array The stored share plus the link, or an error array.
	 *
	 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
	 */
	public function createTokenShare(
		string $caseId,
		string $label,
		string $createdBy,
		?string $expiresAt = null,
		array $capabilities = CaseAccessLinkServiceInterface::DEFAULT_CAPABILITIES,
		?string $password = null,
		array $sharedDocuments = [],
		array $extra = [],
	): array;

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
	public function getCaseIdForFederatedShare(string $shareId): ?string;

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
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getCaseIdForShare(string $shareId): ?string;

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
	public function revokeFederatedShare(string $shareId, string $userId): array;

	/**
	 * Revoke a case share by marking it as revoked in OpenRegister.
	 *
	 * @param string $shareId The UUID of the share to revoke
	 * @param string $userId The user ID performing the revocation
	 *
	 * @return array The updated share data
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function revokeShare(string $shareId, string $userId): array;
}//end interface
