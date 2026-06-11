<?php

/**
 * Procest Supplier Auth Service
 *
 * eHerkenning-based authentication for the leverancier portal — validates
 * the KvK claim from the broker, resolves / creates the SupplierUser, and
 * issues a short-lived session token via the chain-member-05 TenantJwtService.
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
 * @spec openspec/changes/leverancier-zaakportaal-02-eherkenning-auth/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Supplier portal authentication.
 */
class SupplierAuthService
{
    /**
     * Session TTL in seconds (2 hours).
     */
    public const SESSION_TTL = 7200;

    /**
     * Refresh window — when remaining TTL ≤ this many seconds, prompt a silent refresh.
     */
    public const SESSION_REFRESH_WINDOW = 900;

    /**
     * Supplier statuses that block login.
     *
     * @var array<int, string>
     */
    private const BLOCKING_STATUSES = ['inactive', 'blacklisted'];

    public function __construct(
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
        private readonly TenantJwtService $jwt,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Decode an eHerkenning code via the broker. In production this calls
     * OpenConnector; in chain-member-02 we expose the broker adapter as a
     * thin contract — `decodeBrokerCode()` — that returns the claim payload.
     *
     * @param string $code Authorisation code.
     *
     * @return array<string,mixed> Decoded eHerkenning claim.
     *
     * @throws RuntimeException When the code cannot be decoded.
     */
    public function authenticateViaEHerkenning(string $code): array
    {
        if ($code === '') {
            throw new InvalidArgumentException('Empty eHerkenning code');
        }

        return $this->decodeBrokerCode($code);
    }

    /**
     * Validate the KvK claim against a known Supplier row.
     *
     * @param string $kvkNumber KvK number from the eHerkenning claim.
     *
     * @return array{ok: bool, supplier?: array<string,mixed>, reason?: string}
     */
    public function validateKvKClaim(string $kvkNumber): array
    {
        if (preg_match('/^[0-9]{6,12}$/', $kvkNumber) !== 1) {
            return ['ok' => false, 'reason' => 'KvK-nummer heeft een ongeldig formaat'];
        }

        $supplier = $this->findSupplierByKvk($kvkNumber);
        if ($supplier === null) {
            return ['ok' => false, 'reason' => 'Onbekende leverancier (KvK-nummer niet geregistreerd)'];
        }

        $status = (string) ($supplier['status'] ?? '');
        if (in_array($status, self::BLOCKING_STATUSES, true) === true) {
            return ['ok' => false, 'reason' => 'Leverancier is '.$status];
        }

        return ['ok' => true, 'supplier' => $supplier];
    }

    /**
     * Create or link a SupplierUser row for an authenticated eHerkenning identity.
     *
     * @param string             $supplierRef Supplier UUID.
     * @param array<string,mixed> $claim       Decoded eHerkenning claim.
     *
     * @return array<string,mixed>|null Persisted SupplierUser.
     */
    public function createOrLinkSupplierUser(string $supplierRef, array $claim): ?array
    {
        $os = $this->getObjectService();
        if ($os === null) {
            return null;
        }

        $email = (string) ($claim['email'] ?? '');
        if ($email === '') {
            return null;
        }

        try {
            $existing = $os->findAll(
                register: TenantSaasService::REGISTER,
                schema: 'supplierUser',
                limit: 1,
                offset: 0,
                filters: ['supplierRef' => $supplierRef, 'email' => $email]
            );
            if (is_array($existing) === true && count($existing) > 0) {
                $row                = $existing[0];
                $row['lastLoginAt'] = (new DateTimeImmutable('now'))->format(DATE_ATOM);
                if (($row['status'] ?? '') !== 'active') {
                    $row['status'] = 'active';
                }

                $uuid = (string) ($row['uuid'] ?? $row['id'] ?? '');
                return $os->saveObject(
                    object: $row,
                    register: TenantSaasService::REGISTER,
                    schema: 'supplierUser',
                    uuid: $uuid !== '' ? $uuid : null
                );
            }
        } catch (Throwable $e) {
            // fall through to create.
        }

        try {
            return $os->saveObject(
                object: [
                    'supplierRef'     => $supplierRef,
                    'email'           => $email,
                    'userRef'         => (string) ($claim['subject'] ?? ''),
                    'role'            => 'read_only',
                    'status'          => 'active',
                    'eherkenningLevel'=> (string) ($claim['eherkenningLevel'] ?? '3'),
                    'addedBy'         => 'eherkenning',
                    'addedAt'         => (new DateTimeImmutable('now'))->format(DATE_ATOM),
                    'lastLoginAt'     => (new DateTimeImmutable('now'))->format(DATE_ATOM),
                ],
                register: TenantSaasService::REGISTER,
                schema: 'supplierUser',
                uuid: null
            );
        } catch (Throwable $e) {
            $this->logger->error('Procest: createOrLinkSupplierUser failed', ['exception' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Issue a portal session token (JWT) with supplier-scoped claims.
     *
     * @param string             $supplierUserId SupplierUser UUID.
     * @param array<string,mixed> $supplier       Supplier row.
     * @param array<string,mixed> $claim          eHerkenning claim.
     * @param bool               $financialReauth Whether financial re-auth is required.
     *
     * @return array{token:string, expiresIn:int, financialReauthRequired:bool}
     */
    public function issueSessionToken(string $supplierUserId, array $supplier, array $claim, bool $financialReauth=false): array
    {
        $roles = ['supplier:'.(string) ($claim['role'] ?? 'read_only')];
        if (isset($claim['eherkenningLevel']) === true) {
            $roles[] = 'eh:level:'.$claim['eherkenningLevel'];
        }

        $token = $this->jwt->createToken(
            subject: $supplierUserId,
            tenantId: (string) ($supplier['tenantRef'] ?? ''),
            tenantSlug: (string) ($supplier['slug'] ?? $supplier['kvkNumber'] ?? ''),
            roles: $roles,
            ttl: self::SESSION_TTL
        );

        return ['token' => $token, 'expiresIn' => self::SESSION_TTL, 'financialReauthRequired' => $financialReauth];
    }

    /**
     * Check whether a session needs silent refresh.
     *
     * @param int $expSeconds Seconds-since-epoch of the JWT `exp` claim.
     *
     * @return bool
     */
    public function needsRefresh(int $expSeconds): bool
    {
        return ($expSeconds - time()) <= self::SESSION_REFRESH_WINDOW;
    }

    /**
     * Stub for the broker call. Production wires this to OpenConnector;
     * in unit tests it's overridden via a subclass.
     *
     * @param string $code Authorisation code.
     *
     * @return array<string,mixed>
     *
     * @throws RuntimeException
     */
    protected function decodeBrokerCode(string $code): array
    {
        throw new RuntimeException('eHerkenning broker not configured — wire via OpenConnector');
    }

    /**
     * Find supplier row by KvK number.
     *
     * @param string $kvkNumber KvK number.
     *
     * @return array<string,mixed>|null
     */
    public function findSupplierByKvk(string $kvkNumber): ?array
    {
        $os = $this->getObjectService();
        if ($os === null) {
            return null;
        }

        try {
            $rows = $os->findAll(
                register: TenantSaasService::REGISTER,
                schema: 'supplier',
                limit: 1,
                offset: 0,
                filters: ['kvkNumber' => $kvkNumber]
            );
            return (is_array($rows) === true && count($rows) > 0) ? $rows[0] : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * @return mixed|null
     */
    private function getObjectService()
    {
        $installed = $this->appManager->getInstalledApps();
        if (is_array($installed) === false || in_array('openregister', $installed, true) === false) {
            return null;
        }

        try {
            return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
        } catch (Throwable $e) {
            return null;
        }
    }
}
