<?php

/**
 * Procest public case-token share service.
 *
 * The "track your case" public link surface. Procest mints NO tokens of its
 * own: every token is minted, listed and revoked through OpenRegister's shares
 * integration leaf (ADR-022), which owns the 256-bit non-guessable handle,
 * expiry, revocation, and the `#[PublicPage]` resolve path that returns only
 * the fields the public group may read. There is no procest-side token store,
 * field-exclusion list, password gate or brute-force lockout to keep in sync.
 *
 * Split out of CaseSharingService so this mode's entire dependency on the leaf
 * — including the "is the leaf even installed?" degradation — sits behind one
 * class, separate from partner hand-off and OCM federation.
 *
 * When the leaf is absent every operation degrades to an error array or false;
 * a missing leaf is never reported as a successful share.
 *
 * @category Service
 * @package  OCA\Procest\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/migrate-public-share-to-shares-leaf/tasks.md#P1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Sharing;

use OCA\Procest\Service\SettingsService;
use Psr\Log\LoggerInterface;

/**
 * Mints, matches and revokes public case-token links via the OR shares leaf.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/migrate-public-share-to-shares-leaf/tasks.md#P1.2
 */
class CaseTokenShareService
{
    /**
     * Constructor.
     *
     * @param SettingsService            $settingsService The settings service
     * @param OpenRegisterSharingGateway $gateway         OpenRegister resolution for the sharing surface
     * @param LoggerInterface            $logger          The logger
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly OpenRegisterSharingGateway $gateway,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

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
        $tokenService = $this->gateway->caseTokenService();
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
        $tokenService = $this->gateway->caseTokenService();
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
        $tokenService = $this->gateway->caseTokenService();
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
}//end class
