<?php

/**
 * Procest Supplier User Management Service
 *
 * Invite / activate / role-change / revoke flow for supplier portal users.
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
 * @spec openspec/changes/leverancier-zaakportaal-03-rbac-user-management/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Supplier-side user management.
 */
class SupplierUserManagementService
{
    /**
     * Allowed roles (mirrors the supplierUser schema enum).
     *
     * @var array<int, string>
     */
    public const ALLOWED_ROLES = ['admin', 'finance', 'contracts', 'sales', 'read_only'];

    /**
     * Role → visible tab matrix.
     *
     * @var array<string, array<int, string>>
     */
    public const ROLE_TAB_MATRIX = [
        'admin'     => ['dashboard', 'profile', 'tenders', 'contracts', 'invoices', 'messages', 'team'],
        'finance'   => ['dashboard', 'profile_limited', 'invoices', 'messages'],
        'contracts' => ['dashboard', 'profile', 'contracts', 'tenders', 'messages'],
        'sales'     => ['dashboard', 'profile', 'tenders', 'messages'],
        'read_only' => ['dashboard', 'profile_limited', 'messages'],
    ];

    /**
     * Activation token TTL in days.
     */
    public const INVITE_TTL_DAYS = 7;

    public function __construct(
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
        private readonly TenantAuditTrailService $auditTrail,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Invite a new supplier user.
     *
     * @param string $supplierRef Supplier UUID.
     * @param string $email       Email address.
     * @param string $role        Role.
     * @param string $invitedBy   Inviter NC user ID.
     *
     * @return array<string,mixed>|null Persisted supplierUser row.
     */
    public function inviteSupplierUser(string $supplierRef, string $email, string $role, string $invitedBy): ?array
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Invalid email');
        }

        $this->assertValidRole($role);

        $os = $this->getObjectService();
        if ($os === null) {
            return null;
        }

        try {
            $row = $os->saveObject(
                object: [
                    'supplierRef'    => $supplierRef,
                    'email'          => $email,
                    'role'           => $role,
                    'status'         => 'invited',
                    'activationToken'=> bin2hex(random_bytes(32)),
                    'addedBy'        => $invitedBy,
                    'addedAt'        => (new DateTimeImmutable('now'))->format(DATE_ATOM),
                ],
                register: TenantSaasService::REGISTER,
                schema: 'supplierUser',
                uuid: null,
            );
            $this->auditTrail->emit([
                'action'   => 'supplier_user.invite',
                'actor'    => $invitedBy,
                'role'     => $role,
                'resource' => 'supplierUser:'.($row['uuid'] ?? ''),
                'tenantId' => $supplierRef,
            ]);
            return $row;
        } catch (Throwable $e) {
            $this->logger->error('Procest: inviteSupplierUser failed', ['exception' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Activate a pending invite. Validates the token's expiry + that the
     * eHerkenning KvK matches the supplier.
     *
     * @param string $token       Activation token.
     * @param string $kvkClaim    KvK number from the eHerkenning callback.
     * @param int    $maxAgeDays  Maximum token age (default 7).
     *
     * @return array{ok: bool, supplierUser?: array<string,mixed>, reason?: string}
     */
    public function activate(string $token, string $kvkClaim, int $maxAgeDays=self::INVITE_TTL_DAYS): array
    {
        $os = $this->getObjectService();
        if ($os === null) {
            return ['ok' => false, 'reason' => 'OpenRegister unavailable'];
        }

        try {
            $rows = $os->findAll(
                register: TenantSaasService::REGISTER,
                schema: 'supplierUser',
                limit: 1,
                offset: 0,
                filters: ['activationToken' => $token]
            );
        } catch (Throwable $e) {
            return ['ok' => false, 'reason' => 'Activation lookup failed'];
        }

        if (is_array($rows) === false || count($rows) === 0) {
            return ['ok' => false, 'reason' => 'Invalid activation token'];
        }

        $user = $rows[0];
        if ($this->isInviteExpired((string) ($user['addedAt'] ?? ''), $maxAgeDays) === true) {
            return ['ok' => false, 'reason' => 'Activation token expired'];
        }

        $supplier = $this->findSupplier((string) ($user['supplierRef'] ?? ''));
        if ($supplier === null) {
            return ['ok' => false, 'reason' => 'Supplier not found'];
        }

        if ((string) ($supplier['kvkNumber'] ?? '') !== $kvkClaim) {
            return ['ok' => false, 'reason' => 'KvK mismatch'];
        }

        $user['status']          = 'active';
        $user['activationToken'] = '';
        $user['lastLoginAt']     = (new DateTimeImmutable('now'))->format(DATE_ATOM);

        try {
            $uuid = (string) ($user['uuid'] ?? $user['id'] ?? '');
            $persisted = $os->saveObject(
                object: $user,
                register: TenantSaasService::REGISTER,
                schema: 'supplierUser',
                uuid: $uuid !== '' ? $uuid : null
            );
            $this->auditTrail->emit([
                'action'   => 'supplier_user.activate',
                'actor'    => (string) ($user['email'] ?? ''),
                'resource' => 'supplierUser:'.($persisted['uuid'] ?? ''),
                'tenantId' => (string) ($user['supplierRef'] ?? ''),
            ]);
            return ['ok' => true, 'supplierUser' => $persisted];
        } catch (Throwable $e) {
            return ['ok' => false, 'reason' => 'Persist failed'];
        }
    }

    /**
     * Update a supplier user's role.
     *
     * @param string $userId  SupplierUser UUID.
     * @param string $newRole New role.
     * @param string $actor   Admin doing the change.
     *
     * @return array<string,mixed>|null
     */
    public function updateRole(string $userId, string $newRole, string $actor): ?array
    {
        $this->assertValidRole($newRole);
        $os = $this->getObjectService();
        if ($os === null) {
            return null;
        }

        try {
            $user = $os->findObject(register: TenantSaasService::REGISTER, schema: 'supplierUser', id: $userId);
        } catch (Throwable $e) {
            return null;
        }

        if (is_array($user) === false) {
            return null;
        }

        $oldRole      = (string) ($user['role'] ?? '');
        $user['role'] = $newRole;
        try {
            $persisted = $os->saveObject(
                object: $user,
                register: TenantSaasService::REGISTER,
                schema: 'supplierUser',
                uuid: $userId
            );
            $this->auditTrail->emit([
                'action'   => 'supplier_user.role_change',
                'actor'    => $actor,
                'role'     => $oldRole.'->'.$newRole,
                'resource' => 'supplierUser:'.$userId,
                'tenantId' => (string) ($user['supplierRef'] ?? ''),
            ]);
            return $persisted;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Revoke a supplier user.
     *
     * @param string $userId UserId.
     * @param string $actor  Admin doing the revoke.
     *
     * @return bool
     */
    public function revoke(string $userId, string $actor): bool
    {
        $os = $this->getObjectService();
        if ($os === null) {
            return false;
        }

        try {
            $user           = $os->findObject(register: TenantSaasService::REGISTER, schema: 'supplierUser', id: $userId);
            $user['status'] = 'revoked';
            $os->saveObject(
                object: $user,
                register: TenantSaasService::REGISTER,
                schema: 'supplierUser',
                uuid: $userId
            );
            $this->auditTrail->emit([
                'action'   => 'supplier_user.revoke',
                'actor'    => $actor,
                'resource' => 'supplierUser:'.$userId,
                'tenantId' => (string) ($user['supplierRef'] ?? ''),
            ]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Resolve the visible tabs for a role.
     *
     * @param string $role Role.
     *
     * @return array<int, string>
     */
    public function getTabsForRole(string $role): array
    {
        return (self::ROLE_TAB_MATRIX[$role] ?? []);
    }

    /**
     * Whether a role is allowed to access a tab.
     *
     * @param string $role Role.
     * @param string $tab  Tab key.
     *
     * @return bool
     */
    public function canAccessTab(string $role, string $tab): bool
    {
        return in_array($tab, $this->getTabsForRole($role), true);
    }

    /**
     * Throw on unknown role.
     *
     * @param string $role Role.
     *
     * @return void
     *
     * @throws InvalidArgumentException
     */
    public function assertValidRole(string $role): void
    {
        if (in_array($role, self::ALLOWED_ROLES, true) === false) {
            throw new InvalidArgumentException('Unknown supplier role: '.$role);
        }
    }

    /**
     * Whether an invite token has expired.
     *
     * @param string $addedAt    Token issue timestamp (ISO-8601).
     * @param int    $maxAgeDays TTL.
     *
     * @return bool
     */
    public function isInviteExpired(string $addedAt, int $maxAgeDays): bool
    {
        $t = strtotime($addedAt);
        if ($t === false) {
            return true;
        }

        return ($t + ($maxAgeDays * 86400)) < time();
    }

    /**
     * @param string $supplierRef Supplier UUID.
     *
     * @return array<string,mixed>|null
     */
    private function findSupplier(string $supplierRef): ?array
    {
        $os = $this->getObjectService();
        if ($os === null) {
            return null;
        }

        try {
            $row = $os->findObject(register: TenantSaasService::REGISTER, schema: 'supplier', id: $supplierRef);
            return is_array($row) ? $row : null;
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
