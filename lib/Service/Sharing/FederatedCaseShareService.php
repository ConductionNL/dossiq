<?php

/**
 * Dossiq federated (OCM) case-share service.
 *
 * Shares a purpose-built, field-scoped SNAPSHOT of a case with a remote org
 * over OpenRegister's OCM federation leaf. OpenRegister's `scope: object`
 * federated share serves exactly the object it is pointed at — the whole
 * object, no field projection — so the live case is never the shared object.
 * Instead a `caseFederatedShare` object carrying only allow-listed fields and
 * document references already attached to the case is persisted, and THAT is
 * shared with `permissions: 'read'`. The live case is never shared and never
 * mutable by the remote org (design.md §3, authority model).
 *
 * Split out of CaseSharingService so the org-boundary rules live apart from
 * same-instance sharing. This service fails CLOSED throughout — an unavailable
 * federation leaf, an unconfigured schema, an unshareable field or an
 * unattached document each return an error and write nothing. That is the
 * opposite of CaseAccessPolicy's deliberate fail-open, because there is
 * nothing safe to fall back to once a request crosses an org boundary.
 *
 * Revocation writes the OR `FederatedShare.status`, which is the single source
 * of truth every downstream check consults, so a revoke is immediate
 * everywhere rather than eventually consistent.
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
 * @spec openspec/specs/federated-case-collaboration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Sharing;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TenantAuditTrailService;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use Psr\Log\LoggerInterface;

/**
 * Creates and revokes redacted, field-scoped federated case-share snapshots.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/federated-case-collaboration/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class FederatedCaseShareService {
	/**
	 * Builds the redacted snapshot record from a loaded case.
	 *
	 * @var FederatedShareSnapshot
	 */
	private readonly FederatedShareSnapshot $snapshot;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings service
	 * @param OpenRegisterSharingGateway $gateway OpenRegister resolution for the sharing surface
	 * @param LoggerInterface $logger The logger
	 * @param TenantAuditTrailService $auditTrailService Audit-trail emitter for cross-org actions
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly OpenRegisterSharingGateway $gateway,
		private readonly LoggerInterface $logger,
		private readonly TenantAuditTrailService $auditTrailService,
	) {
		$this->snapshot = new FederatedShareSnapshot();
	}//end __construct()

	/**
	 * Create a federated case share: a purpose-built, field-scoped snapshot
	 * of the case shared with a remote org over OpenRegister's OCM
	 * federation leaf (`FederationShareService`).
	 *
	 * Fails closed (returns an error, writes nothing) when the OR
	 * federation leaf is unavailable.
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
		$setup = $this->shareSetup(sharedFields: $sharedFields);
		if (isset($setup['error']) === true) {
			return $setup;
		}

		$request = new FederatedShareRequest(
			caseId: $caseId,
			remoteCloudId: $remoteCloudId,
			sharedFields: $sharedFields,
			sharedDocuments: $sharedDocuments,
			permissionLevel: $permissionLevel,
			createdBy: $createdBy,
		);

		return $this->shareThrough(setup: $setup, request: $request);
	}//end createFederatedShare()

	/**
	 * The federation service, the share store and the case schema, once the
	 * federation leaf and the schemas are there.
	 *
	 * @param array<string> $sharedFields Requested case field names
	 *
	 * @return array<string, mixed> The federationService, store and caseSchema, or an error array
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#federated-case-share-is-a-redacted-snapshot-never-the-live-case
	 */
	private function shareSetup(array $sharedFields): array {
		$services = $this->federationServices();
		if ($services === null) {
			return ['error' => 'Federated case sharing requires the OpenRegister federation leaf'];
		}

		[$federationService, $objectService] = $services;

		$config = $this->federatedShareConfig(sharedFields: $sharedFields);
		if (isset($config['error']) === true) {
			return $config;
		}

		[$register, $caseSchema, $shareSchema] = $config;

		$store = new FederatedShareStore(objectService: $objectService, register: $register, shareSchema: $shareSchema);

		return ['federationService' => $federationService, 'store' => $store, 'caseSchema' => $caseSchema];
	}//end shareSetup()

	/**
	 * Build the snapshot and publish it to the remote org.
	 *
	 * @param array<string, mixed>  $setup   The federationService, store and caseSchema.
	 * @param FederatedShareRequest $request The share being created.
	 *
	 * @return array The created federated share data, or an error array
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#federated-case-share-is-a-redacted-snapshot-never-the-live-case
	 */
	private function shareThrough(array $setup, FederatedShareRequest $request): array {
		$store = $setup['store'];
		$shareData = $this->buildShareData(request: $request, store: $store, caseSchema: $setup['caseSchema']);
		if (isset($shareData['error']) === true) {
			return $shareData;
		}

		return $this->publishShareSnapshot(
			federationService: $setup['federationService'],
			store: $store,
			shareData: $shareData,
			request: $request,
		);
	}//end shareThrough()

	/**
	 * The OR federation-share and object services, or null when either is unavailable.
	 *
	 * @return array{0: object, 1: object}|null The federation service and the object service
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#federated-case-share-is-a-redacted-snapshot-never-the-live-case
	 */
	private function federationServices(): ?array {
		$federationService = $this->gateway->federationShareService();
		$objectService = $this->gateway->objectService();
		if ($federationService === null || $objectService === null) {
			return null;
		}

		return [$federationService, $objectService];
	}//end federationServices()

	/**
	 * Check the requested fields and read the configured register and schemas.
	 *
	 * @param array<string> $sharedFields Requested case field names
	 *
	 * @return array{0: string, 1: string, 2: string}|array{error: string} The register, case schema
	 *                                                                     and share schema, or an error array
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#federated-case-share-is-a-redacted-snapshot-never-the-live-case
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function federatedShareConfig(array $sharedFields): array {
		$refusal = $this->snapshot->fieldRefusal(sharedFields: $sharedFields);
		if ($refusal !== null) {
			return $refusal;
		}

		$register = $this->settingsService->getConfigValue('register');
		$caseSchema = $this->settingsService->getConfigValue('case_schema');
		$shareSchema = $this->settingsService->getConfigValue('case_federated_share_schema');

		if (empty($register) === true || empty($caseSchema) === true || empty($shareSchema) === true) {
			return ['error' => 'Federated case sharing is not configured'];
		}

		return [$register, $caseSchema, $shareSchema];
	}//end federatedShareConfig()

	/**
	 * Build the redacted snapshot record for a federated case share.
	 *
	 * @param FederatedShareRequest $request What the share was asked to carry
	 * @param FederatedShareStore $store The object service and the register to read the case from
	 * @param string $caseSchema The configured case schema id
	 *
	 * @return array The snapshot record, or an error array
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#federated-case-share-is-a-redacted-snapshot-never-the-live-case
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function buildShareData(FederatedShareRequest $request, FederatedShareStore $store, string $caseSchema): array {
		$caseData = $this->loadCaseForFederatedShare(store: $store, caseId: $request->caseId, caseSchema: (int)$caseSchema);
		if ($caseData === null) {
			return ['error' => 'Case not found'];
		}

		return $this->snapshot->record(request: $request, caseData: $caseData);
	}//end buildShareData()

	/**
	 * Persist the snapshot, mint the OCM share for it and activate it.
	 *
	 * @param object $federationService The OR FederationShareService
	 * @param FederatedShareStore $store Where the snapshot is saved
	 * @param array<string, mixed> $shareData The snapshot record
	 * @param FederatedShareRequest $request What the share was asked to carry
	 *
	 * @return array The created federated share data, or an error array
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#federated-case-share-is-a-redacted-snapshot-never-the-live-case
	 */
	private function publishShareSnapshot(
		object $federationService,
		FederatedShareStore $store,
		array $shareData,
		FederatedShareRequest $request,
	): array {
		$objectService = $store->objectService;
		$register = $store->register;
		$shareSchema = $store->shareSchema;
		$caseId = $request->caseId;
		$remoteCloudId = $request->remoteCloudId;

		$result = $objectService->saveObject(
			object: $shareData,
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: (int)$register, schema: (int)$shareSchema))
		);
		$resultData = $this->gateway->toArray(value: $result);

		$shareUuid = (string)($resultData['id'] ?? $resultData['uuid'] ?? '');

		$federatedShare = $this->mintOutgoingShare(
			federationService: $federationService,
			request: $request,
			shareUuid: $shareUuid,
			store: $store,
		);
		if ($federatedShare === null) {
			return ['error' => 'Could not mint the federated share token'];
		}

		$resultData['federationShareId'] = $federatedShare->getId();
		$resultData['status'] = 'active';
		$activated = $objectService->saveObject(
			object: $resultData,
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: (int)$register, schema: (int)$shareSchema))
		);
		$resultData = $this->gateway->toArray(value: $activated);

		$this->auditTrailService->emit(
			[
				'action' => 'federated_case_share_created',
				'actor' => $request->createdBy,
				'resource' => $caseId,
				'tenantId' => $remoteCloudId,
			]
		);

		$this->logger->info(
			'Dossiq: Federated case share created',
			['caseId' => $caseId, 'remoteCloudId' => $remoteCloudId, 'shareId' => $shareUuid]
		);

		return $resultData;
	}//end publishShareSnapshot()

	/**
	 * Revoke a federated case share. Sets the OR `FederatedShare.status` to
	 * 'revoked' — the single source of truth every downstream check
	 * (OR's own serving endpoint, dossiq's own token checks) consults, so
	 * revocation is immediate everywhere.
	 *
	 * @param string $shareId The UUID of the caseFederatedShare to revoke
	 * @param string $userId The user ID performing the revocation
	 *
	 * @return array The updated share data, or an error array
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#federated-share-revocation-is-immediate-and-single-sourced
	 */
	public function revokeFederatedShare(string $shareId, string $userId): array {
		$loaded = $this->servicesAndShareToRevoke(shareId: $shareId);
		if (isset($loaded['error']) === true) {
			return $loaded;
		}

		[$federationService, $objectService, $register, $shareSchema, $shareData] = $loaded;

		$revokeError = $this->revokeFederationToken(federationService: $federationService, shareData: $shareData, shareId: $shareId);
		if ($revokeError !== null) {
			return $revokeError;
		}

		$store = new FederatedShareStore(objectService: $objectService, register: $register, shareSchema: $shareSchema);

		return $this->storeRevokedShare(store: $store, shareData: $shareData, shareId: $shareId, userId: $userId);
	}//end revokeFederatedShare()

	/**
	 * The federation and object services, and the share to revoke with its register and schema.
	 *
	 * @param string $shareId The UUID of the caseFederatedShare to revoke
	 *
	 * @return array{0: object, 1: object, 2: string, 3: string, 4: array<string, mixed>}|array{error: string} The
	 *         services, register, schema and share, or the error.
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#federated-share-revocation-is-immediate-and-single-sourced
	 */
	private function servicesAndShareToRevoke(string $shareId): array {
		$services = $this->federationServices();
		if ($services === null) {
			return ['error' => 'Federated case sharing requires the OpenRegister federation leaf'];
		}

		[$federationService, $objectService] = $services;

		$loaded = $this->loadShareToRevoke(objectService: $objectService, shareId: $shareId);
		if (isset($loaded['error']) === true) {
			return $loaded;
		}

		return [$federationService, $objectService, ...$loaded];
	}//end servicesAndShareToRevoke()

	/**
	 * Load the federated-share snapshot to revoke, with the register and schema it lives in.
	 *
	 * @param object $objectService The OpenRegister ObjectService
	 * @param string $shareId The UUID of the caseFederatedShare to revoke
	 *
	 * @return array{0: string, 1: string, 2: array<string, mixed>}|array{error: string} The register,
	 *                                                                                   schema and snapshot, or an error array
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#federated-share-revocation-is-immediate-and-single-sourced
	 */
	private function loadShareToRevoke(object $objectService, string $shareId): array {
		$target = $this->shareRegisterAndSchema();
		if ($target === null) {
			return ['error' => 'Federated case sharing is not configured'];
		}

		[$register, $shareSchema] = $target;

		$shareObj = $objectService->find($shareId, new ObjectFindOptions(register: (int)$register, schema: (int)$shareSchema));
		if ($shareObj === null) {
			return ['error' => 'Federated share not found'];
		}

		// NOTE: normalised through a helper deliberately. Hoisting a default
		// assignment inline lets PHPStan narrow $shareData to the empty-array
		// shape of ObjectService::find()'s array branch, which then reports
		// the 'caseId'/'remoteCloudId' reads below as non-existent offsets.
		$shareData = $this->gateway->toArray(value: $shareObj);

		return [$register, $shareSchema, $shareData];
	}//end loadShareToRevoke()

	/**
	 * The configured register and federated-share schema, or null when either is missing.
	 *
	 * @return array{0: string, 1: string}|null The register and schema ids
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#federated-share-revocation-is-immediate-and-single-sourced
	 */
	private function shareRegisterAndSchema(): ?array {
		$register = $this->settingsService->getConfigValue('register');
		$shareSchema = $this->settingsService->getConfigValue('case_federated_share_schema');
		if (empty($register) === true || empty($shareSchema) === true) {
			return null;
		}

		return [$register, $shareSchema];
	}//end shareRegisterAndSchema()

	/**
	 * Revoke the OR federated-share token behind a snapshot, when it has one.
	 *
	 * @param object $federationService The OR FederationShareService
	 * @param array<string, mixed> $shareData The snapshot record
	 * @param string $shareId The UUID of the caseFederatedShare to revoke
	 *
	 * @return array|null An error array when the revoke failed, otherwise null
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#federated-share-revocation-is-immediate-and-single-sourced
	 */
	private function revokeFederationToken(object $federationService, array $shareData, string $shareId): ?array {
		$federationShareId = $shareData['federationShareId'] ?? null;
		if ($federationShareId !== null) {
			try {
				$federationService->setStatus(id: (int)$federationShareId, status: 'revoked');
			} catch (\Throwable $e) {
				$this->logger->error(
					'CaseSharingService: OR federated-share revoke failed',
					['shareId' => $shareId, 'exception' => $e->getMessage()]
				);
				return ['error' => 'Could not revoke the federated share token'];
			}
		}

		return null;
	}//end revokeFederationToken()

	/**
	 * Mark the snapshot revoked, save it and record the revocation.
	 *
	 * @param FederatedShareStore $store Where the snapshot is saved
	 * @param array<string, mixed> $shareData The snapshot record
	 * @param string $shareId The UUID of the caseFederatedShare to revoke
	 * @param string $userId The user ID performing the revocation
	 *
	 * @return array The updated share data
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#federated-share-revocation-is-immediate-and-single-sourced
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function storeRevokedShare(FederatedShareStore $store, array $shareData, string $shareId, string $userId): array {
		$shareData = $this->snapshot->revoked(shareData: $shareData, userId: $userId);

		$result = $store->objectService->saveObject(
			object: $shareData,
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: (int)$store->register, schema: (int)$store->shareSchema))
		);

		$this->auditTrailService->emit(
			[
				'action' => 'federated_case_share_revoked',
				'actor' => $userId,
				'resource' => (string)($shareData['caseId'] ?? ''),
				'tenantId' => (string)($shareData['remoteCloudId'] ?? ''),
			]
		);

		$this->logger->info('Dossiq: Federated case share revoked', ['shareId' => $shareId, 'revokedBy' => $userId]);

		return $this->snapshot->recordOf(saved: $result);
	}//end storeRevokedShare()

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
		$objectService = $this->gateway->objectService();
		if ($objectService === null) {
			return null;
		}

		$target = $this->shareRegisterAndSchema();
		if ($target === null) {
			return null;
		}

		[$register, $shareSchema] = $target;

		try {
			return $this->readShareCaseId(
				store: new FederatedShareStore(objectService: $objectService, register: $register, shareSchema: $shareSchema),
				shareId: $shareId,
			);
		} catch (\Throwable $e) {
			$this->logger->debug(
				'CaseSharingService: getCaseIdForFederatedShare failed',
				['shareId' => $shareId, 'exception' => $e->getMessage()]
			);
			return null;
		}//end try
	}//end getCaseIdForFederatedShare()

	/**
	 * Read the caseId off a stored federated share.
	 *
	 * @param FederatedShareStore $store Where the share is stored
	 * @param string $shareId The federated share UUID
	 *
	 * @return string|null The caseId, or null when not found
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#federated-share-revocation-is-immediate-and-single-sourced
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function readShareCaseId(FederatedShareStore $store, string $shareId): ?string {
		$shareObj = $store->objectService->find($shareId, new ObjectFindOptions(register: (int)$store->register, schema: (int)$store->shareSchema));

		return $this->snapshot->caseIdOf(stored: $shareObj);
	}//end readShareCaseId()

	/**
	 * Load the case a federated share is being built from.
	 *
	 * Returns null both when the case cannot be loaded and when it does not
	 * exist — the caller reports 'Case not found' either way; the load failure
	 * is additionally logged here.
	 *
	 * @param FederatedShareStore $store The object service and the register to read the case from
	 * @param string $caseId The UUID of the case to share
	 * @param int $caseSchema The configured case schema id
	 *
	 * @return array<string, mixed>|null The case data, or null when unavailable
	 */
	private function loadCaseForFederatedShare(FederatedShareStore $store, string $caseId, int $caseSchema): ?array {
		try {
			$caseObj = $store->objectService->find($caseId, new ObjectFindOptions(register: (int)$store->register, schema: $caseSchema));
		} catch (\Throwable $e) {
			$this->logger->warning(
				'CaseSharingService: createFederatedShare case load failed',
				['caseId' => $caseId, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if ($caseObj === null) {
			return null;
		}

		return $this->gateway->toArray(value: $caseObj);
	}//end loadCaseForFederatedShare()

	/**
	 * Mint the outgoing OCM share for a persisted snapshot through the OR leaf.
	 *
	 * The grant is always 'read' — the case-summary share never gives the
	 * remote org write access to the case. Returns null (and logs) when the
	 * leaf refuses, so the caller can fail closed.
	 *
	 * @param object $federationService The OR FederationShareService
	 * @param FederatedShareRequest $request The share request (target, and the case id for logging)
	 * @param string $shareUuid The UUID of the persisted snapshot object
	 * @param FederatedShareStore $store The register and schema the snapshot lives in
	 *
	 * @return object|null The minted OR federated share, or null on failure
	 */
	private function mintOutgoingShare(
		object $federationService,
		FederatedShareRequest $request,
		string $shareUuid,
		FederatedShareStore $store,
	): ?object {
		try {
			return $federationService->createOutgoingShare(
				params: [
					'scope' => 'object',
					'register' => $store->register,
					'schema' => $store->shareSchema,
					'objectUri' => $shareUuid,
					'sharedWith' => $request->remoteCloudId,
					// Always 'read' — the case-summary share never grants
					// the remote org write access to the case.
					'permissions' => 'read',
				]
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CaseSharingService: OR createOutgoingShare failed',
				['caseId' => $request->caseId, 'exception' => $e->getMessage()]
			);
			return null;
		}
	}//end mintOutgoingShare()
}//end class
