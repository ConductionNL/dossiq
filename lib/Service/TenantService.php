<?php

/**
 * Procest Tenant Service
 *
 * Service for managing multi-tenant isolation; delegates the tenant data model
 * to OpenRegister's Organisation entity + TenantLifecycleService per the
 * Hydra umbrella consume-or-tenant-fleet-wide (ADR-022).
 *
 * Public method signatures and return shapes are preserved so existing
 * callers (TenantController, TenantMiddleware) do not require modification.
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
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Service for managing multi-tenant isolation backed by OR Organisations.
 *
 * Each procest tenant maps 1:1 to an OR Organisation entity. The
 * `Organisation.groups` array carries the NC group IDs used by procest for
 * tenant routing; `Organisation.status` carries the lifecycle state enforced
 * by `TenantMiddleware`.
 */
class TenantService
{
    /**
     * Prefix for tenant Nextcloud groups.
     */
    private const TENANT_GROUP_PREFIX = 'tenant_';

    /**
     * Constructor for the TenantService.
     *
     * @param SettingsService    $settingsService Settings service.
     * @param IAppManager        $appManager      The app manager.
     * @param IGroupManager      $groupManager    The Nextcloud group manager.
     * @param IUserManager       $userManager     The Nextcloud user manager.
     * @param ContainerInterface $container       The DI container (graceful OR resolution).
     * @param LoggerInterface    $logger          The logger.
     */
    public function __construct(
        private SettingsService $settingsService,
        private IAppManager $appManager,
        private IGroupManager $groupManager,
        private IUserManager $userManager,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Resolve the tenant for a given user via OR's `findByUserId` lookup.
     *
     * Returns the Organisation as a `jsonSerialize`d array so existing callers
     * see the same shape as before (uuid, name, slug, status, registerId, etc.).
     *
     * @param string $userId The Nextcloud user ID.
     *
     * @return array|null The tenant data or null when none found.
     */
    public function getTenantForUser(string $userId): ?array
    {
        $orgs = $this->findOrganisationsByUserId(userId: $userId);
        if (empty($orgs) === true) {
            // Fall back to NC-group lookup so existing single-tenant deployments still work.
            $user = $this->userManager->get($userId);
            if ($user === null) {
                return null;
            }

            $groups = $this->groupManager->getUserGroups($user);
            foreach ($groups as $group) {
                $groupId = $group->getGID();
                if (str_starts_with($groupId, self::TENANT_GROUP_PREFIX) === true) {
                    return $this->getTenantByGroupId(groupId: $groupId);
                }
            }

            return null;
        }

        return $orgs[0]->jsonSerialize();
    }//end getTenantForUser()

    /**
     * Get a tenant by its Nextcloud group ID.
     *
     * Queries OR Organisations whose `groups` array contains the given groupId.
     *
     * @param string $groupId The Nextcloud group ID (`tenant_<slug>`).
     *
     * @return array|null The tenant data, or null when not resolvable.
     */
    public function getTenantByGroupId(string $groupId): ?array
    {
        $mapper = $this->getOrganisationMapper();
        if ($mapper === null) {
            return null;
        }

        try {
            // Linear scan is acceptable here — tenant count is small (≤100s).
            foreach ($mapper->findAll(limit: 500) as $org) {
                $groups = ($org->getGroups() ?? []);
                if (in_array($groupId, $groups, true) === true) {
                    return $org->jsonSerialize();
                }
            }
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest: getTenantByGroupId failed against OR',
                ['groupId' => $groupId, 'exception' => $e->getMessage()]
            );
            return null;
        }

        return null;
    }//end getTenantByGroupId()

    /**
     * Provision a tenant via OR's TenantLifecycleService.
     *
     * Reads the Organisation by UUID, calls `provision()` (which handles the
     * provisioning → active state transition + emits `OrganisationProvisionedEvent`).
     *
     * @param string $tenantId The Organisation UUID.
     *
     * @return array The provisioning result (the Organisation `jsonSerialize`d).
     */
    public function provisionTenant(string $tenantId): array
    {
        $mapper           = $this->getOrganisationMapper();
        $lifecycleService = $this->getTenantLifecycleService();
        if ($mapper === null || $lifecycleService === null) {
            return ['error' => 'OpenRegister tenant services unavailable'];
        }

        try {
            $org = $mapper->findByUuid($tenantId);
            if ($this->groupManager->isAdmin($org->getOwner() ?? '') === true) {
                $adminUid = $org->getOwner();
            } else {
                $adminUid = 'admin';
            }

            $org = $lifecycleService->provision($org, (string) $adminUid);
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest: provisionTenant failed via OR',
                ['tenantId' => $tenantId, 'exception' => $e->getMessage()]
            );
            return ['error' => 'Failed to provision tenant: '.$e->getMessage()];
        }

        $this->logger->info(
            'Procest: Tenant provisioned via OR TenantLifecycleService',
            ['tenantId' => $tenantId, 'status' => $org->getStatus()]
        );

        return $org->jsonSerialize();
    }//end provisionTenant()

    /**
     * Get resource usage for a tenant from OR data.
     *
     * `storageQuota` comes from the OR Organisation entity (bytes per
     * tenant-quotas spec). User count comes from the NC group as before.
     *
     * @param string $tenantId The Organisation UUID.
     *
     * @return array Usage data with user count + OR quota fields.
     */
    public function getResourceUsage(string $tenantId): array
    {
        $mapper = $this->getOrganisationMapper();
        if ($mapper === null) {
            return ['error' => 'OpenRegister is not available'];
        }

        try {
            $org = $mapper->findByUuid($tenantId);
        } catch (Throwable $e) {
            return ['error' => 'Organisation not found'];
        }

        $userCount = 0;
        foreach (($org->getGroups() ?? []) as $groupId) {
            $group = $this->groupManager->get($groupId);
            if ($group !== null) {
                $userCount += count($group->getUsers());
            }
        }

        return [
            'users'          => $userCount,
            'storageQuota'   => (int) ($org->getStorageQuota() ?? 0),
            'bandwidthQuota' => (int) ($org->getBandwidthQuota() ?? 0),
            'requestQuota'   => (int) ($org->getRequestQuota() ?? 0),
            'status'         => (string) ($org->getStatus() ?? ''),
        ];
    }//end getResourceUsage()

    /**
     * Check whether a user belongs to a specific tenant.
     *
     * @param string $userId   The Nextcloud user ID.
     * @param string $tenantId The Organisation UUID.
     *
     * @return bool True when the user is mapped to the tenant.
     */
    public function isUserInTenant(string $userId, string $tenantId): bool
    {
        $tenant = $this->getTenantForUser(userId: $userId);
        if ($tenant === null) {
            return false;
        }

        return ($tenant['uuid'] ?? $tenant['id'] ?? '') === $tenantId;
    }//end isUserInTenant()

    /**
     * Check whether a user is a platform administrator.
     *
     * @param string $userId The Nextcloud user ID.
     *
     * @return bool True when the user is in the NC admin group.
     */
    public function isPlatformAdmin(string $userId): bool
    {
        return $this->groupManager->isAdmin($userId);
    }//end isPlatformAdmin()

    /**
     * Check whether a tenant (OR Organisation) is in `active` state.
     *
     * Used by `TenantMiddleware` to short-circuit requests scoped to a
     * suspended / deprovisioning / archived tenant with HTTP 403.
     *
     * @param string $tenantId Organisation UUID.
     *
     * @return string|null Status string (`active`, `suspended`, etc.) or null
     *                     when OR cannot resolve the Organisation.
     */
    public function getTenantStatus(string $tenantId): ?string
    {
        $mapper = $this->getOrganisationMapper();
        if ($mapper === null) {
            return null;
        }

        try {
            return $mapper->findByUuid($tenantId)->getStatus();
        } catch (Throwable $e) {
            return null;
        }
    }//end getTenantStatus()

    /**
     * Find OR Organisations a user belongs to.
     *
     * @param string $userId Nextcloud user ID.
     *
     * @return array Organisation entities (may be empty).
     */
    private function findOrganisationsByUserId(string $userId): array
    {
        $mapper = $this->getOrganisationMapper();
        if ($mapper === null) {
            return [];
        }

        try {
            return $mapper->findByUserId($userId);
        } catch (Throwable $e) {
            return [];
        }
    }//end findOrganisationsByUserId()

    /**
     * Resolve OR's OrganisationMapper if installed.
     *
     * @return \OCA\OpenRegister\Db\OrganisationMapper|null
     */
    private function getOrganisationMapper()
    {
        if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
            return null;
        }

        try {
            return $this->container->get('OCA\\OpenRegister\\Db\\OrganisationMapper');
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest: Could not get OrganisationMapper',
                ['exception' => $e->getMessage()]
            );
            return null;
        }
    }//end getOrganisationMapper()

    /**
     * Resolve OR's TenantLifecycleService if installed.
     *
     * @return \OCA\OpenRegister\Service\TenantLifecycleService|null
     */
    private function getTenantLifecycleService()
    {
        if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
            return null;
        }

        try {
            return $this->container->get('OCA\\OpenRegister\\Service\\TenantLifecycleService');
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest: Could not get TenantLifecycleService',
                ['exception' => $e->getMessage()]
            );
            return null;
        }
    }//end getTenantLifecycleService()
}//end class
