<?php

/**
 * Contract of CaseTransferService
 *
 * The public surface of CaseTransferService that other components call, so they depend
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
 * Contract of CaseTransferService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseTransferServiceInterface {

	/**
	 * Accept a handover on the receiving team's behalf.
	 *
	 * @param string $transferId The handover's uuid.
	 * @param string $acceptedBy Who accepted it.
	 *
	 * @return array The settled record.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-the-receiving-team-can-refuse-a-handover-back-req-hand-02
	 */
	public function acceptHandover(string $transferId, string $acceptedBy): array;

	/**
	 * Accept a pending case transfer request. Idempotent when called again
	 * after already reaching 'accepted'; refuses loudly for any other
	 * non-pending state (e.g. a prior reject).
	 *
	 * @param string $transferId The UUID of the transfer request
	 * @param string|null $remoteCloudId Set when the accept was authenticated via a federated token (custody audit actorType)
	 * @param string|null $actingUser    The session user who accepts a local transfer; ignored for a federated one
	 *
	 * @return array The updated transfer data, or an error array
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#case-transfer-extends-across-federation-with-idempotent-acceptreject-and-a-custody-audit-trail
	 */
	public function acceptTransfer(string $transferId, ?string $remoteCloudId = null, ?string $actingUser = null): array;

	/**
	 * Look up the caseId for a given transfer UUID (for the controller's
	 * per-case RBAC check before local accept/reject).
	 *
	 * @param string $transferId The transfer UUID
	 *
	 * @return string|null The caseId, or null when unavailable/not found
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#local-transfer-acceptreject-requires-case-access-pre-existing-gap-fix
	 */
	public function getCaseIdForTransfer(string $transferId): ?string;

	/**
	 * Hand a case to another team inside this organisation.
	 *
	 * 🔑 ONE DOOR, TWO BOUNDARIES. The internal handover enters through this
	 * class rather than beside it, so anyone looking for "how does a case
	 * move" finds every answer in one place and neither path can grow a
	 * custody trail the other does not know about. The work itself lives in
	 * {@see InternalHandover} because this class was already at its
	 * complexity ceiling, which is decomposition and not a second mechanism.
	 *
	 * @param string $caseId The case uuid.
	 * @param string $targetTeam The receiving team's Nextcloud group id.
	 * @param string $reason Why the case is moving.
	 * @param string $initiatedBy Who handed it on.
	 * @param bool $doorzending Whether this is a doorzending under Awb 2:3.
	 *
	 * @return array The transfer record.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-a-case-is-handed-to-another-team-as-a-recorded-act-req-hand-01
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The declaration Awb 2:3
	 * hangs on, stored on the record. See InternalHandover::initiate().
	 */
	public function handToTeam(
		string $caseId,
		string $targetTeam,
		string $reason,
		string $initiatedBy,
		bool $doorzending = false,
	): array;

	/**
	 * Initiate a case transfer to a target organization, optionally over
	 * federation.
	 *
	 * When `$remoteCloudId` is set, this is a zaakoverdracht across
	 * Nextcloud instances: the call is idempotent per
	 * (caseId, targetOrganization, remoteCloudId) — a repeat initiate
	 * returns the existing pending/accepted transfer rather than creating a
	 * duplicate — and a transfer-scoped OR federated share
	 * (`scope: object`, `permissions: read-write`, pointed at ONLY this
	 * transfer object) is minted so the remote org can later authenticate
	 * its accept/reject call via {@see resolveFederatedTransferShare()}.
	 *
	 * @param string $caseId The UUID of the case to transfer
	 * @param string $sourceOrganization The source organization identifier
	 * @param string $targetOrganization The UUID of the target partner organization
	 * @param string $reason The reason for transfer
	 * @param string $requestedDate The requested transfer date (ISO 8601)
	 * @param string $initiatedBy User ID of the initiator (custody audit trail)
	 * @param string|null $remoteCloudId The federated target (slug@host), or null for a local-only transfer
	 *
	 * @return array The created (or existing, when idempotent) transfer request data
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#case-transfer-extends-across-federation-with-idempotent-acceptreject-and-a-custody-audit-trail
	 */
	public function initiateTransfer(
		string $caseId,
		string $sourceOrganization,
		string $targetOrganization,
		string $reason,
		string $requestedDate,
		string $initiatedBy = '',
		?string $remoteCloudId = null,
	): array;

	/**
	 * The handovers a team sent that nobody has picked up.
	 *
	 * @param string $team The sending team's Nextcloud group id.
	 *
	 * @return array The outstanding handovers.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-the-receiving-team-can-refuse-a-handover-back-req-hand-02
	 */
	public function outstandingHandovers(string $team): array;

	/**
	 * Refuse a handover back, with a reason.
	 *
	 * @param string $transferId The handover's uuid.
	 * @param string $reason Why the receiving team will not take it.
	 * @param string $refusedBy Who refused it.
	 *
	 * @return array The settled record.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-the-receiving-team-can-refuse-a-handover-back-req-hand-02
	 */
	public function refuseHandover(string $transferId, string $reason, string $refusedBy): array;

	/**
	 * Reject a pending case transfer request. Idempotent when called again
	 * after already reaching 'rejected'; refuses loudly for any other
	 * non-pending state.
	 *
	 * @param string $transferId The UUID of the transfer request
	 * @param string $rejectionReason The reason for rejection
	 * @param string|null $remoteCloudId Set when the reject was authenticated via a federated token
	 * @param string|null $actingUser    The session user who rejects a local transfer; ignored for a federated one
	 *
	 * @return array The updated transfer data, or an error array
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#case-transfer-extends-across-federation-with-idempotent-acceptreject-and-a-custody-audit-trail
	 */
	public function rejectTransfer(
		string $transferId,
		string $rejectionReason,
		?string $remoteCloudId = null,
		?string $actingUser = null,
	): array;

	/**
	 * Resolve a scoped bearer token to the transfer it authorises — used
	 * exclusively by the `#[PublicPage]` remote accept/reject endpoint.
	 * Requires an OUTGOING, read-write, non-revoked/declined OR
	 * FederatedShare whose objectUri tail matches this exact transfer id,
	 * so a token minted for one transfer (or for a read-only case-summary
	 * share) can never authenticate a different transfer.
	 *
	 * @param string $shareToken The scoped bearer token
	 * @param string $transferId The candidate transfer UUID
	 *
	 * @return array{sharedWith: string, organisation: ?string}|null The resolved grant, or null when invalid
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#a-read-only-case-share-token-cannot-accept-a-transfer
	 */
	public function resolveFederatedTransferShare(string $shareToken, string $transferId): ?array;
}//end interface
