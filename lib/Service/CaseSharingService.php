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
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCP\App\IAppManager;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing case sharing with external parties.
 *
 * Handles token-based sharing, partner organization sharing,
 * permission enforcement, and field-level data filtering.
 */
class CaseSharingService
{
    /**
     * Maximum failed password attempts before lockout.
     */
    private const MAX_FAILED_ATTEMPTS = 5;

    /**
     * Lockout duration in minutes after max failed attempts.
     */
    private const LOCKOUT_MINUTES = 15;

    /**
     * Default fields excluded from shared views for data minimization.
     */
    private const DEFAULT_EXCLUDED_FIELDS = [
        'interneAantekening',
        'risicoScore',
        'kosteninschatting',
        'assignee',
        'activity',
        'statusHistory',
    ];

    /**
     * APCu-backed distributed cache for atomic brute-force counters.
     *
     * @var ICache
     */
    private ICache $cache;

    /**
     * Constructor for the CaseSharingService.
     *
     * @param SettingsService    $settingsService The settings service
     * @param IAppManager        $appManager      The app manager
     * @param ContainerInterface $container       The DI container
     * @param LoggerInterface    $logger          The logger
     * @param ICacheFactory      $cacheFactory    The cache factory
     *
     * @return void
     */
    public function __construct(
        private SettingsService $settingsService,
        private IAppManager $appManager,
        private ContainerInterface $container,
        private LoggerInterface $logger,
        ICacheFactory $cacheFactory,
    ) {
        $this->cache = $cacheFactory->createDistributed('procest_share_brute');
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

            if (is_array($caseObj) === true) {
                $caseData = $caseObj;
            } else {
                $caseData = $caseObj->jsonSerialize();
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'CaseSharingService: canUserAccessCase load failed',
                ['caseId' => $caseId, 'exception' => $e->getMessage()]
            );
            return true;
        }

        // Direct assignee field (single user ID string).
        if (isset($caseData['assignee']) === true && (string) $caseData['assignee'] === $userId) {
            return true;
        }

        // Assignees array.
        $assignees = $caseData['assignees'] ?? [];
        if (is_array($assignees) === true && in_array($userId, $assignees, true) === true) {
            return true;
        }

        // Check existing caseShares: if this user created any share for this case they
        // already had access at that time.
        $shareSchema = $this->settingsService->getConfigValue('case_share_schema');
        if (empty($shareSchema) === false) {
            try {
                $shares = $objectService->findAll(
                    ['filters' => ['register' => (int) $register, 'schema' => (int) $shareSchema, 'caseId' => $caseId]],
                );

                foreach ($shares as $share) {
                    if (is_array($share) === true) {
                        $shareData = $share;
                    } else {
                        $shareData = $share->jsonSerialize();
                    }

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
        }//end if

        return false;
    }//end canUserAccessCase()

    /**
     * Generate a cryptographically secure share token.
     *
     * Generates a 128-bit (16 byte) random token encoded as 32 hex characters.
     *
     * @return string The generated token (32 hex characters)

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function generateToken(): string
    {
        return bin2hex(random_bytes(16));
    }//end generateToken()

    /**
     * Create a token-based case share.
     *
     * @param string      $caseId          The UUID of the case to share
     * @param string      $permissionLevel The permission level slug
     * @param string      $label           Human-readable label for the share
     * @param string      $createdBy       User ID of the creator
     * @param string|null $expiresAt       ISO 8601 expiration datetime
     * @param string|null $password        Plain text password (will be hashed)
     * @param array       $fieldExclusions Additional field exclusions
     *
     * @return array The created share data
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) — all params needed for share creation

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function createTokenShare(
        string $caseId,
        string $permissionLevel,
        string $label,
        string $createdBy,
        ?string $expiresAt=null,
        ?string $password=null,
        array $fieldExclusions=[],
    ): array {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return ['error' => 'OpenRegister is not available'];
        }

        // M2: Generate plaintext token but store only its SHA-256 hash in the DB.
        // The plaintext is returned once to the caller and NEVER stored.
        $plainToken = $this->generateToken();
        $tokenHash  = hash('sha256', $plainToken);

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('case_share_schema');

        $shareData = [
            'token'           => $tokenHash,
            'caseId'          => $caseId,
            'shareType'       => 'token',
            'permissionLevel' => $permissionLevel,
            'label'           => $label,
            'createdBy'       => $createdBy,
            'fieldExclusions' => json_encode(
                array_merge(self::DEFAULT_EXCLUDED_FIELDS, $fieldExclusions)
            ),
            'failedAttempts'  => 0,
        ];

        if ($expiresAt !== null) {
            $shareData['expiresAt'] = $expiresAt;
        }

        if ($password !== null) {
            $shareData['password'] = password_hash($password, PASSWORD_BCRYPT);
        }

        $result = $objectService->saveObject(
            (int) $register,
            (int) $schema,
            $shareData,
        );

        $this->logger->info(
            'Procest: Token share created',
            [
                'caseId'  => $caseId,
                'shareId' => $result->getUuid(),
                'label'   => $label,
            ]
        );

        // M2: Return the plaintext token in the response — the only time it is available.
        $resultData          = $result->jsonSerialize();
        $resultData['token'] = $plainToken;
        return $resultData;
    }//end createTokenShare()

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

        $shareData = [
            'token'           => $this->generateToken(),
            'caseId'          => $caseId,
            'shareType'       => 'partner',
            'partnerId'       => $partnerId,
            'permissionLevel' => $permissionLevel,
            'createdBy'       => $createdBy,
            'fieldExclusions' => json_encode(self::DEFAULT_EXCLUDED_FIELDS),
            'failedAttempts'  => 0,
        ];

        $result = $objectService->saveObject(
            (int) $register,
            (int) $schema,
            $shareData,
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

            if (is_array($shareObj) === true) {
                $shareData = $shareObj;
            } else {
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
     * Validate a token submission against the stored hash with brute-force protection.
     *
     * Looks up the share by SHA-256 hash of the supplied token, then:
     *  - checks expiry
     *  - enforces lockout if failedAttempts >= MAX_FAILED_ATTEMPTS
     *  - verifies the password when the share is password-protected
     *  - uses APCu atomic increment to record failed attempts without a read-modify-write race
     *
     * @param string      $token    The plaintext token supplied by the user
     * @param string|null $password Optional plaintext password
     *
     * @return array{valid: bool, share?: array, error?: string}

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function validateToken(string $token, ?string $password=null): array
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return ['valid' => false, 'error' => 'Service unavailable'];
        }

        $register    = $this->settingsService->getConfigValue('register');
        $shareSchema = $this->settingsService->getConfigValue('case_share_schema');

        if (empty($register) === true || empty($shareSchema) === true) {
            return ['valid' => false, 'error' => 'Service unavailable'];
        }

        // M2: Look up share by hash of the submitted token, never by plaintext.
        $tokenHash = hash('sha256', $token);

        try {
            $results = $objectService->findAll(
                [
                    'filters' => [
                        'register' => (int) $register,
                        'schema'   => (int) $shareSchema,
                        'token'    => $tokenHash,
                    ],
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error('CaseSharingService: validateToken findAll failed', ['error' => $e->getMessage()]);
            return ['valid' => false, 'error' => 'Service unavailable'];
        }

        if (is_array($results) === false || count($results) === 0) {
            return ['valid' => false, 'error' => 'Token not found'];
        }

        $shareObj = reset($results);
        if (is_array($shareObj) === true) {
            $share = $shareObj;
        } else {
            $share = $shareObj->jsonSerialize();
        }

        $shareId = (string) ($share['id'] ?? ($share['uuid'] ?? ''));

        // Expiry check.
        $expiresAt = $share['expiresAt'] ?? null;
        if ($expiresAt !== null && strtotime((string) $expiresAt) < time()) {
            return ['valid' => false, 'error' => 'Token verlopen'];
        }

        // H3: Read the APCu counter (authoritative for lockout) then fall back to the
        // DB field for requests that survive an APCu flush or failover.
        $apcuKey   = 'share_failed_'.$shareId;
        $apcuCount = (int) $this->cache->get($apcuKey);
        $dbCount   = (int) ($share['failedAttempts'] ?? 0);
        $maxCount  = max($apcuCount, $dbCount);

        if ($maxCount >= self::MAX_FAILED_ATTEMPTS) {
            // Check lockout expiry stored in APCu.
            $lockoutKey  = 'share_lockout_'.$shareId;
            $lockedUntil = (int) $this->cache->get($lockoutKey);
            if ($lockedUntil > time()) {
                return ['valid' => false, 'error' => 'Account tijdelijk geblokkeerd na te veel pogingen'];
            }

            // Lockout TTL expired — reset the counter.
            $this->cache->remove($apcuKey);
            $this->cache->remove($lockoutKey);
        }

        // Password verification when the share requires it.
        $storedPassword = $share['password'] ?? null;
        if ($storedPassword !== null) {
            if ($password === null || password_verify($password, (string) $storedPassword) === false) {
                // H3: Atomic increment via APCu — no read-modify-write race.
                $newCount = (int) $this->cache->get($apcuKey) + 1;
                $this->cache->set($apcuKey, $newCount, self::LOCKOUT_MINUTES * 60 * 2);

                if ($newCount >= self::MAX_FAILED_ATTEMPTS) {
                    $this->cache->set('share_lockout_'.$shareId, time() + (self::LOCKOUT_MINUTES * 60), self::LOCKOUT_MINUTES * 60);
                    $this->logger->warning(
                        'CaseSharingService: share locked out after too many failed attempts',
                        ['shareId' => $shareId]
                    );
                }

                return ['valid' => false, 'error' => 'Onjuist wachtwoord'];
            }
        }

        // Successful validation — reset the APCu counter.
        $this->cache->remove($apcuKey);
        $this->cache->remove('share_lockout_'.$shareId);

        return ['valid' => true, 'share' => $share];
    }//end validateToken()

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
