<?php

/**
 * Procest Tenant Service
 *
 * Service for managing multi-tenant isolation and tenant provisioning.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
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

/**
 * Service for managing multi-tenant isolation.
 *
 * Resolves tenant from user's Nextcloud group membership,
 * provisions new tenants with dedicated OpenRegister registers,
 * and enforces resource limits.
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
     * @param SettingsService    $settingsService The settings service
     * @param IAppManager        $appManager      The app manager
     * @param IGroupManager      $groupManager    The Nextcloud group manager
     * @param IUserManager       $userManager     The Nextcloud user manager
     * @param ContainerInterface $container       The DI container
     * @param LoggerInterface    $logger          The logger
     *
     * @return void
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
     * Resolve the tenant for a given user.
     *
     * Finds the user's tenant_* group membership and returns the tenant record.
     *
     * @param string $userId The Nextcloud user ID
     *
     * @return array|null The tenant data or null if no tenant found
     */
    public function getTenantForUser(string $userId): ?array
    {
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
    }//end getTenantForUser()

    /**
     * Get a tenant record by its Nextcloud group ID.
     *
     * @param string $groupId The Nextcloud group ID (tenant_{slug})
     *
     * @return array|null The tenant data
     */
    public function getTenantByGroupId(string $groupId): ?array
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return null;
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('tenant_schema');

        if (empty($register) === true || empty($schema) === true) {
            return null;
        }

        $result = $objectService->getObjects(
            (int) $register,
            (int) $schema,
            ['groupId' => $groupId],
        );

        $tenants = ($result['objects'] ?? []);
        if (empty($tenants) === true) {
            return null;
        }

        $tenant = reset($tenants);
        if (is_object($tenant) === true) {
            return $tenant->jsonSerialize();
        }

        return $tenant;
    }//end getTenantByGroupId()

    /**
     * Create a new tenant.
     *
     * Creates the tenant record, a Nextcloud group, and (optionally)
     * a dedicated OpenRegister register.
     *
     * @param string      $name   The municipality name
     * @param string|null $oin    The Organisatie-identificatienummer
     * @param string|null $domain The custom domain
     *
     * @return array The created tenant data
     */
    public function createTenant(string $name, ?string $oin=null, ?string $domain=null): array
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return ['error' => 'OpenRegister is not available'];
        }

        $slug    = $this->slugify(name: $name);
        $groupId = self::TENANT_GROUP_PREFIX.$slug;

        // Create Nextcloud group.
        if ($this->groupManager->groupExists($groupId) === false) {
            $this->groupManager->createGroup($groupId);
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('tenant_schema');

        $tenantData = [
            'name'           => $name,
            'slug'           => $slug,
            'oin'            => $oin,
            'domain'         => $domain,
            'groupId'        => $groupId,
            'brandingTokens' => '{}',
            'maxUsers'       => 0,
            'maxStorageMb'   => 0,
            'isActive'       => true,
        ];

        $result = $objectService->saveObject(
            (int) $register,
            (int) $schema,
            $tenantData,
        );

        $this->logger->info(
            'Procest: Tenant created',
            ['name' => $name, 'slug' => $slug, 'groupId' => $groupId]
        );

        return $result->jsonSerialize();
    }//end createTenant()

    /**
     * Provision a tenant with a dedicated OpenRegister register and default schemas.
     *
     * @param string $tenantId The tenant UUID
     *
     * @return array The provisioning result
     */
    public function provisionTenant(string $tenantId): array
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return ['error' => 'OpenRegister is not available'];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('tenant_schema');

        $tenant     = $objectService->getObject((int) $register, (int) $schema, $tenantId);
        $tenantData = $tenant->jsonSerialize();

        // Create a dedicated register for this tenant.
        try {
            $registerService = $this->container->get('OCA\OpenRegister\Service\RegisterService');
            $newRegister     = $registerService->createFromArray(
                    [
                        'title'       => 'Procest - '.$tenantData['name'],
                        'description' => 'Case management register for '.$tenantData['name'],
                    ]
                    );

            $tenantData['registerId'] = (string) $newRegister->getId();
        } catch (\Exception $e) {
            $this->logger->error(
                'Procest: Failed to create tenant register',
                ['tenantId' => $tenantId, 'exception' => $e->getMessage()]
            );
            return ['error' => 'Failed to create tenant register: '.$e->getMessage()];
        }

        // Save updated tenant with register ID.
        $result = $objectService->saveObject(
            (int) $register,
            (int) $schema,
            $tenantData,
        );

        $this->logger->info(
            'Procest: Tenant provisioned',
            ['tenantId' => $tenantId, 'registerId' => $tenantData['registerId']]
        );

        return $result->jsonSerialize();
    }//end provisionTenant()

    /**
     * Get resource usage for a tenant.
     *
     * @param string $tenantId The tenant UUID
     *
     * @return array Usage data with user count, storage, and limits
     */
    public function getResourceUsage(string $tenantId): array
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return ['error' => 'OpenRegister is not available'];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('tenant_schema');

        $tenant     = $objectService->getObject((int) $register, (int) $schema, $tenantId);
        $tenantData = $tenant->jsonSerialize();

        // Count users in tenant group.
        $group = $this->groupManager->get($tenantData['groupId'] ?? '');
        if ($group !== null) {
            $userCount = count($group->getUsers());
        } else {
            $userCount = 0;
        }

        return [
            'users'        => $userCount,
            'maxUsers'     => (int) ($tenantData['maxUsers'] ?? 0),
            'maxStorageMb' => (int) ($tenantData['maxStorageMb'] ?? 0),
        ];
    }//end getResourceUsage()

    /**
     * Check if a user belongs to a specific tenant.
     *
     * @param string $userId   The Nextcloud user ID
     * @param string $tenantId The tenant UUID
     *
     * @return bool True if user belongs to the tenant
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
     * Check if a user is a platform administrator.
     *
     * @param string $userId The Nextcloud user ID
     *
     * @return bool True if user is in the admin group
     */
    public function isPlatformAdmin(string $userId): bool
    {
        return $this->groupManager->isAdmin($userId);
    }//end isPlatformAdmin()

    /**
     * Generate a URL-safe slug from a name.
     *
     * @param string $name The name to slugify
     *
     * @return string The slug
     */
    private function slugify(string $name): string
    {
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        return trim($slug, '-');
    }//end slugify()

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
}//end class
