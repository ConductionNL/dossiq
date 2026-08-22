<?php

/**
 * Dossiq Case Transfer Service
 *
 * Service for managing case ownership transfers between organizations.
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
use OCA\Dossiq\Service\Transfer\TransferRegisterGateway;
use OCA\Dossiq\Service\Transfer\TransferShareBroker;
use Psr\Log\LoggerInterface;

/**
 * Service for managing case ownership transfers between organizations.
 *
 * Supports initiating, accepting, and rejecting transfer requests
 * with full audit trail and notification support.
 *
 * @spec openspec/specs/federated-case-collaboration/spec.md
 */
class CaseTransferService {
	/**
	 * Constructor for the CaseTransferService.
	 *
	 * @param SettingsService $settingsService The settings service
	 * @param TransferRegisterGateway $gateway OpenRegister resolution for the transfer surface
	 * @param TransferShareBroker $shareBroker Transfer-scoped OCM token minting and resolution
	 * @param LoggerInterface $logger The logger
	 * @param TenantAuditTrailService $auditTrail Audit-trail emitter for custody-change actions
	 *
	 * @return void
	 */
	public function __construct(
		private SettingsService $settingsService,
		private TransferRegisterGateway $gateway,
		private TransferShareBroker $shareBroker,
		private LoggerInterface $logger,
		private TenantAuditTrailService $auditTrail,
	) {
	}//end __construct()

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
	): array {
		$objectService = $this->gateway->objectService();
		if ($objectService === null) {
			return ['error' => 'OpenRegister is not available'];
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_transfer_schema');

		$idempotencyKey = null;
		if ($remoteCloudId !== null && $remoteCloudId !== '') {
			$shareService = $this->gateway->federationShareService();
			if ($shareService === null) {
				return ['error' => 'Federated case transfer requires the OpenRegister federation leaf'];
			}

			$idempotencyKey = hash('sha256', $caseId . '|' . $targetOrganization . '|' . $remoteCloudId);

			$existing = $this->findTransferByIdempotencyKey(
				idempotencyKey: $idempotencyKey,
				register: (int)$register,
				schema: (int)$schema,
				objectService: $objectService,
			);
			if ($existing !== null) {
				return $existing;
			}
		}

		$now = (new DateTime())->format('c');

		$transferData = $this->buildInitialTransferData(
			caseId: $caseId,
			sourceOrganization: $sourceOrganization,
			targetOrganization: $targetOrganization,
			reason: $reason,
			requestedDate: $requestedDate,
			initiatedBy: $initiatedBy,
			remoteCloudId: $remoteCloudId,
			idempotencyKey: $idempotencyKey,
			now: $now,
		);

		$result = $objectService->saveObject(
			object: $transferData,
			register: (int)$register,
			schema: (int)$schema,
		);
		$resultData = $result->jsonSerialize();

		if ($remoteCloudId !== null && $remoteCloudId !== '') {
			$transferUuid = (string)($resultData['id'] ?? $resultData['uuid'] ?? '');
			$mintedShare = $this->shareBroker->mintTransferShare(
				transferUuid: $transferUuid,
				remoteCloudId: $remoteCloudId,
				register: (string)$register,
				schema: (string)$schema,
			);
			if ($mintedShare === null) {
				return ['error' => 'Could not mint the federated transfer token'];
			}

			$resultData['federationShareId'] = $mintedShare->getId();
			$result = $objectService->saveObject(object: $resultData, register: (int)$register, schema: (int)$schema);
			$resultData = $result->jsonSerialize();
		}

		$this->recordTransferInitiated(
			result: $result,
			caseId: $caseId,
			targetOrganization: $targetOrganization,
			initiatedBy: $initiatedBy,
			remoteCloudId: $remoteCloudId,
		);

		return $resultData;
	}//end initiateTransfer()

	/**
	 * Build the initial (pending) transfer object payload with its first
	 * custody-audit entry.
	 *
	 * @param string $caseId The UUID of the case to transfer
	 * @param string $sourceOrganization The source organization identifier
	 * @param string $targetOrganization The UUID of the target partner organization
	 * @param string $reason The reason for transfer
	 * @param string $requestedDate The requested transfer date (ISO 8601)
	 * @param string $initiatedBy User ID of the initiator (custody audit trail)
	 * @param string|null $remoteCloudId The federated target (slug@host), or null for a local-only transfer
	 * @param string|null $idempotencyKey The federated idempotency key, or null for a local-only transfer
	 * @param string $now The ISO 8601 timestamp used for the custody entry
	 *
	 * @return array The transfer object payload ready to be saved
	 */
	private function buildInitialTransferData(
		string $caseId,
		string $sourceOrganization,
		string $targetOrganization,
		string $reason,
		string $requestedDate,
		string $initiatedBy,
		?string $remoteCloudId,
		?string $idempotencyKey,
		string $now,
	): array {
		return [
			'caseId' => $caseId,
			'sourceOrganization' => $sourceOrganization,
			'targetOrganization' => $targetOrganization,
			'reason' => $reason,
			'requestedDate' => $requestedDate,
			'status' => 'pending',
			'initiatedBy' => $initiatedBy,
			'remoteCloudId' => $remoteCloudId,
			'idempotencyKey' => $idempotencyKey,
			'custodyAuditTrail' => [
				[
					'event' => 'initiated',
					'actor' => $initiatedBy,
					'actorType' => 'local',
					'cloudId' => '',
					'timestamp' => $now,
				],
			],
		];
	}//end buildInitialTransferData()

	/**
	 * Emit the tenant audit event and log line for a freshly initiated transfer.
	 *
	 * @param object $result The saved transfer object
	 * @param string $caseId The UUID of the transferred case
	 * @param string $targetOrganization The UUID of the target partner organization
	 * @param string $initiatedBy User ID of the initiator
	 * @param string|null $remoteCloudId The federated target (slug@host), or null for a local-only transfer
	 *
	 * @return void
	 */
	private function recordTransferInitiated(
		object $result,
		string $caseId,
		string $targetOrganization,
		string $initiatedBy,
		?string $remoteCloudId,
	): void {
		$this->auditTrail->emit(
			[
				'action' => 'case_transfer_initiated',
				'actor' => $initiatedBy,
				'resource' => $caseId,
				'tenantId' => (string)($remoteCloudId ?? $targetOrganization),
			]
		);

		$this->logger->info(
			'Dossiq: Case transfer initiated',
			[
				'caseId' => $caseId,
				'transferId' => $result->getUuid(),
				'target' => $targetOrganization,
				'federated' => ($remoteCloudId !== null),
			]
		);
	}//end recordTransferInitiated()

	/**
	 * Accept a pending case transfer request. Idempotent when called again
	 * after already reaching 'accepted'; refuses loudly for any other
	 * non-pending state (e.g. a prior reject).
	 *
	 * @param string $transferId The UUID of the transfer request
	 * @param string|null $remoteCloudId Set when the accept was authenticated via a federated token (custody audit actorType)
	 *
	 * @return array The updated transfer data, or an error array
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#case-transfer-extends-across-federation-with-idempotent-acceptreject-and-a-custody-audit-trail
	 */
	public function acceptTransfer(string $transferId, ?string $remoteCloudId = null): array {
		return $this->completeTransfer(
			transferId: $transferId,
			targetStatus: 'accepted',
			remoteCloudId: $remoteCloudId,
		);
	}//end acceptTransfer()

	/**
	 * Reject a pending case transfer request. Idempotent when called again
	 * after already reaching 'rejected'; refuses loudly for any other
	 * non-pending state.
	 *
	 * @param string $transferId The UUID of the transfer request
	 * @param string $rejectionReason The reason for rejection
	 * @param string|null $remoteCloudId Set when the reject was authenticated via a federated token
	 *
	 * @return array The updated transfer data, or an error array
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#case-transfer-extends-across-federation-with-idempotent-acceptreject-and-a-custody-audit-trail
	 */
	public function rejectTransfer(string $transferId, string $rejectionReason, ?string $remoteCloudId = null): array {
		return $this->completeTransfer(
			transferId: $transferId,
			targetStatus: 'rejected',
			remoteCloudId: $remoteCloudId,
			rejectionReason: $rejectionReason,
		);
	}//end rejectTransfer()

	/**
	 * Shared accept/reject state machine. Refuses ambiguous transitions
	 * loudly (any non-pending status other than the one already matching
	 * the requested target status), and is idempotent on a repeated call
	 * that already reached the target status.
	 *
	 * @param string $transferId The UUID of the transfer
	 * @param string $targetStatus 'accepted' or 'rejected'
	 * @param string|null $remoteCloudId Set for a federated (remote-authenticated) call
	 * @param string $rejectionReason Reason text (rejected only)
	 *
	 * @return array The updated (or existing, when idempotent) transfer data, or an error array
	 */
	private function completeTransfer(
		string $transferId,
		string $targetStatus,
		?string $remoteCloudId = null,
		string $rejectionReason = '',
	): array {
		$objectService = $this->gateway->objectService();
		if ($objectService === null) {
			return ['error' => 'OpenRegister is not available'];
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_transfer_schema');

		$transfer = $objectService->find($transferId, register: (int)$register, schema: (int)$schema);
		if ($transfer === null) {
			return ['error' => 'Transfer not found'];
		}

		$transferData = (array)$transfer;
		if (is_object($transfer) === true) {
			$transferData = $transfer->jsonSerialize();
		}

		$currentStatus = (string)($transferData['status'] ?? '');
		if ($currentStatus === $targetStatus) {
			// Idempotent replay: same call already applied, return as-is.
			return $transferData;
		}

		if ($currentStatus !== 'pending') {
			// Ambiguous/conflicting state (e.g. accept after reject) — refuse loudly.
			return ['error' => 'Transfer is not in a state that can be ' . $targetStatus . ' (current status: ' . $currentStatus . ')'];
		}

		$caseId = (string)($transferData['caseId'] ?? '');
		$now = (new DateTime())->format('c');

		// Read the existing custody chain before the status writes below, which
		// is also where the pre-existing trail must be preserved from.
		$auditTrail = (array)($transferData['custodyAuditTrail'] ?? []);
		$actorType = $this->resolveCustodyActorType(remoteCloudId: $remoteCloudId);

		$transferData = $this->applyTransferCompletion(
			transferData: $transferData,
			auditTrail: $auditTrail,
			targetStatus: $targetStatus,
			rejectionReason: $rejectionReason,
			actorType: $actorType,
			remoteCloudId: $remoteCloudId,
			now: $now,
		);

		$result = $objectService->saveObject(
			object: $transferData,
			register: (int)$register,
			schema: (int)$schema,
		);

		$this->auditTrail->emit(
			[
				'action' => 'case_transfer_' . $targetStatus,
				'actor' => ($remoteCloudId ?? 'local'),
				'role' => $actorType,
				'resource' => $caseId,
				'tenantId' => ($remoteCloudId ?? ''),
			]
		);

		$this->logger->info(
			'Dossiq: Case transfer ' . $targetStatus,
			[
				'transferId' => $transferId,
				'caseId' => $caseId,
				'remoteCloudId' => $remoteCloudId,
			]
		);

		if (is_array($result) === true) {
			return $result;
		}

		return $result->jsonSerialize();
	}//end completeTransfer()

	/**
	 * Resolve the custody-audit actor type for a completion event.
	 *
	 * @param string|null $remoteCloudId Set when the call was authenticated via a federated token
	 *
	 * @return string 'remote' for a federated call, 'local' otherwise
	 */
	private function resolveCustodyActorType(?string $remoteCloudId): string {
		if ($remoteCloudId !== null) {
			return 'remote';
		}

		return 'local';
	}//end resolveCustodyActorType()

	/**
	 * Apply the completion status, optional rejection reason and custody entry
	 * to a transfer payload.
	 *
	 * @param array $transferData The transfer payload being completed
	 * @param array $auditTrail The pre-existing custody chain, read before the status writes
	 * @param string $targetStatus 'accepted' or 'rejected'
	 * @param string $rejectionReason Reason text (rejected only)
	 * @param string $actorType 'local' or 'remote'
	 * @param string|null $remoteCloudId Set for a federated (remote-authenticated) call
	 * @param string $now The ISO 8601 completion timestamp
	 *
	 * @return array The completed transfer payload
	 */
	private function applyTransferCompletion(
		array $transferData,
		array $auditTrail,
		string $targetStatus,
		string $rejectionReason,
		string $actorType,
		?string $remoteCloudId,
		string $now,
	): array {
		$transferData['status'] = $targetStatus;
		$transferData['completedAt'] = $now;
		if ($targetStatus === 'rejected') {
			$transferData['rejectionReason'] = $rejectionReason;
		}

		$auditTrail[] = [
			'event' => $targetStatus,
			'actor' => ($remoteCloudId ?? ''),
			'actorType' => $actorType,
			'cloudId' => ($remoteCloudId ?? ''),
			'timestamp' => $now,
		];
		$transferData['custodyAuditTrail'] = $auditTrail;

		return $transferData;
	}//end applyTransferCompletion()

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
	public function getCaseIdForTransfer(string $transferId): ?string {
		$objectService = $this->gateway->objectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_transfer_schema');
		if (empty($register) === true || empty($schema) === true) {
			return null;
		}

		try {
			$transfer = $objectService->find($transferId, register: (int)$register, schema: (int)$schema);
			if ($transfer === null) {
				return null;
			}

			$transferData = (array)$transfer;
			if (is_object($transfer) === true) {
				$transferData = $transfer->jsonSerialize();
			}

			if (isset($transferData['caseId']) === true) {
				return (string)$transferData['caseId'];
			}

			return null;
		} catch (\Throwable $e) {
			$this->logger->debug(
				'CaseTransferService: getCaseIdForTransfer failed',
				['transferId' => $transferId, 'exception' => $e->getMessage()]
			);
			return null;
		}//end try
	}//end getCaseIdForTransfer()

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
	public function resolveFederatedTransferShare(string $shareToken, string $transferId): ?array {
		return $this->shareBroker->resolveTransferShare(shareToken: $shareToken, transferId: $transferId);
	}//end resolveFederatedTransferShare()

	/**
	 * Find an existing transfer by idempotency key (pending or accepted
	 * only — a rejected transfer does not block re-initiating).
	 *
	 * @param string $idempotencyKey The sha256 idempotency key
	 * @param int $register The register id
	 * @param int $schema The schema id
	 * @param object $objectService The resolved OR ObjectService
	 *
	 * @return array|null The existing transfer data, or null when none found
	 */
	private function findTransferByIdempotencyKey(string $idempotencyKey, int $register, int $schema, object $objectService): ?array {
		try {
			$matches = $objectService->findAll(
				['filters' => ['register' => $register, 'schema' => $schema, 'idempotencyKey' => $idempotencyKey]],
			);
		} catch (\Throwable $e) {
			return null;
		}

		foreach ((array)$matches as $match) {
			$matchData = $match;
			if (is_array($match) === false) {
				$matchData = $match->jsonSerialize();
			}

			$status = (string)($matchData['status'] ?? '');
			if ($status === 'pending' || $status === 'accepted') {
				return $matchData;
			}
		}

		return null;
	}//end findTransferByIdempotencyKey()
}//end class
