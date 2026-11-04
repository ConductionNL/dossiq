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

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseTransfer\TransferStore;
use OCA\Dossiq\Service\Custody\CaseCustodyChain;
use OCA\Dossiq\Service\Custody\CaseTransferConsentGate;
use OCA\Dossiq\Service\Transfer\FederatedIdempotency;
use OCA\Dossiq\Service\Transfer\InternalHandover;
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
	 * @param TransferRegisterGateway $gateway OpenRegister resolution for the transfer surface
	 * @param TransferShareBroker $shareBroker Transfer-scoped OCM token minting and resolution
	 * @param LoggerInterface $logger The logger
	 * @param TenantAuditTrailService $auditTrail Audit-trail emitter for custody-change actions
	 * @param InternalHandover $internal The same act with a team in place of the target organisation
	 * @param CaseCustodyChain $custody The dated chain of holdings every move writes into
	 * @param CaseTransferConsentGate $consent Whether this case may leave the organisation at all
	 * @param FederatedIdempotency $idempotency The key a repeated federated ask is recognised by
	 *
	 * @return void
	 */
	public function __construct(
		private TransferRegisterGateway $gateway,
		private TransferShareBroker $shareBroker,
		private LoggerInterface $logger,
		private TenantAuditTrailService $auditTrail,
		private InternalHandover $internal,
		private CaseCustodyChain $custody,
		private CaseTransferConsentGate $consent,
		private FederatedIdempotency $idempotency,
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

		$request = [
			'caseId' => $caseId,
			'sourceOrganization' => $sourceOrganization,
			'targetOrganization' => $targetOrganization,
			'reason' => $reason,
			'requestedDate' => $requestedDate,
			'initiatedBy' => $initiatedBy,
			'remoteCloudId' => $remoteCloudId,
		];

		// 🔴 THE CONSENT IS A PRECONDITION, NOT A WARNING (D-5). It runs before
		// the idempotency lookup on purpose: a refused hand-off must not leave
		// a pending transfer behind that a later call would happily return as
		// "already initiated".
		$refusal = $this->consentRefusal(request: $request);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->createTransfer(store: $this->store(objectService: $objectService), request: $request);
	}//end initiateTransfer()

	/**
	 * Why consent stops this hand-off, or null when it may go ahead.
	 *
	 * @param array<string, mixed> $request The transfer being initiated.
	 *
	 * @return array<string, mixed>|null The refusal, or null.
	 */
	private function consentRefusal(array $request): ?array {
		$verdict = $this->consent->assess(
			caseId: (string)$request['caseId'],
			sourceOrganisation: (string)$request['sourceOrganization'],
			receivingOrg: (string)$request['targetOrganization'],
			atDate: (string)$request['requestedDate'],
		);
		if ($verdict['allowed'] === false) {
			return ['error' => $verdict['sentence'], 'rule' => $verdict['rule']];
		}

		return null;
	}//end consentRefusal()

	/**
	 * The transfer register and schema, bound to the object service.
	 *
	 * @param object $objectService The OpenRegister object service.
	 *
	 * @return TransferStore
	 */
	private function store(object $objectService): TransferStore {
		[$register, $schema] = $this->gateway->transferScope();

		return new TransferStore(objectService: $objectService, register: (int)$register, schema: (int)$schema);
	}//end store()

	/**
	 * Create the pending transfer, or give the answer a repeated federated ask already has.
	 *
	 * @param TransferStore        $store   The transfer register.
	 * @param array<string, mixed> $request The transfer being initiated.
	 *
	 * @return array<string, mixed> The created (or existing) transfer, or an error.
	 */
	private function createTransfer(TransferStore $store, array $request): array {
		$precheck = $this->idempotency->precheck(
			remoteCloudId: $request['remoteCloudId'],
			caseId: (string)$request['caseId'],
			targetOrganization: (string)$request['targetOrganization'],
			register: $store->register,
			schema: $store->schema,
			objectService: $store->objectService,
		);
		if ($precheck['answer'] !== null) {
			return $precheck['answer'];
		}

		$request['idempotencyKey'] = $precheck['key'];
		$saved = $this->saveTransfer(store: $store, request: $request);
		if ($saved === null) {
			return ['error' => 'Could not mint the federated transfer token'];
		}

		$transfer = $saved->jsonSerialize();
		$this->recordTransferInitiated(result: $saved, request: $request);

		return $transfer;
	}//end createTransfer()

	/**
	 * Save the pending transfer and, for a federated one, the token the remote answers with.
	 *
	 * The token is a transfer-scoped OR federated share (`scope: object`,
	 * `permissions: read-write`) pointed at ONLY this transfer object.
	 *
	 * @param TransferStore        $store   The transfer register.
	 * @param array<string, mixed> $request The transfer being initiated, with its idempotency key.
	 *
	 * @return object|null The saved transfer, or null when the token could not be minted.
	 */
	private function saveTransfer(TransferStore $store, array $request): ?object {
		$saved = $store->save(transfer: $this->pendingTransferFields(request: $request));
		$remoteCloudId = (string)$request['remoteCloudId'];
		if ($remoteCloudId === '') {
			return $saved;
		}

		$transfer = $saved->jsonSerialize();
		$share = $this->shareBroker->mintTransferShare(
			transferUuid: (string)($transfer['id'] ?? $transfer['uuid'] ?? ''),
			remoteCloudId: $remoteCloudId,
			register: (string)$store->register,
			schema: (string)$store->schema,
		);
		if ($share === null) {
			return null;
		}

		$transfer['federationShareId'] = $share->getId();

		return $store->save(transfer: $transfer);
	}//end saveTransfer()

	/**
	 * The pending transfer object with its first custody-audit entry.
	 *
	 * @param array<string, mixed> $request The transfer being initiated, with its idempotency key.
	 *
	 * @return array<string, mixed> The transfer object payload ready to be saved.
	 */
	private function pendingTransferFields(array $request): array {
		return [
			'caseId' => $request['caseId'],
			'sourceOrganization' => $request['sourceOrganization'],
			'targetOrganization' => $request['targetOrganization'],
			'reason' => $request['reason'],
			'requestedDate' => $request['requestedDate'],
			'status' => 'pending',
			'initiatedBy' => $request['initiatedBy'],
			'remoteCloudId' => $request['remoteCloudId'],
			'idempotencyKey' => $request['idempotencyKey'],
			'custodyAuditTrail' => [
				[
					'event' => 'initiated',
					'actor' => $request['initiatedBy'],
					'actorType' => 'local',
					'cloudId' => '',
					'timestamp' => date('c'),
				],
			],
		];
	}//end pendingTransferFields()

	/**
	 * Emit the tenant audit event and log line for a freshly initiated transfer.
	 *
	 * @param object               $result  The saved transfer object.
	 * @param array<string, mixed> $request The transfer that was initiated.
	 *
	 * @return void
	 */
	private function recordTransferInitiated(object $result, array $request): void {
		$this->auditTrail->emit(
			[
				'action' => 'case_transfer_initiated',
				'actor' => $request['initiatedBy'],
				'resource' => $request['caseId'],
				'tenantId' => (string)($request['remoteCloudId'] ?? $request['targetOrganization']),
			]
		);

		$this->logger->info(
			'Dossiq: Case transfer initiated',
			[
				'caseId' => $request['caseId'],
				'transferId' => $result->getUuid(),
				'target' => $request['targetOrganization'],
				'federated' => ($request['remoteCloudId'] !== null),
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
	 * @param string|null $actingUser    The session user who accepts a local transfer; ignored for a federated one
	 *
	 * @return array The updated transfer data, or an error array
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#case-transfer-extends-across-federation-with-idempotent-acceptreject-and-a-custody-audit-trail
	 */
	public function acceptTransfer(string $transferId, ?string $remoteCloudId = null, ?string $actingUser = null): array {
		return $this->completeTransfer(
			outcome: [
				'transferId' => $transferId,
				'status' => 'accepted',
				'remoteCloudId' => $remoteCloudId,
				'actor' => ($remoteCloudId ?? $actingUser ?? ''),
				'rejectionReason' => '',
			],
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
	): array {
		return $this->completeTransfer(
			outcome: [
				'transferId' => $transferId,
				'status' => 'rejected',
				'remoteCloudId' => $remoteCloudId,
				'actor' => ($remoteCloudId ?? $actingUser ?? ''),
				'rejectionReason' => $rejectionReason,
			],
		);
	}//end rejectTransfer()

	/**
	 * Shared accept/reject state machine. Refuses ambiguous transitions
	 * loudly (any non-pending status other than the one already matching
	 * the requested target status), and is idempotent on a repeated call
	 * that already reached the target status.
	 *
	 * @param array<string, mixed> $outcome The transfer id, the status to move to, the remote
	 *                                      that answered (null when local), who answered it
	 *                                      and the rejection reason.
	 *
	 * @return array The updated (or existing, when idempotent) transfer data, or an error array
	 */
	private function completeTransfer(array $outcome): array {
		$objectService = $this->gateway->objectService();
		if ($objectService === null) {
			return ['error' => 'OpenRegister is not available'];
		}

		$store = $this->store(objectService: $objectService);
		$transferData = $this->pendingTransfer(store: $store, outcome: $outcome);
		if (($transferData['status'] ?? '') !== 'pending') {
			// Not found, an idempotent replay, or a conflicting state: whichever
			// it is, `pendingTransfer()` has already shaped the answer.
			return $transferData;
		}

		$transferData = $this->applyTransferCompletion(transferData: $transferData, outcome: $outcome);

		// RECORDED FIRST, THEN APPLIED, the same order InternalHandover uses.
		// A chain written after the status would leave a hole whenever the
		// chain write failed, and a chain with a hole reads as an answer. This
		// way the failure is that the transfer stays pending, which is visible.
		$refusal = $this->custodyRefusal(transferData: $transferData, outcome: $outcome);
		if ($refusal !== null) {
			return $refusal;
		}

		$result = $store->save(transfer: $transferData);
		$this->recordTransferCompleted(transferData: $transferData, outcome: $outcome);

		if (is_array($result) === true) {
			return $result;
		}

		return $result->jsonSerialize();
	}//end completeTransfer()

	/**
	 * The transfer as it stands, or the answer to give when it cannot be completed.
	 *
	 * A transfer already at the target status is an idempotent replay and is
	 * answered as it stands. Any other status is a conflicting state, such as an
	 * accept after a reject, and is refused loudly. The caller tells the three
	 * apart by whether what comes back is still `pending`.
	 *
	 * @param TransferStore        $store   The transfer register.
	 * @param array<string, mixed> $outcome The status being moved to.
	 *
	 * @return array<string, mixed> The pending transfer, or the answer to return.
	 */
	private function pendingTransfer(TransferStore $store, array $outcome): array {
		$transferData = $store->read(transferId: $outcome['transferId']);
		if ($transferData === null) {
			return ['error' => 'Transfer not found'];
		}

		$currentStatus = (string)($transferData['status'] ?? '');
		if ($currentStatus === $outcome['status']) {
			return $transferData;
		}

		if ($currentStatus !== 'pending') {
			return [
				'error' => 'Transfer is not in a state that can be ' . $outcome['status']
					. ' (current status: ' . $currentStatus . ')',
			];
		}

		return $transferData;
	}//end pendingTransfer()

	/**
	 * Hand the case over on the custody chain when it is accepted, or say why it did not move.
	 *
	 * @param array<string, mixed> $transferData The transfer as it stands.
	 * @param array<string, mixed> $outcome      The status being moved to, and who answered.
	 *
	 * @return array<string, mixed>|null The refusal, or null when the case moved or need not move.
	 */
	private function custodyRefusal(array $transferData, array $outcome): ?array {
		if ($outcome['status'] !== 'accepted') {
			return null;
		}

		try {
			$this->custody->move(
				caseId: (string)($transferData['caseId'] ?? ''),
				organisationUnit: (string)($transferData['targetOrganization'] ?? ''),
				handler: '',
				reason: (string)($transferData['reason'] ?? ''),
				movedBy: $outcome['actor'],
			);
		} catch (RefusedException $e) {
			return ['error' => $e->getSentence(), 'rule' => $e->getRule()];
		}

		return null;
	}//end custodyRefusal()

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
	 * to a transfer payload, keeping the custody chain it already had.
	 *
	 * @param array<string, mixed> $transferData The transfer payload being completed.
	 * @param array<string, mixed> $outcome      The status being moved to, and who answered.
	 *
	 * @return array<string, mixed> The completed transfer payload.
	 */
	private function applyTransferCompletion(array $transferData, array $outcome): array {
		$now = date('c');
		$auditTrail = (array)($transferData['custodyAuditTrail'] ?? []);

		$transferData['status'] = $outcome['status'];
		$transferData['completedAt'] = $now;
		if ($outcome['status'] === 'rejected') {
			$transferData['rejectionReason'] = $outcome['rejectionReason'];
		}

		$auditTrail[] = [
			'event' => $outcome['status'],
			'actor' => $outcome['actor'],
			'actorType' => $this->resolveCustodyActorType(remoteCloudId: $outcome['remoteCloudId']),
			'cloudId' => ($outcome['remoteCloudId'] ?? ''),
			'timestamp' => $now,
		];
		$transferData['custodyAuditTrail'] = $auditTrail;

		return $transferData;
	}//end applyTransferCompletion()

	/**
	 * Emit the tenant audit event and log line for an accepted or rejected transfer.
	 *
	 * @param array<string, mixed> $transferData The completed transfer.
	 * @param array<string, mixed> $outcome      The status it moved to, and who answered.
	 *
	 * @return void
	 */
	private function recordTransferCompleted(array $transferData, array $outcome): void {
		$caseId = (string)($transferData['caseId'] ?? '');
		$this->auditTrail->emit(
			[
				'action' => 'case_transfer_' . $outcome['status'],
				'actor' => $outcome['actor'],
				'role' => $this->resolveCustodyActorType(remoteCloudId: $outcome['remoteCloudId']),
				'resource' => $caseId,
				'tenantId' => ($outcome['remoteCloudId'] ?? ''),
			]
		);

		$this->logger->info(
			'Dossiq: Case transfer ' . $outcome['status'],
			[
				'transferId' => $outcome['transferId'],
				'caseId' => $caseId,
				'remoteCloudId' => $outcome['remoteCloudId'],
			]
		);
	}//end recordTransferCompleted()

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

		$store = $this->store(objectService: $objectService);
		if ($store->isConfigured() === false) {
			return null;
		}

		try {
			return $this->caseIdOf(transferData: $store->read(transferId: $transferId));
		} catch (\Throwable $e) {
			$this->logger->debug(
				'CaseTransferService: getCaseIdForTransfer failed',
				['transferId' => $transferId, 'exception' => $e->getMessage()]
			);
			return null;
		}//end try
	}//end getCaseIdForTransfer()

	/**
	 * The case a transfer is for.
	 *
	 * @param array<string, mixed>|null $transferData The transfer, or null when it is not there.
	 *
	 * @return string|null The caseId, or null when the transfer names none.
	 */
	private function caseIdOf(?array $transferData): ?string {
		if (isset($transferData['caseId']) === true) {
			return (string)$transferData['caseId'];
		}

		return null;
	}//end caseIdOf()

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
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-a-case-is-handed-to-another-team-as-a-recorded-act-req-hand-01
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
	): array {
		$record = $this->internal->initiate(
			caseId: $caseId,
			targetTeam: $targetTeam,
			reason: $reason,
			initiatedBy: $initiatedBy,
			doorzending: $doorzending,
		);

		// One door, one chain. The internal handover moves the case on
		// initiate — the receiving team owns it before anybody accepts — so
		// the holding opens here rather than on the answer.
		$this->custody->move(
			caseId: $caseId,
			organisationUnit: $targetTeam,
			handler: '',
			reason: $reason,
			movedBy: $initiatedBy,
		);

		return $record;
	}//end handToTeam()

	/**
	 * Accept a handover on the receiving team's behalf.
	 *
	 * @param string $transferId The handover's uuid.
	 * @param string $acceptedBy Who accepted it.
	 *
	 * @return array The settled record.
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-the-receiving-team-can-refuse-a-handover-back-req-hand-02
	 */
	public function acceptHandover(string $transferId, string $acceptedBy): array {
		return $this->internal->accept(transferId: $transferId, acceptedBy: $acceptedBy);
	}//end acceptHandover()

	/**
	 * Refuse a handover back, with a reason.
	 *
	 * @param string $transferId The handover's uuid.
	 * @param string $reason Why the receiving team will not take it.
	 * @param string $refusedBy Who refused it.
	 *
	 * @return array The settled record.
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-the-receiving-team-can-refuse-a-handover-back-req-hand-02
	 */
	public function refuseHandover(string $transferId, string $reason, string $refusedBy): array {
		return $this->internal->refuse(transferId: $transferId, reason: $reason, refusedBy: $refusedBy);
	}//end refuseHandover()

	/**
	 * The handovers a team sent that nobody has picked up.
	 *
	 * @param string $team The sending team's Nextcloud group id.
	 *
	 * @return array The outstanding handovers.
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-the-receiving-team-can-refuse-a-handover-back-req-hand-02
	 */
	public function outstandingHandovers(string $team): array {
		return $this->internal->outstandingFor(team: $team);
	}//end outstandingHandovers()
}//end class
