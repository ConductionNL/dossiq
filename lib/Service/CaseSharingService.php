<?php

/**
 * Procest Case Sharing Service
 *
 * Service for managing case shares, token generation, and permission enforcement.
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
 * Service for managing case sharing with external parties.
 *
 * Handles token-based sharing, partner organization sharing,
 * permission enforcement, and field-level data filtering.
 *
 * @spec openspec/specs/federated-case-collaboration/spec.md
 */
class CaseSharingService
{
    /**
     * Hard-coded allow-list of case-summary fields that may ever cross a
     * federation boundary. A field NOT in this list is rejected outright by
     * {@see createFederatedShare()} — never silently dropped. `@self` and
     * `relations` are deliberately never included: the fleet lesson is that
     * a relations mirror can leak writeOnly fields, so it is excluded by
     * construction rather than filtered after the fact.
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
     * @param SettingsService         $settingsService   The settings service
     * @param IAppManager             $appManager        The app manager
     * @param ContainerInterface      $container         The DI container
     * @param LoggerInterface         $logger            The logger
     * @param TenantAuditTrailService $auditTrailService Audit-trail emitter for cross-org actions
     *
     * @return void
     */
    public function __construct(
        private SettingsService $settingsService,
        private IAppManager $appManager,
        private ContainerInterface $container,
        private LoggerInterface $logger,
        private TenantAuditTrailService $auditTrailService,
    ) {
    }//end __construct()

    /**
     * Check whether a given user may access a case for sharing purposes.
     *
     * A user is permitted when any of the following holds:
     *  - the case's `assignee` field equals the user ID
     *  - the user ID appears in `assignees` (array)
     *  - the user ID appears as a `createdBy` on any caseShare linked to the case
     *  - the caller is an NC admin (checked via group membership `admin`)
     *
     * Returns true when the case cannot be loaded (fail-safe for missing OR
     * config) to avoid breaking installations that have not configured the
     * case schema. The caller must still authenticate via IUserSession.
     *
     * @param string $caseId The case UUID
     * @param string $userId The caller's user ID
     *
     * @return bool True when the user may proceed
     *
     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function canUserAccessCase(string $caseId, string $userId): bool
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            // OR not available — fail-open so the feature still works on basic setups.
            return true;
        }

        $register   = $this->settingsService->getConfigValue('register');
        $caseSchema = $this->settingsService->getConfigValue('case_schema');

        if (empty($register) === true || empty($caseSchema) === true) {
            return true;
        }

        try {
            $caseObj = $objectService->find($caseId, register: (int) $register, schema: (int) $caseSchema);
            if ($caseObj === null) {
                // Case not found — deny (treated as 404 by callers).
                return false;
            }

            $caseData = $this->normalizeToArray(value: $caseObj);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'CaseSharingService: canUserAccessCase load failed',
                ['caseId' => $caseId, 'exception' => $e->getMessage()]
            );
            return true;
        }

        // Direct assignee field, then the assignees array.
        if ($this->isCaseAssignee(caseData: $caseData, userId: $userId) === true) {
            return true;
        }

        // Check existing caseShares: if this user created any share for this case they
        // already had access at that time.
        if ($this->hasCreatedShareForCase(
            objectService: $objectService,
            caseId: $caseId,
            userId: $userId,
            register: (int) $register
        ) === true
        ) {
            return true;
        }

        return false;
    }//end canUserAccessCase()

    /**
     * Whether a case names the given user as assignee.
     *
     * Accepts both the single-valued `assignee` field and membership of the
     * `assignees` array; either one grants access.
     *
     * @param array<string, mixed> $caseData The case data
     * @param string               $userId   The caller's user ID
     *
     * @return bool True when the user is an assignee of the case
     */
    private function isCaseAssignee(array $caseData, string $userId): bool
    {
        // Direct assignee field (single user ID string).
        if (isset($caseData['assignee']) === true && (string) $caseData['assignee'] === $userId) {
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
     * @param object $objectService The OpenRegister ObjectService
     * @param string $caseId        The case UUID
     * @param string $userId        The caller's user ID
     * @param int    $register      The configured register id
     *
     * @return bool True when a share created by this user exists
     */
    private function hasCreatedShareForCase(
        object $objectService,
        string $caseId,
        string $userId,
        int $register,
    ): bool {
        $shareSchema = $this->settingsService->getConfigValue('case_share_schema');
        if (empty($shareSchema) === true) {
            return false;
        }

        try {
            $shares = $objectService->findAll(
                ['filters' => ['register' => $register, 'schema' => (int) $shareSchema, 'caseId' => $caseId]],
            );

            foreach ($shares as $share) {
                $shareData = $this->normalizeToArray(value: $share);
                if (isset($shareData['createdBy']) === true && (string) $shareData['createdBy'] === $userId) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug(
                'CaseSharingService: share lookup in canUserAccessCase failed',
                ['caseId' => $caseId, 'exception' => $e->getMessage()]
            );
        }//end try

        return false;
    }//end hasCreatedShareForCase()

    /**
     * Resolve OpenRegister's CaseTokenService — the public "track your
     * case" token-link surface of the shares integration leaf (ADR-022).
     *
     * The leaf owns token generation (256-bit non-guessable handle),
     * expiry, revocation, and the RBAC-respecting public resolve path;
     * procest mints no share tokens of its own.
     *
     * @return object|null The OR CaseTokenService, or null when OR is
     *                     unavailable / pre-foundation build.
     *
     * @spec openspec/changes/migrate-public-share-to-shares-leaf/tasks.md#P1.2
     */
    private function getCaseTokenService(): ?object
    {
        if ($this->appManager->isInstalled('openregister') === false) {
            return null;
        }

        try {
            $service = $this->container->get('OCA\OpenRegister\Service\CaseTokenService');
            if (method_exists($service, 'mint') === false) {
                return null;
            }

            return $service;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'CaseSharingService: OR CaseTokenService unavailable (shares leaf not present)',
                ['exception' => $e->getMessage()]
            );
            return null;
        }
    }//end getCaseTokenService()

    /**
     * Create a public "track your case" token link through OpenRegister's
     * shares integration leaf.
     *
     * The leaf mints a 256-bit token bound to the case object. The token
     * resolves anonymously to a PUBLIC-SAFE view of the case via OR's
     * `#[PublicPage]` resolve endpoint — only the fields the public group
     * may read are returned (the `publicatiedatum<=$now` + public-group
     * predicate), so procest no longer hand-maintains a token store,
     * field-exclusion list, password gate, or brute-force lockout. RBAC
     * is enforced by the OR public read path, not by procest.
     *
     * @param string      $caseId    The UUID of the case to share
     * @param string      $label     Human-readable label for the link
     * @param string      $createdBy User ID of the creator (audit log)
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
        ?string $expiresAt=null,
    ): array {
        $tokenService = $this->getCaseTokenService();
        if ($tokenService === null) {
            return ['error' => 'OpenRegister shares leaf is not available'];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('case_schema');

        $ttlSeconds = null;
        if ($expiresAt !== null) {
            $expiryTs = strtotime((string) $expiresAt);
            if ($expiryTs !== false) {
                $ttlSeconds = max(1, ($expiryTs - time()));
            }
        }

        $registerId = null;
        if (empty($register) === false) {
            $registerId = (int) $register;
        }

        $schemaId = null;
        if (empty($schema) === false) {
            $schemaId = (int) $schema;
        }

        $mintLabel = null;
        if ($label !== '') {
            $mintLabel = $label;
        }

        try {
            // Mint through the leaf — it owns token generation, expiry and
            // the public resolve URL. The minter (createdBy) is recorded by
            // the leaf via the current user session.
            $minted = $tokenService->mint(
                objectUuid: $caseId,
                registerId: $registerId,
                schemaId: $schemaId,
                label: $mintLabel,
                ttlSeconds: $ttlSeconds
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'CaseSharingService: leaf mint failed',
                ['caseId' => $caseId, 'exception' => $e->getMessage()]
            );
            return ['error' => 'Could not create share link'];
        }

        $this->logger->info(
            'Procest: Public case-token link minted via OR shares leaf',
            [
                'caseId'    => $caseId,
                'createdBy' => $createdBy,
                'label'     => $label,
            ]
        );

        return $minted;
    }//end createTokenShare()

    /**
     * Resolve the case (object) a leaf-minted token belongs to.
     *
     * Used by the controller to enforce the per-case owner/handler guard
     * before revoking a public token (ADR-005): the controller looks up
     * which case the token addresses, then checks the caller may access
     * that case. Returns null when the token cannot be matched to any
     * case the candidate caseId owns.
     *
     * @param string $tokenId The leaf token id (numeric) or opaque token.
     * @param string $caseId  The candidate case UUID.
     *
     * @return bool True when the token is one of the case's minted tokens.
     *
     * @spec openspec/changes/migrate-public-share-to-shares-leaf/tasks.md#P1.3
     */
    public function tokenBelongsToCase(string $tokenId, string $caseId): bool
    {
        $tokenService = $this->getCaseTokenService();
        if ($tokenService === null || method_exists($tokenService, 'listForObject') === false) {
            return false;
        }

        try {
            $tokens = $tokenService->listForObject($caseId);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'CaseSharingService: listForObject failed',
                ['caseId' => $caseId, 'exception' => $e->getMessage()]
            );
            return false;
        }

        foreach ((array) $tokens as $token) {
            $candidateId    = (string) ($token['id'] ?? '');
            $candidateToken = (string) ($token['token'] ?? '');
            if ($tokenId !== '' && ($tokenId === $candidateId || $tokenId === $candidateToken)) {
                return true;
            }
        }

        return false;
    }//end tokenBelongsToCase()

    /**
     * Revoke a public "track your case" token link through the OR shares
     * leaf. The caller MUST have already authorised the revoke against the
     * owning case (see {@see tokenBelongsToCase()} + canUserAccessCase()).
     *
     * @param string $tokenId The token id (or the opaque token) minted by
     *                        the leaf.
     *
     * @return bool True when the leaf accepted the revoke.
     *
     * @spec openspec/changes/migrate-public-share-to-shares-leaf/tasks.md#P1.3
     */
    public function revokeTokenShare(string $tokenId): bool
    {
        $tokenService = $this->getCaseTokenService();
        if ($tokenService === null || method_exists($tokenService, 'revoke') === false) {
            return false;
        }

        try {
            $tokenService->revoke($tokenId);
            $this->logger->info(
                'Procest: Public case-token link revoked via OR shares leaf',
                ['tokenId' => $tokenId]
            );
            return true;
        } catch (\Throwable $e) {
            $this->logger->error(
                'CaseSharingService: leaf revoke failed',
                ['tokenId' => $tokenId, 'exception' => $e->getMessage()]
            );
            return false;
        }
    }//end revokeTokenShare()

    /**
     * Create a partner organization-based case share.
     *
     * @param string $caseId          The UUID of the case to share
     * @param string $partnerId       The UUID of the partner organization
     * @param string $permissionLevel The permission level slug
     * @param string $createdBy       User ID of the creator
     *
     * @return array The created share data

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function createPartnerShare(
        string $caseId,
        string $partnerId,
        string $permissionLevel,
        string $createdBy,
    ): array {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return ['error' => 'OpenRegister is not available'];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('case_share_schema');

        // Partner-organisation handover is zaak-domain logic (org-to-org case
        // hand-off), NOT public token sharing — it stays in-app per ADR-022.
        // It carries no public token: the bespoke token mechanism moved to the
        // OR shares leaf (createTokenShare) and is the only public surface.
        $shareData = [
            'caseId'          => $caseId,
            'shareType'       => 'partner',
            'partnerId'       => $partnerId,
            'permissionLevel' => $permissionLevel,
            'createdBy'       => $createdBy,
        ];

        $result = $objectService->saveObject(
            object: $shareData,
            register: (int) $register,
            schema: (int) $schema,
        );

        $this->logger->info(
            'Procest: Partner share created',
            [
                'caseId'    => $caseId,
                'partnerId' => $partnerId,
                'shareId'   => $result->getUuid(),
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
    public function getCaseIdForShare(string $shareId): ?string
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return null;
        }

        $register    = $this->settingsService->getConfigValue('register');
        $shareSchema = $this->settingsService->getConfigValue('case_share_schema');

        if (empty($register) === true || empty($shareSchema) === true) {
            return null;
        }

        try {
            $shareObj = $objectService->find($shareId, register: (int) $register, schema: (int) $shareSchema);
            if ($shareObj === null) {
                return null;
            }

            $shareData = $shareObj;
            if (is_array($shareObj) === false) {
                $shareData = $shareObj->jsonSerialize();
            }

            if (isset($shareData['caseId']) === true) {
                return (string) $shareData['caseId'];
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
     * @param string $userId  The user ID performing the revocation
     *
     * @return array The updated share data

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function revokeShare(string $shareId, string $userId): array
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return ['error' => 'OpenRegister is not available'];
        }

        $register    = $this->settingsService->getConfigValue('register');
        $shareSchema = $this->settingsService->getConfigValue('case_share_schema');

        if (empty($register) === true || empty($shareSchema) === true) {
            return ['error' => 'Service unavailable'];
        }

        $shareObj = $objectService->find($shareId, register: (int) $register, schema: (int) $shareSchema);
        if ($shareObj === null) {
            return ['error' => 'Share not found'];
        }

        $shareData = $shareObj;
        if (is_array($shareObj) === false) {
            $shareData = $shareObj->jsonSerialize();
        }

        $shareData['status']    = 'revoked';
        $shareData['revokedBy'] = $userId;
        $shareData['revokedAt'] = (new DateTime())->format('c');

        $result = $objectService->saveObject(object: $shareData, register: (int) $register, schema: (int) $shareSchema);

        $this->logger->info(
            'Procest: Case share revoked',
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
     * federation leaf (`FederationShareService`).
     *
     * OpenRegister's `scope: object` federated share serves exactly the
     * object it is pointed at — the whole object, no field projection. So
     * rather than sharing the live case, this builds a `caseFederatedShare`
     * object containing only {@see FEDERATION_ALLOWED_FIELDS} fields (and
     * document references actually attached to the case), and shares THAT
     * object with `permissions: 'read'`. The live case is never shared and
     * never mutable by the remote org (see design.md §3 authority model).
     *
     * Fails closed (returns an error, writes nothing) when the OR
     * federation leaf is unavailable — unlike {@see canUserAccessCase()},
     * which fails open for same-instance convenience, there is nothing safe
     * to fall back to once a request is about to cross an org boundary.
     *
     * @param string        $caseId          The UUID of the case to share
     * @param string        $remoteCloudId   The federated target (slug@host)
     * @param array<string> $sharedFields    Requested case field names
     * @param array<string> $sharedDocuments Requested document references
     * @param string        $permissionLevel Permission level slug (informational; the OR grant is always 'read')
     * @param string        $createdBy       User ID of the share creator
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
        $federationService = $this->getFederationShareService();
        $objectService     = $this->getObjectService();
        if ($federationService === null || $objectService === null) {
            return ['error' => 'Federated case sharing requires the OpenRegister federation leaf'];
        }

        $invalidFields = array_diff($sharedFields, self::FEDERATION_ALLOWED_FIELDS);
        if (count($invalidFields) > 0) {
            return ['error' => 'Field(s) not shareable across a federation boundary: '.implode(', ', $invalidFields)];
        }

        $register    = $this->settingsService->getConfigValue('register');
        $caseSchema  = $this->settingsService->getConfigValue('case_schema');
        $shareSchema = $this->settingsService->getConfigValue('case_federated_share_schema');

        if ($this->isFederatedShareConfigured(register: $register, caseSchema: $caseSchema, shareSchema: $shareSchema) === false) {
            return ['error' => 'Federated case sharing is not configured'];
        }

        $caseData = $this->loadCaseForFederatedShare(
            objectService: $objectService,
            caseId: $caseId,
            register: (int) $register,
            caseSchema: (int) $caseSchema
        );
        if ($caseData === null) {
            return ['error' => 'Case not found'];
        }

        // Build the redacted snapshot — allow-listed fields present on the case only.
        $fieldSnapshot = $this->buildFieldSnapshot(caseData: $caseData, sharedFields: $sharedFields);

        // Only document references already attached to the case may cross.
        $caseDocuments    = (array) ($caseData['documents'] ?? []);
        $validDocuments   = array_values(array_intersect($sharedDocuments, $caseDocuments));
        $invalidDocuments = array_diff($sharedDocuments, $caseDocuments);
        if (count($invalidDocuments) > 0) {
            return ['error' => 'Document(s) not attached to this case: '.implode(', ', $invalidDocuments)];
        }

        $shareData = [
            'caseId'          => $caseId,
            'remoteCloudId'   => $remoteCloudId,
            'sharedFields'    => array_values($sharedFields),
            'sharedDocuments' => $validDocuments,
            'fieldSnapshot'   => $fieldSnapshot,
            'permissionLevel' => $permissionLevel,
            'status'          => 'pending',
            'createdBy'       => $createdBy,
        ];

        $result     = $objectService->saveObject(object: $shareData, register: (int) $register, schema: (int) $shareSchema);
        $resultData = $this->normalizeToArray(value: $result);

        $shareUuid = (string) ($resultData['id'] ?? $resultData['uuid'] ?? '');

        $federatedShare = $this->mintOutgoingShare(
            federationService: $federationService,
            caseId: $caseId,
            shareUuid: $shareUuid,
            remoteCloudId: $remoteCloudId,
            register: (string) $register,
            shareSchema: (string) $shareSchema
        );
        if ($federatedShare === null) {
            return ['error' => 'Could not mint the federated share token'];
        }

        $resultData['federationShareId'] = $federatedShare->getId();
        $resultData['status']            = 'active';
        $activated  = $objectService->saveObject(object: $resultData, register: (int) $register, schema: (int) $shareSchema);
        $resultData = $this->normalizeToArray(value: $activated);

        $this->auditTrailService->emit(
            [
                'action'   => 'federated_case_share_created',
                'actor'    => $createdBy,
                'resource' => $caseId,
                'tenantId' => $remoteCloudId,
            ]
        );

        $this->logger->info(
            'Procest: Federated case share created',
            ['caseId' => $caseId, 'remoteCloudId' => $remoteCloudId, 'shareId' => $shareUuid]
        );

        return $resultData;
    }//end createFederatedShare()

    /**
     * Whether every configuration value a federated share needs is present.
     *
     * @param string $register    The configured register id
     * @param string $caseSchema  The configured case schema id
     * @param string $shareSchema The configured federated-share schema id
     *
     * @return bool True when all three are configured
     */
    private function isFederatedShareConfigured(string $register, string $caseSchema, string $shareSchema): bool
    {
        return (empty($register) === false && empty($caseSchema) === false && empty($shareSchema) === false);
    }//end isFederatedShareConfigured()

    /**
     * Load the case a federated share is being built from.
     *
     * Returns null both when the case cannot be loaded and when it does not
     * exist — the caller reports 'Case not found' either way; the load failure
     * is additionally logged here.
     *
     * @param object $objectService The OpenRegister ObjectService
     * @param string $caseId        The UUID of the case to share
     * @param int    $register      The configured register id
     * @param int    $caseSchema    The configured case schema id
     *
     * @return array<string, mixed>|null The case data, or null when unavailable
     */
    private function loadCaseForFederatedShare(
        object $objectService,
        string $caseId,
        int $register,
        int $caseSchema,
    ): ?array {
        try {
            $caseObj = $objectService->find($caseId, register: $register, schema: $caseSchema);
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

        return $this->normalizeToArray(value: $caseObj);
    }//end loadCaseForFederatedShare()

    /**
     * Project the requested fields that are actually present on the case.
     *
     * The caller has already rejected any field outside
     * {@see FEDERATION_ALLOWED_FIELDS}, so this only drops fields the case does
     * not carry.
     *
     * @param array<string, mixed> $caseData     The case data
     * @param array<string>        $sharedFields Requested case field names
     *
     * @return array<string, mixed> The redacted snapshot
     */
    private function buildFieldSnapshot(array $caseData, array $sharedFields): array
    {
        $fieldSnapshot = [];
        foreach ($sharedFields as $field) {
            if (array_key_exists($field, $caseData) === true) {
                $fieldSnapshot[$field] = $caseData[$field];
            }
        }

        return $fieldSnapshot;
    }//end buildFieldSnapshot()

    /**
     * Mint the outgoing OCM share for a persisted snapshot through the OR leaf.
     *
     * The grant is always 'read' — the case-summary share never gives the
     * remote org write access to the case. Returns null (and logs) when the
     * leaf refuses, so the caller can fail closed.
     *
     * @param object $federationService The OR FederationShareService
     * @param string $caseId            The UUID of the case being shared (logging context)
     * @param string $shareUuid         The UUID of the persisted snapshot object
     * @param string $remoteCloudId     The federated target (slug@host)
     * @param string $register          The configured register id
     * @param string $shareSchema       The configured federated-share schema id
     *
     * @return object|null The minted OR federated share, or null on failure
     */
    private function mintOutgoingShare(
        object $federationService,
        string $caseId,
        string $shareUuid,
        string $remoteCloudId,
        string $register,
        string $shareSchema,
    ): ?object {
        try {
            return $federationService->createOutgoingShare(
                params: [
                    'scope'       => 'object',
                    'register'    => $register,
                    'schema'      => $shareSchema,
                    'objectUri'   => $shareUuid,
                    'sharedWith'  => $remoteCloudId,
                    // Always 'read' — the case-summary share never grants
                    // the remote org write access to the case.
                    'permissions' => 'read',
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'CaseSharingService: OR createOutgoingShare failed',
                ['caseId' => $caseId, 'exception' => $e->getMessage()]
            );
            return null;
        }
    }//end mintOutgoingShare()

    /**
     * Revoke a federated case share. Sets the OR `FederatedShare.status` to
     * 'revoked' — the single source of truth every downstream check
     * (OR's own serving endpoint, procest's own token checks) consults, so
     * revocation is immediate everywhere.
     *
     * @param string $shareId The UUID of the caseFederatedShare to revoke
     * @param string $userId  The user ID performing the revocation
     *
     * @return array The updated share data, or an error array
     *
     * @spec openspec/specs/federated-case-collaboration/spec.md#federated-share-revocation-is-immediate-and-single-sourced
     */
    public function revokeFederatedShare(string $shareId, string $userId): array
    {
        $federationService = $this->getFederationShareService();
        $objectService     = $this->getObjectService();
        if ($federationService === null || $objectService === null) {
            return ['error' => 'Federated case sharing requires the OpenRegister federation leaf'];
        }

        $register    = $this->settingsService->getConfigValue('register');
        $shareSchema = $this->settingsService->getConfigValue('case_federated_share_schema');
        if (empty($register) === true || empty($shareSchema) === true) {
            return ['error' => 'Federated case sharing is not configured'];
        }

        $shareObj = $objectService->find($shareId, register: (int) $register, schema: (int) $shareSchema);
        if ($shareObj === null) {
            return ['error' => 'Federated share not found'];
        }

        // NOTE: normalised through a helper deliberately. Hoisting a default
        // assignment inline lets PHPStan narrow $shareData to the empty-array
        // shape of ObjectService::find()'s array branch, which then reports
        // the 'caseId'/'remoteCloudId' reads below as non-existent offsets.
        $shareData = $this->normalizeToArray(value: $shareObj);

        $federationShareId = $shareData['federationShareId'] ?? null;
        if ($federationShareId !== null) {
            try {
                $federationService->setStatus(id: (int) $federationShareId, status: 'revoked');
            } catch (\Throwable $e) {
                $this->logger->error(
                    'CaseSharingService: OR federated-share revoke failed',
                    ['shareId' => $shareId, 'exception' => $e->getMessage()]
                );
                return ['error' => 'Could not revoke the federated share token'];
            }
        }

        $shareData['status']    = 'revoked';
        $shareData['revokedBy'] = $userId;
        $shareData['revokedAt'] = (new DateTime())->format('c');

        $result = $objectService->saveObject(object: $shareData, register: (int) $register, schema: (int) $shareSchema);

        $this->auditTrailService->emit(
            [
                'action'   => 'federated_case_share_revoked',
                'actor'    => $userId,
                'resource' => (string) ($shareData['caseId'] ?? ''),
                'tenantId' => (string) ($shareData['remoteCloudId'] ?? ''),
            ]
        );

        $this->logger->info('Procest: Federated case share revoked', ['shareId' => $shareId, 'revokedBy' => $userId]);

        if (is_array($result) === true) {
            return $result;
        }

        return $result->jsonSerialize();
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
    public function getCaseIdForFederatedShare(string $shareId): ?string
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return null;
        }

        $register    = $this->settingsService->getConfigValue('register');
        $shareSchema = $this->settingsService->getConfigValue('case_federated_share_schema');
        if (empty($register) === true || empty($shareSchema) === true) {
            return null;
        }

        try {
            $shareObj = $objectService->find($shareId, register: (int) $register, schema: (int) $shareSchema);
            if ($shareObj === null) {
                return null;
            }

            $shareData = $shareObj;
            if (is_array($shareObj) === false) {
                $shareData = $shareObj->jsonSerialize();
            }

            if (isset($shareData['caseId']) === true) {
                return (string) $shareData['caseId'];
            }

            return null;
        } catch (\Throwable $e) {
            $this->logger->debug(
                'CaseSharingService: getCaseIdForFederatedShare failed',
                ['shareId' => $shareId, 'exception' => $e->getMessage()]
            );
            return null;
        }//end try
    }//end getCaseIdForFederatedShare()

    /**
     * Resolve OpenRegister's FederationShareService — the leaf that owns
     * OCM token minting, transport and lifecycle status. Returns null (fail
     * closed for federation callers) when OR or its federation classes are
     * unavailable.
     *
     * @return object|null The OR FederationShareService, or null
     */
    private function getFederationShareService(): ?object
    {
        if ($this->appManager->isInstalled('openregister') === false) {
            return null;
        }

        try {
            $service = $this->container->get('OCA\OpenRegister\Service\FederationShareService');
            if (method_exists($service, 'createOutgoingShare') === false || method_exists($service, 'setStatus') === false) {
                return null;
            }

            return $service;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'CaseSharingService: OR FederationShareService unavailable (federation leaf not present)',
                ['exception' => $e->getMessage()]
            );
            return null;
        }
    }//end getFederationShareService()

    /**
     * Normalize an OpenRegister return value (array or ObjectEntity) to an array.
     *
     * @param mixed $value The value returned by the ObjectService
     *
     * @return array<string, mixed> The value as a plain array
     */
    private function normalizeToArray(mixed $value): array
    {
        if (is_array($value) === true) {
            return $value;
        }

        if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
            return (array) $value->jsonSerialize();
        }

        return [];
    }//end normalizeToArray()

    /**
     * Resolve the ObjectService from the DI container.
     *
     * @return object|null The ObjectService, or null when OpenRegister is unavailable
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
                'CaseSharingService: ObjectService unavailable',
                ['exception' => $e->getMessage()]
            );
            return null;
        }
    }//end getObjectService()
}//end class
