<?php

/**
 * Procest Case Transfer Service
 *
 * Service for managing case ownership transfers between organizations.
 *
 * @category Service
 * @package  OCA\Procest\Service
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
 * @link https://procest.nl
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTime;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing case ownership transfers between organizations.
 *
 * Supports initiating, accepting, and rejecting transfer requests
 * with full audit trail and notification support.
 *
 * @spec openspec/specs/federated-case-collaboration/spec.md
 */
class CaseTransferService
{
    /**
     * Constructor for the CaseTransferService.
     *
     * @param SettingsService         $settingsService         The settings service
     * @param IAppManager             $appManager              The app manager
     * @param ContainerInterface      $container               The DI container
     * @param LoggerInterface         $logger                  The logger
     * @param TenantAuditTrailService $tenantAuditTrailService Audit-trail emitter for custody-change actions
     *
     * @return void
     */
    public function __construct(
        private SettingsService $settingsService,
        private IAppManager $appManager,
        private ContainerInterface $container,
        private LoggerInterface $logger,
        private TenantAuditTrailService $tenantAuditTrailService,
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
     * @param string      $caseId             The UUID of the case to transfer
     * @param string      $sourceOrganization The source organization identifier
     * @param string      $targetOrganization The UUID of the target partner organization
     * @param string      $reason             The reason for transfer
     * @param string      $requestedDate      The requested transfer date (ISO 8601)
     * @param string      $initiatedBy        User ID of the initiator (custody audit trail)
     * @param string|null $remoteCloudId      The federated target (slug@host), or null for a local-only transfer
     *
     * @return array The created (or existing, when idempotent) transfer request data

     * @spec openspec/specs/federated-case-collaboration/spec.md#case-transfer-extends-across-federation-with-idempotent-acceptreject-and-a-custody-audit-trail
     */
    public function initiateTransfer(
        string $caseId,
        string $sourceOrganization,
        string $targetOrganization,
        string $reason,
        string $requestedDate,
        string $initiatedBy='',
        ?string $remoteCloudId=null,
    ): array {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return ['error' => 'OpenRegister is not available'];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('case_transfer_schema');

        $idempotencyKey = null;
        if ($remoteCloudId !== null && $remoteCloudId !== '') {
            $federationShareService = $this->getFederationShareService();
            if ($federationShareService === null) {
                return ['error' => 'Federated case transfer requires the OpenRegister federation leaf'];
            }

            $idempotencyKey = hash('sha256', $caseId.'|'.$targetOrganization.'|'.$remoteCloudId);

            $existing = $this->findTransferByIdempotencyKey(
                idempotencyKey: $idempotencyKey,
                register: (int) $register,
                schema: (int) $schema,
                objectService: $objectService,
            );
            if ($existing !== null) {
                return $existing;
            }
        }

        $now = (new DateTime())->format('c');

        $transferData = [
            'caseId'             => $caseId,
            'sourceOrganization' => $sourceOrganization,
            'targetOrganization' => $targetOrganization,
            'reason'             => $reason,
            'requestedDate'      => $requestedDate,
            'status'             => 'pending',
            'initiatedBy'        => $initiatedBy,
            'remoteCloudId'      => $remoteCloudId,
            'idempotencyKey'     => $idempotencyKey,
            'custodyAuditTrail'  => [
                [
                    'event'     => 'initiated',
                    'actor'     => $initiatedBy,
                    'actorType' => 'local',
                    'cloudId'   => '',
                    'timestamp' => $now,
                ],
            ],
        ];

        $result     = $objectService->saveObject(
            object: $transferData,
            register: (int) $register,
            schema: (int) $schema,
        );
        $resultData = $result->jsonSerialize();

        if ($remoteCloudId !== null && $remoteCloudId !== '') {
            $transferUuid = (string) ($resultData['id'] ?? $resultData['uuid'] ?? '');
            $mintedShare  = $this->mintFederatedTransferShare(
                transferUuid: $transferUuid,
                remoteCloudId: $remoteCloudId,
                register: (string) $register,
                schema: (string) $schema,
            );
            if ($mintedShare === null) {
                return ['error' => 'Could not mint the federated transfer token'];
            }

            $resultData['federationShareId'] = $mintedShare->getId();
            $result     = $objectService->saveObject(object: $resultData, register: (int) $register, schema: (int) $schema);
            $resultData = $result->jsonSerialize();
        }

        $this->tenantAuditTrailService->emit(
            [
                'action'   => 'case_transfer_initiated',
                'actor'    => $initiatedBy,
                'resource' => $caseId,
                'tenantId' => (string) ($remoteCloudId ?? $targetOrganization),
            ]
        );

        $this->logger->info(
            'Procest: Case transfer initiated',
            [
                'caseId'     => $caseId,
                'transferId' => $result->getUuid(),
                'target'     => $targetOrganization,
                'federated'  => ($remoteCloudId !== null),
            ]
        );

        return $resultData;
    }//end initiateTransfer()

    /**
     * Accept a pending case transfer request. Idempotent when called again
     * after already reaching 'accepted'; refuses loudly for any other
     * non-pending state (e.g. a prior reject).
     *
     * @param string      $transferId    The UUID of the transfer request
     * @param string|null $remoteCloudId Set when the accept was authenticated via a federated token (custody audit actorType)
     *
     * @return array The updated transfer data, or an error array

     * @spec openspec/specs/federated-case-collaboration/spec.md#case-transfer-extends-across-federation-with-idempotent-acceptreject-and-a-custody-audit-trail
     */
    public function acceptTransfer(string $transferId, ?string $remoteCloudId=null): array
    {
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
     * @param string      $transferId      The UUID of the transfer request
     * @param string      $rejectionReason The reason for rejection
     * @param string|null $remoteCloudId   Set when the reject was authenticated via a federated token
     *
     * @return array The updated transfer data, or an error array

     * @spec openspec/specs/federated-case-collaboration/spec.md#case-transfer-extends-across-federation-with-idempotent-acceptreject-and-a-custody-audit-trail
     */
    public function rejectTransfer(string $transferId, string $rejectionReason, ?string $remoteCloudId=null): array
    {
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
     * @param string      $transferId      The UUID of the transfer
     * @param string      $targetStatus    'accepted' or 'rejected'
     * @param string|null $remoteCloudId   Set for a federated (remote-authenticated) call
     * @param string      $rejectionReason Reason text (rejected only)
     *
     * @return array The updated (or existing, when idempotent) transfer data, or an error array
     */
    private function completeTransfer(
        string $transferId,
        string $targetStatus,
        ?string $remoteCloudId=null,
        string $rejectionReason='',
    ): array {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return ['error' => 'OpenRegister is not available'];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('case_transfer_schema');

        $transfer = $objectService->find($transferId, register: (int) $register, schema: (int) $schema);
        if ($transfer === null) {
            return ['error' => 'Transfer not found'];
        }

        if (is_object($transfer) === true) {
            $transferData = $transfer->jsonSerialize();
        } else {
            $transferData = (array) $transfer;
        }

        $currentStatus = (string) ($transferData['status'] ?? '');
        if ($currentStatus === $targetStatus) {
            // Idempotent replay: same call already applied, return as-is.
            return $transferData;
        }

        if ($currentStatus !== 'pending') {
            // Ambiguous/conflicting state (e.g. accept after reject) — refuse loudly.
            return ['error' => 'Transfer is not in a state that can be '.$targetStatus.' (current status: '.$currentStatus.')'];
        }

        $caseId = (string) ($transferData['caseId'] ?? '');
        $now    = (new DateTime())->format('c');

        // Read the existing custody chain before the status writes below, which
        // is also where the pre-existing trail must be preserved from.
        $auditTrail = (array) ($transferData['custodyAuditTrail'] ?? []);

        $transferData['status']      = $targetStatus;
        $transferData['completedAt'] = $now;
        if ($targetStatus === 'rejected') {
            $transferData['rejectionReason'] = $rejectionReason;
        }

        if ($remoteCloudId !== null) {
            $actorType = 'remote';
        } else {
            $actorType = 'local';
        }

        $auditTrail[] = [
            'event'     => $targetStatus,
            'actor'     => ($remoteCloudId ?? ''),
            'actorType' => $actorType,
            'cloudId'   => ($remoteCloudId ?? ''),
            'timestamp' => $now,
        ];
        $transferData['custodyAuditTrail'] = $auditTrail;

        $result = $objectService->saveObject(
            object: $transferData,
            register: (int) $register,
            schema: (int) $schema,
        );

        $this->tenantAuditTrailService->emit(
            [
                'action'   => 'case_transfer_'.$targetStatus,
                'actor'    => ($remoteCloudId ?? 'local'),
                'role'     => $actorType,
                'resource' => $caseId,
                'tenantId' => ($remoteCloudId ?? ''),
            ]
        );

        $this->logger->info(
            'Procest: Case transfer '.$targetStatus,
            [
                'transferId'    => $transferId,
                'caseId'        => $caseId,
                'remoteCloudId' => $remoteCloudId,
            ]
        );

        if (is_array($result) === true) {
            return $result;
        }

        return $result->jsonSerialize();
    }//end completeTransfer()

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
    public function getCaseIdForTransfer(string $transferId): ?string
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return null;
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('case_transfer_schema');
        if (empty($register) === true || empty($schema) === true) {
            return null;
        }

        try {
            $transfer = $objectService->find($transferId, register: (int) $register, schema: (int) $schema);
            if ($transfer === null) {
                return null;
            }

            if (is_object($transfer) === true) {
                $transferData = $transfer->jsonSerialize();
            } else {
                $transferData = (array) $transfer;
            }

            if (isset($transferData['caseId']) === true) {
                return (string) $transferData['caseId'];
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
    public function resolveFederatedTransferShare(string $shareToken, string $transferId): ?array
    {
        $shareMapper = $this->getFederatedShareMapper();
        if ($shareMapper === null || $shareToken === '' || $transferId === '') {
            return null;
        }

        try {
            $share = $shareMapper->findByToken($shareToken);
        } catch (\Throwable $e) {
            return null;
        }

        if ($share->getDirection() !== 'outgoing') {
            return null;
        }

        if (in_array($share->getStatus(), ['revoked', 'declined'], true) === true) {
            return null;
        }

        if ($share->getPermissions() !== 'read-write') {
            return null;
        }

        $objectUri = (string) $share->getObjectUri();
        if ($this->uuidFromUri(uri: $objectUri) !== $transferId) {
            return null;
        }

        return [
            'sharedWith'   => (string) $share->getSharedWith(),
            'organisation' => $share->getOrganisation(),
        ];
    }//end resolveFederatedTransferShare()

    /**
     * Find an existing transfer by idempotency key (pending or accepted
     * only — a rejected transfer does not block re-initiating).
     *
     * @param string $idempotencyKey The sha256 idempotency key
     * @param int    $register       The register id
     * @param int    $schema         The schema id
     * @param object $objectService  The resolved OR ObjectService
     *
     * @return array|null The existing transfer data, or null when none found
     */
    private function findTransferByIdempotencyKey(string $idempotencyKey, int $register, int $schema, object $objectService): ?array
    {
        try {
            $matches = $objectService->findAll(
                ['filters' => ['register' => $register, 'schema' => $schema, 'idempotencyKey' => $idempotencyKey]],
            );
        } catch (\Throwable $e) {
            return null;
        }

        foreach ((array) $matches as $match) {
            if (is_array($match) === true) {
                $matchData = $match;
            } else {
                $matchData = $match->jsonSerialize();
            }

            $status = (string) ($matchData['status'] ?? '');
            if ($status === 'pending' || $status === 'accepted') {
                return $matchData;
            }
        }

        return null;
    }//end findTransferByIdempotencyKey()

    /**
     * Mint a transfer-scoped OR federated share (read-write, pointed only
     * at the transfer object) so the remote org can later authenticate its
     * accept/reject call. Distinct from the case-summary share's token —
     * this one grants no access to the case itself, only to this one
     * transfer's status field via procest's own state machine.
     *
     * @param string $transferUuid  The transfer object's uuid
     * @param string $remoteCloudId The federated target (slug@host)
     * @param string $register      The register id/slug
     * @param string $schema        The case_transfer_schema id/slug
     *
     * @return object|null The minted OR FederatedShare, or null on failure
     */
    private function mintFederatedTransferShare(string $transferUuid, string $remoteCloudId, string $register, string $schema): ?object
    {
        $federationShareService = $this->getFederationShareService();
        if ($federationShareService === null) {
            return null;
        }

        try {
            return $federationShareService->createOutgoingShare(
                params: [
                    'scope'       => 'object',
                    'register'    => $register,
                    'schema'      => $schema,
                    'objectUri'   => $transferUuid,
                    'sharedWith'  => $remoteCloudId,
                    'permissions' => 'read-write',
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'CaseTransferService: OR createOutgoingShare failed',
                ['transferUuid' => $transferUuid, 'exception' => $e->getMessage()]
            );
            return null;
        }
    }//end mintFederatedTransferShare()

    /**
     * Extract the trailing uuid from a canonical object uri (or return it
     * as-is when it is already a bare uuid).
     *
     * @param string $uri The object uri or uuid
     *
     * @return string The uuid
     */
    private function uuidFromUri(string $uri): string
    {
        $parts = explode('/', rtrim($uri, '/'));
        return (string) end($parts);
    }//end uuidFromUri()

    /**
     * Resolve OpenRegister's FederationShareService. Returns null (fail
     * closed) when OR or its federation classes are unavailable.
     *
     * @return object|null The OR FederationShareService, or null
     */
    private function getFederationShareService(): ?object
    {
        if (in_array('openregister', $this->appManager->getInstalledApps()) === false) {
            return null;
        }

        try {
            $service = $this->container->get('OCA\OpenRegister\Service\FederationShareService');
            if (method_exists($service, 'createOutgoingShare') === false) {
                return null;
            }

            return $service;
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: Could not get OR FederationShareService',
                ['exception' => $e->getMessage()]
            );
            return null;
        }
    }//end getFederationShareService()

    /**
     * Resolve OpenRegister's FederatedShareMapper — used only to resolve a
     * scoped bearer token to its share (`findByToken`), for the remote
     * accept/reject endpoint. Returns null (fail closed) when unavailable.
     *
     * @return object|null The OR FederatedShareMapper, or null
     */
    private function getFederatedShareMapper(): ?object
    {
        if (in_array('openregister', $this->appManager->getInstalledApps()) === false) {
            return null;
        }

        try {
            $mapper = $this->container->get('OCA\OpenRegister\Db\FederatedShareMapper');
            if (method_exists($mapper, 'findByToken') === false) {
                return null;
            }

            return $mapper;
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: Could not get OR FederatedShareMapper',
                ['exception' => $e->getMessage()]
            );
            return null;
        }
    }//end getFederatedShareMapper()

    /**
     * Get the OpenRegister ObjectService.
     *
     * Pre-existing gap fixed alongside the federation extension: this
     * previously declared a concrete `?\OCA\OpenRegister\Service\ObjectService`
     * return type. That class is not part of this app's autoload map (OR is
     * a separate app, resolved only through the DI container at runtime), so
     * the type declaration was unenforceable and would TypeError the moment
     * any test exercised this method with a test double — which none did
     * before this change, since CaseTransferService had zero prior test
     * coverage. `?object` matches the pattern already used everywhere else
     * in this file (getFederationShareService/getFederatedShareMapper) and
     * in CaseSharingService's own getObjectService().
     *
     * @return object|null The service or null
     */
    private function getObjectService(): ?object
    {
        if (in_array('openregister', $this->appManager->getInstalledApps()) === false) {
            return null;
        }

        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Exception $e) {
            $this->logger->error(
                'Procest: Could not get ObjectService',
                ['exception' => $e->getMessage()]
            );
            return null;
        }
    }//end getObjectService()
}//end class
