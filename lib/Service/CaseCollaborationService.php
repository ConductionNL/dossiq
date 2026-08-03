<?php

/**
 * Procest Case Federated Collaboration Service
 *
 * Async, append-only collaboration activity stream scoped to one federated
 * case share, postable by both the owning org (local session) and the
 * remote org (the share's scoped bearer token). Real-time co-editing is
 * explicitly out of scope — see design.md §6.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://procest.nl
 *
 * @spec openspec/specs/federated-case-collaboration/spec.md#shared-activity-stream-is-async-append-only-scoped-to-one-federated-share
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTime;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Post/list collaboration-activity entries on a federated case share.
 *
 * @spec openspec/specs/federated-case-collaboration/spec.md
 */
class CaseCollaborationService
{
    /**
     * Constructor.
     *
     * @param SettingsService         $settingsService  The settings service
     * @param IAppManager             $appManager       The app manager
     * @param ContainerInterface      $container        The DI container
     * @param LoggerInterface         $logger           The logger
     * @param TenantAuditTrailService $tenantAuditTrail Audit-trail emitter
     *
     * @return void
     */
    public function __construct(
        private SettingsService $settingsService,
        private IAppManager $appManager,
        private ContainerInterface $container,
        private LoggerInterface $logger,
        private TenantAuditTrailService $tenantAuditTrail,
    ) {
    }//end __construct()

    /**
     * Post a local (session-authenticated) activity entry. The caller MUST
     * already have authorised the request against the share's caseId
     * (ADR-005) before calling this — this method trusts $actorUserId.
     *
     * @param string $federatedShareId The caseFederatedShare UUID
     * @param string $actorUserId      The posting user's NC user id
     * @param string $message          The activity message
     *
     * @return array The updated activity stream, or an error array
     *
     * @spec openspec/specs/federated-case-collaboration/spec.md#a-local-handler-posts-an-activity-entry
     */
    public function postLocalActivity(string $federatedShareId, string $actorUserId, string $message): array
    {
        return $this->appendEntry(
            federatedShareId: $federatedShareId,
            entry: [
                'actor'     => $actorUserId,
                'actorType' => 'local',
                'cloudId'   => '',
                'message'   => $message,
                'createdAt' => (new DateTime())->format('c'),
            ],
        );
    }//end postLocalActivity()

    /**
     * Post a remote activity entry, authenticated via the federated share's
     * scoped bearer token. Fails closed on any resolution mismatch: unknown
     * token, revoked/declined share, wrong direction, or a token minted for
     * a DIFFERENT federated share.
     *
     * @param string $shareToken       The scoped bearer token
     * @param string $federatedShareId The caseFederatedShare UUID the caller claims to post to
     * @param string $message          The activity message
     *
     * @return array The updated activity stream, or an error array
     *
     * @spec openspec/specs/federated-case-collaboration/spec.md#a-remote-org-posts-an-activity-entry-via-its-scoped-token
     */
    public function postRemoteActivity(string $shareToken, string $federatedShareId, string $message): array
    {
        $resolved = $this->resolveRemoteToken(shareToken: $shareToken, federatedShareId: $federatedShareId);
        if ($resolved === null) {
            return ['error' => 'Invalid, unauthorized or revoked federated share token'];
        }

        return $this->appendEntry(
            federatedShareId: $federatedShareId,
            entry: [
                'actor'     => $resolved['sharedWith'],
                'actorType' => 'remote',
                'cloudId'   => $resolved['sharedWith'],
                'message'   => $message,
                'createdAt' => (new DateTime())->format('c'),
            ],
        );
    }//end postRemoteActivity()

    /**
     * List the activity entries for a federated share (local, session
     * already authorised by the controller).
     *
     * @param string $federatedShareId The caseFederatedShare UUID
     *
     * @return array The activity entries (empty array when none/unavailable)
     *
     * @spec openspec/specs/federated-case-collaboration/spec.md#shared-activity-stream-is-async-append-only-scoped-to-one-federated-share
     */
    public function listActivity(string $federatedShareId): array
    {
        $activity = $this->findActivityObject(federatedShareId: $federatedShareId);
        if ($activity === null) {
            return [];
        }

        return (array) ($activity['entries'] ?? []);
    }//end listActivity()

    /**
     * List the activity entries for a federated share via a remote bearer
     * token (same resolution guard as {@see postRemoteActivity()}).
     *
     * @param string $shareToken       The scoped bearer token
     * @param string $federatedShareId The caseFederatedShare UUID
     *
     * @return array The activity entries, or an error array
     *
     * @spec openspec/specs/federated-case-collaboration/spec.md#shared-activity-stream-is-async-append-only-scoped-to-one-federated-share
     */
    public function listRemoteActivity(string $shareToken, string $federatedShareId): array
    {
        $resolved = $this->resolveRemoteToken(shareToken: $shareToken, federatedShareId: $federatedShareId);
        if ($resolved === null) {
            return ['error' => 'Invalid, unauthorized or revoked federated share token'];
        }

        return ['entries' => $this->listActivity(federatedShareId: $federatedShareId)];
    }//end listRemoteActivity()

    /**
     * Append one entry to a federated share's activity stream, creating the
     * `caseFederatedActivity` object on first use. Append-only: existing
     * entries are never rewritten.
     *
     * @param string               $federatedShareId The caseFederatedShare UUID
     * @param array<string, mixed> $entry            The entry to append
     *
     * @return array The updated activity object data, or an error array
     */
    private function appendEntry(string $federatedShareId, array $entry): array
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return ['error' => 'Federated case collaboration requires the OpenRegister federation leaf'];
        }

        $register       = $this->settingsService->getConfigValue('register');
        $shareSchema    = $this->settingsService->getConfigValue('case_federated_share_schema');
        $activitySchema = $this->settingsService->getConfigValue('case_federated_activity_schema');
        if (empty($register) === true || empty($shareSchema) === true || empty($activitySchema) === true) {
            return ['error' => 'Federated case collaboration is not configured'];
        }

        $shareObj = $objectService->find($federatedShareId, register: (int) $register, schema: (int) $shareSchema);
        if ($shareObj === null) {
            return ['error' => 'Federated share not found'];
        }

        $shareData = $this->asArray(value: $shareObj);

        if (($shareData['status'] ?? '') === 'revoked') {
            return ['error' => 'This federated share has been revoked'];
        }

        $activity = $this->findActivityObject(federatedShareId: $federatedShareId);
        if ($activity === null) {
            $activity = [
                'federatedShareId' => $federatedShareId,
                'caseId'           => (string) ($shareData['caseId'] ?? ''),
                'entries'          => [],
            ];
        }

        $entries   = (array) ($activity['entries'] ?? []);
        $entries[] = $entry;
        $activity['entries']        = $entries;
        $activity['lastActivityAt'] = $entry['createdAt'];

        $result     = $objectService->saveObject(object: $activity, register: (int) $register, schema: (int) $activitySchema);
        $resultData = $this->asArray(value: $result);

        $this->tenantAuditTrail->emit(
            [
                'action'   => 'federated_case_activity_posted',
                'actor'    => $entry['actor'],
                'role'     => $entry['actorType'],
                'resource' => (string) ($activity['caseId'] ?? ''),
                'tenantId' => (string) ($entry['cloudId'] ?? ''),
            ]
        );

        return $resultData;
    }//end appendEntry()

    /**
     * Normalise an OpenRegister return value to its array form — OR hands back
     * either a plain array or a JsonSerializable entity depending on the call.
     *
     * @param mixed $value The array or JsonSerializable entity to normalise
     *
     * @return array The array form of the value
     */
    private function asArray(mixed $value): array
    {
        if (is_array($value) === true) {
            return $value;
        }

        return $value->jsonSerialize();
    }//end asArray()

    /**
     * Find the caseFederatedActivity object for a share, if any.
     *
     * @param string $federatedShareId The caseFederatedShare UUID
     *
     * @return array|null The activity object data, or null when none exists yet
     */
    private function findActivityObject(string $federatedShareId): ?array
    {
        $objectService  = $this->getObjectService();
        $register       = $this->settingsService->getConfigValue('register');
        $activitySchema = $this->settingsService->getConfigValue('case_federated_activity_schema');
        if ($objectService === null || empty($register) === true || empty($activitySchema) === true) {
            return null;
        }

        try {
            $matches = $objectService->findAll(
                ['filters' => ['register' => (int) $register, 'schema' => (int) $activitySchema, 'federatedShareId' => $federatedShareId]],
            );
        } catch (\Throwable $e) {
            return null;
        }

        foreach ((array) $matches as $match) {
            if (is_array($match) === true) {
                return $match;
            }

            return $match->jsonSerialize();
        }

        return null;
    }//end findActivityObject()

    /**
     * Resolve a remote bearer token against a claimed federated share id.
     * Requires an OUTGOING, non-revoked/declined OR FederatedShare whose
     * objectUri tail matches the claimed caseFederatedShare uuid exactly —
     * a token minted for a different share can never post/read here.
     *
     * Permissions ('read' vs 'read-write') are deliberately NOT checked:
     * the collaboration activity stream is a bounded side-channel distinct
     * from case-object access (design.md §3) — a read-only case-summary
     * share still lets its remote counterpart participate in the async
     * activity stream.
     *
     * @param string $shareToken       The scoped bearer token
     * @param string $federatedShareId The claimed caseFederatedShare uuid
     *
     * @return array{sharedWith: string}|null The resolved grant, or null when invalid
     */
    private function resolveRemoteToken(string $shareToken, string $federatedShareId): ?array
    {
        $shareMapper = $this->getFederatedShareMapper();
        if ($shareMapper === null || $shareToken === '' || $federatedShareId === '') {
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

        $objectUri = (string) $share->getObjectUri();
        $parts     = explode('/', rtrim($objectUri, '/'));
        $uuid      = (string) end($parts);
        if ($uuid !== $federatedShareId) {
            return null;
        }

        return ['sharedWith' => (string) $share->getSharedWith()];
    }//end resolveRemoteToken()

    /**
     * Resolve OpenRegister's ObjectService.
     *
     * @return object|null The ObjectService, or null when unavailable
     */
    private function getObjectService(): ?object
    {
        if ($this->appManager->isInstalled('openregister') === false) {
            return null;
        }

        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            $this->logger->warning(
                'CaseCollaborationService: ObjectService unavailable',
                ['exception' => $e->getMessage()]
            );
            return null;
        }
    }//end getObjectService()

    /**
     * Resolve OpenRegister's FederatedShareMapper. Returns null (fail
     * closed) when OR or its federation classes are unavailable.
     *
     * @return object|null The OR FederatedShareMapper, or null
     */
    private function getFederatedShareMapper(): ?object
    {
        if ($this->appManager->isInstalled('openregister') === false) {
            return null;
        }

        try {
            $mapper = $this->container->get('OCA\OpenRegister\Db\FederatedShareMapper');
            if (method_exists($mapper, 'findByToken') === false) {
                return null;
            }

            return $mapper;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'CaseCollaborationService: OR FederatedShareMapper unavailable',
                ['exception' => $e->getMessage()]
            );
            return null;
        }
    }//end getFederatedShareMapper()
}//end class
