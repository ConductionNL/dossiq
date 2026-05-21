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
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCP\App\IAppManager;
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
     * Constructor for the CaseSharingService.
     *
     * @param SettingsService    $settingsService The settings service
     * @param IAppManager        $appManager      The app manager
     * @param ContainerInterface $container       The DI container
     * @param LoggerInterface    $logger          The logger
     *
     * @return void
     */
    public function __construct(
        private SettingsService $settingsService,
        private IAppManager $appManager,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Generate a cryptographically secure share token.
     *
     * Generates a 128-bit (16 byte) random token encoded as 32 hex characters.
     *
     * @return string The generated token (32 hex characters)
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

        $token    = $this->generateToken();
        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('case_share_schema');

        $shareData = [
            'token'           => $token,
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

        return $result->jsonSerialize();
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
     * Revoke a share by marking it with revocation timestamp.
     *
     * @param string $shareId   The UUID of the share to revoke
     * @param string $revokedBy The user ID of the revoker
     *
     * @return array The updated share data
     */
    public function revokeShare(string $shareId, string $revokedBy): array
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return ['error' => 'OpenRegister is not available'];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('case_share_schema');

        $share = $objectService->getObject(
            (int) $register,
            (int) $schema,
            $shareId,
        );

        $shareData = $share->jsonSerialize();
        $shareData['revokedAt'] = (new \DateTime())->format('c');
        $shareData['revokedBy'] = $revokedBy;

        $result = $objectService->saveObject(
            (int) $register,
            (int) $schema,
            $shareData,
        );

        $this->logger->info(
            'Procest: Share revoked',
            [
                'shareId'   => $shareId,
                'revokedBy' => $revokedBy,
            ]
        );

        return $result->jsonSerialize();
    }//end revokeShare()

    /**
     * Validate a share token for access.
     *
     * Checks token existence, expiration, revocation, password, and lockout.
     *
     * @param string      $token    The share token to validate
     * @param string|null $password The password attempt (for protected shares)
     *
     * @return array Validation result with 'valid' boolean and share data or error
     */
    public function validateToken(string $token, ?string $password=null): array
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return ['valid' => false, 'error' => 'Service unavailable'];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('case_share_schema');

        $shares = $objectService->findAll(
            ['filters' => ['register' => (int) $register, 'schema' => (int) $schema, 'token' => $token]],
        );
        if (empty($shares) === true) {
            return ['valid' => false, 'error' => 'Token niet gevonden'];
        }

        $share = reset($shares);
        if (is_object($share) === true) {
            $shareData = $share->jsonSerialize();
        } else {
            $shareData = $share;
        }

        // Check if revoked.
        if (empty($shareData['revokedAt']) === false) {
            return ['valid' => false, 'error' => 'Toegang ingetrokken'];
        }

        // Check if expired.
        if (empty($shareData['expiresAt']) === false) {
            $expiresAt = new \DateTime($shareData['expiresAt']);
            if ($expiresAt < new \DateTime()) {
                return [
                    'valid' => false,
                    'error' => 'Deze link is verlopen. Neem contact op met de behandelaar.',
                ];
            }
        }

        // Check lockout.
        if (empty($shareData['lockedUntil']) === false) {
            $lockedUntil = new \DateTime($shareData['lockedUntil']);
            if ($lockedUntil > new \DateTime()) {
                return [
                    'valid' => false,
                    'error' => 'Deze link is tijdelijk vergrendeld. Probeer het later opnieuw.',
                ];
            }
        }

        // Check password if required.
        if (empty($shareData['password']) === false) {
            if ($password === null) {
                return ['valid' => false, 'requiresPassword' => true];
            }

            if (password_verify($password, $shareData['password']) === false) {
                $this->recordFailedAttempt(shareData: $shareData, register: $register, schema: $schema);
                return ['valid' => false, 'error' => 'Onjuist wachtwoord'];
            }

            // Reset failed attempts on successful password.
            $this->resetFailedAttempts(shareData: $shareData, register: $register, schema: $schema);
        }

        // Update last accessed timestamp.
        $shareData['lastAccessedAt'] = (new \DateTime())->format('c');
        $objectService->saveObject(
            (int) $register,
            (int) $schema,
            $shareData,
        );

        return ['valid' => true, 'share' => $shareData];
    }//end validateToken()

    /**
     * Get filtered case data based on share permission level.
     *
     * Applies field exclusions and data minimization rules.
     *
     * @param array $shareData The share record data
     * @param array $caseData  The full case data
     *
     * @return array The filtered case data safe for external viewing
     */
    public function getFilteredCaseData(array $shareData, array $caseData): array
    {
        $fieldExclusions = json_decode(($shareData['fieldExclusions'] ?? '[]'), true);
        if (is_array($fieldExclusions) === false) {
            $fieldExclusions = self::DEFAULT_EXCLUDED_FIELDS;
        }

        // Remove excluded fields entirely (not even null).
        foreach ($fieldExclusions as $field) {
            unset($caseData[$field]);
        }

        // Apply BSN masking for data minimization.
        if (isset($caseData['bsn']) === true) {
            $caseData['bsn'] = $this->maskBsn(bsn: $caseData['bsn']);
        }

        return $caseData;
    }//end getFilteredCaseData()

    /**
     * Mask a BSN number showing only the last 4 digits.
     *
     * @param string $bsn The full BSN number
     *
     * @return string The masked BSN (e.g., "***99*653")
     */
    public function maskBsn(string $bsn): string
    {
        $length = strlen($bsn);
        if ($length <= 4) {
            return $bsn;
        }

        return str_repeat('*', ($length - 4)).substr($bsn, -4);
    }//end maskBsn()

    /**
     * Record a failed password attempt and lock if threshold exceeded.
     *
     * @param array  $shareData The share record data
     * @param string $register  The register ID
     * @param string $schema    The schema ID
     *
     * @return void
     */
    private function recordFailedAttempt(array $shareData, string $register, string $schema): void
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return;
        }

        $shareData['failedAttempts'] = (int) ($shareData['failedAttempts'] ?? 0) + 1;

        if ($shareData['failedAttempts'] >= self::MAX_FAILED_ATTEMPTS) {
            $lockUntil = new \DateTime();
            $lockUntil->modify('+'.self::LOCKOUT_MINUTES.' minutes');
            $shareData['lockedUntil']    = $lockUntil->format('c');
            $shareData['failedAttempts'] = 0;

            $this->logger->warning(
                'Procest: Share token locked after failed attempts',
                ['token' => substr($shareData['token'], 0, 8).'...']
            );
        }

        $objectService->saveObject(
            (int) $register,
            (int) $schema,
            $shareData,
        );
    }//end recordFailedAttempt()

    /**
     * Reset failed password attempts after successful authentication.
     *
     * @param array  $shareData The share record data
     * @param string $register  The register ID
     * @param string $schema    The schema ID
     *
     * @return void
     */
    private function resetFailedAttempts(array $shareData, string $register, string $schema): void
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return;
        }

        $shareData['failedAttempts'] = 0;
        $shareData['lockedUntil']    = null;

        $objectService->saveObject(
            (int) $register,
            (int) $schema,
            $shareData,
        );
    }//end resetFailedAttempts()

    /**
     * Get the OpenRegister ObjectService.
     *
     * @return \OCA\OpenRegister\Service\ObjectService|null The service or null
     */
    private function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService
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

    /**
     * Store a document uploaded via a public share token.
     *
     * Minimal scaffold — full ZGW DRC integration is handled elsewhere.
     *
     * @param string $caseId       The case UUID
     * @param string $shareId      The share record UUID
     * @param array  $uploadedFile The $_FILES entry for the upload
     *
     * @return array Document descriptor
     */
    public function storeExternalDocument(string $caseId, string $shareId, array $uploadedFile): array
    {
        $this->logger->info(
            'Procest: External document upload via share',
            [
                'caseId'  => $caseId,
                'shareId' => $shareId,
                'name'    => ($uploadedFile['name'] ?? 'unknown'),
                'size'    => ($uploadedFile['size'] ?? 0),
            ]
        );

        return [
            'caseId'     => $caseId,
            'shareId'    => $shareId,
            'name'       => ($uploadedFile['name'] ?? 'unknown'),
            'size'       => ($uploadedFile['size'] ?? 0),
            'uploadedAt' => (new \DateTime())->format('c'),
        ];
    }//end storeExternalDocument()
}//end class
