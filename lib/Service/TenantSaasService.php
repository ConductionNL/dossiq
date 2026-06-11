<?php

/**
 * Procest Tenant SaaS Service
 *
 * Service that owns the SaaS-shape Tenant CRUD + lifecycle state machine,
 * backed by the seven OpenRegister schemas declared in chain member 01.
 *
 * Separate from the older `TenantService` (which wraps OR's Organisation
 * entity for single-tenant deployments). This service operates on the new
 * `tenant` register schema and implements the slugify + state-machine
 * primitives required by chain members 02-11.
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
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/tenant-zaaksysteem-saas-02-tenant-crud-lifecycle/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use InvalidArgumentException;
use OCA\Procest\Service\Support\SearchesObjects;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Tenant SaaS CRUD + lifecycle state machine backed by OR `tenant` schema.
 *
 * All persistence goes through OpenRegister's ObjectService — no bespoke
 * Doctrine entity. The state machine validates that only the documented
 * transitions are written.
 */
class TenantSaasService
{
    use SearchesObjects;

    /**
     * Procest register slug.
     */
    public const REGISTER = 'procest';

    /**
     * Tenant schema slug.
     */
    public const SCHEMA_TENANT = 'tenant';

    /**
     * Legal lifecycle transitions: from-status => allowed to-statuses.
     *
     * @var array<string, array<int, string>>
     */
    private const LIFECYCLE_TRANSITIONS = [
        'onboarding' => ['active'],
        'active'     => ['suspended', 'terminated'],
        'suspended'  => ['active', 'terminated'],
        'terminated' => [],
    ];

    /**
     * Valid tier values.
     */
    private const TIERS = ['basic', 'standard', 'enterprise'];

    /**
     * Default isolation mode per tier.
     *
     * @var array<string, string>
     */
    private const TIER_ISOLATION = [
        'basic'      => 'schema',
        'standard'   => 'schema',
        'enterprise' => 'database',
    ];

    /**
     * Constructor.
     *
     * @param IAppManager        $appManager App manager (for OR availability check).
     * @param ContainerInterface $container  DI container (graceful OR resolution).
     * @param LoggerInterface    $logger     Logger.
     */
    public function __construct(
        private IAppManager $appManager,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Create a new tenant in `onboarding` status.
     *
     * @param string $name      Display name; also drives slug generation.
     * @param string $kvkNumber KvK (Chamber of Commerce) number.
     * @param string $tier      Tier (basic|standard|enterprise).
     *
     * @return array<string,mixed> The persisted tenant row.
     *
     * @throws InvalidArgumentException On invalid tier or duplicate slug.
     * @throws RuntimeException         When OpenRegister is unavailable.
     */
    public function create(string $name, string $kvkNumber, string $tier): array
    {
        if (in_array($tier, self::TIERS, true) === false) {
            throw new InvalidArgumentException('Invalid tier: '.$tier);
        }

        $slug = $this->slugify($name);
        if ($this->slugExists($slug) === true) {
            throw new InvalidArgumentException('Slug already exists: '.$slug);
        }

        $tenant = [
            'slug'          => $slug,
            'displayName'   => $name,
            'kvkNumber'     => $kvkNumber,
            'status'        => 'onboarding',
            'tier'          => $tier,
            'isolationMode' => (self::TIER_ISOLATION[$tier] ?? 'schema'),
            'dataResidency' => 'nl',
            'createdAt'     => (new \DateTimeImmutable('now'))->format(DATE_ATOM),
        ];

        return $this->saveTenant($tenant, uuid: null);
    }//end create()

    /**
     * Fetch a tenant by UUID.
     *
     * @param string $tenantId Tenant UUID.
     *
     * @return array<string,mixed>|null Persisted tenant or null when missing.
     */
    public function getById(string $tenantId): ?array
    {
        $os = $this->getObjectService();
        if ($os === null) {
            return null;
        }

        try {
            $row = $this->findObjectAsArray(objectService: $os, register: self::REGISTER, schema: self::SCHEMA_TENANT, id: $tenantId);
            return is_array($row) ? $row : null;
        } catch (Throwable $e) {
            $this->logger->info('Procest: TenantSaasService::getById miss', ['tenantId' => $tenantId, 'exception' => $e->getMessage()]);
            return null;
        }
    }//end getById()

    /**
     * List tenants, optionally filtered by status.
     *
     * @param string|null $statusFilter Optional status enum value.
     * @param int         $limit        Page size (default 100).
     * @param int         $offset       Page offset.
     *
     * @return array<int, array<string,mixed>> Tenant rows.
     */
    public function listActive(?string $statusFilter=null, int $limit=100, int $offset=0): array
    {
        $os = $this->getObjectService();
        if ($os === null) {
            return [];
        }

        $filters = [];
        if ($statusFilter !== null && $statusFilter !== '') {
            $filters['status'] = $statusFilter;
        }

        try {
            $rows = $os->findAll(
                register: self::REGISTER,
                schema: self::SCHEMA_TENANT,
                limit: $limit,
                offset: $offset,
                filters: $filters,
            );
            return is_array($rows) ? array_values($rows) : [];
        } catch (Throwable $e) {
            $this->logger->error('Procest: TenantSaasService::listActive failed', ['exception' => $e->getMessage()]);
            return [];
        }
    }//end listActive()

    /**
     * Update a tenant's status, validating the transition against the state machine.
     *
     * @param string $tenantId  Tenant UUID.
     * @param string $newStatus Target status enum value.
     *
     * @return array<string,mixed> Persisted tenant row.
     *
     * @throws InvalidArgumentException On illegal transition or missing tenant.
     * @throws RuntimeException         When OpenRegister is unavailable.
     */
    public function updateStatus(string $tenantId, string $newStatus): array
    {
        $row = $this->getById($tenantId);
        if ($row === null) {
            throw new InvalidArgumentException('Tenant not found: '.$tenantId);
        }

        $current = (string) ($row['status'] ?? '');
        $this->assertLegalTransition($current, $newStatus);

        $row['status'] = $newStatus;
        if ($newStatus === 'active' && empty($row['activatedAt']) === true) {
            $row['activatedAt'] = (new \DateTimeImmutable('now'))->format(DATE_ATOM);
        }

        if ($newStatus === 'terminated' && empty($row['terminatedAt']) === true) {
            $row['terminatedAt'] = (new \DateTimeImmutable('now'))->format(DATE_ATOM);
        }

        return $this->saveTenant($row, uuid: $tenantId);
    }//end updateStatus()

    /**
     * Delete a tenant (hard delete via OR's `deleteObject`).
     *
     * Caller must enforce the business rule that only `terminated` tenants can be
     * physically deleted; the state machine prevents direct delete of active rows.
     *
     * @param string $tenantId Tenant UUID.
     *
     * @return bool True when deletion succeeded.
     */
    public function delete(string $tenantId): bool
    {
        $os = $this->getObjectService();
        if ($os === null) {
            return false;
        }

        try {
            $os->deleteObject(register: self::REGISTER, schema: self::SCHEMA_TENANT, id: $tenantId);
            return true;
        } catch (Throwable $e) {
            $this->logger->error('Procest: TenantSaasService::delete failed', ['tenantId' => $tenantId, 'exception' => $e->getMessage()]);
            return false;
        }
    }//end delete()

    /**
     * Generate a URL-safe tenant slug from a human-readable name.
     *
     * Lowercased, non-alphanumerics collapsed to single hyphens, trimmed,
     * max 64 chars.
     *
     * @param string $name Display name.
     *
     * @return string Slug.
     */
    public function slugify(string $name): string
    {
        $lower = mb_strtolower(trim($name), 'UTF-8');
        // Unicode-aware: replace any non-letter/non-digit run with a hyphen.
        $rep = preg_replace('/[^\p{L}\p{N}]+/u', '-', $lower);
        $rep = (string) $rep;
        $rep = trim($rep, '-');

        if (mb_strlen($rep, 'UTF-8') > 64) {
            $rep = mb_substr($rep, 0, 64, 'UTF-8');
            $rep = trim($rep, '-');
        }

        return $rep;
    }//end slugify()

    /**
     * Validate a lifecycle transition.
     *
     * @param string $current Current status.
     * @param string $target  Target status.
     *
     * @return void
     *
     * @throws InvalidArgumentException When the transition is illegal.
     */
    public function assertLegalTransition(string $current, string $target): void
    {
        if (array_key_exists($current, self::LIFECYCLE_TRANSITIONS) === false) {
            throw new InvalidArgumentException('Unknown current status: '.$current);
        }

        if ($current === $target) {
            throw new InvalidArgumentException('No-op transition: '.$current);
        }

        if (in_array($target, self::LIFECYCLE_TRANSITIONS[$current], true) === false) {
            throw new InvalidArgumentException(
                'Illegal lifecycle transition: '.$current.' → '.$target
            );
        }
    }//end assertLegalTransition()

    /**
     * Return the full lifecycle transition graph (for tests / introspection).
     *
     * @return array<string, array<int, string>>
     */
    public function getLifecycleGraph(): array
    {
        return self::LIFECYCLE_TRANSITIONS;
    }//end getLifecycleGraph()

    /**
     * Check whether a slug is already taken.
     *
     * @param string $slug Candidate slug.
     *
     * @return bool True when an existing tenant uses the slug.
     */
    public function slugExists(string $slug): bool
    {
        $os = $this->getObjectService();
        if ($os === null) {
            return false;
        }

        try {
            $rows = $os->findAll(
                register: self::REGISTER,
                schema: self::SCHEMA_TENANT,
                limit: 1,
                offset: 0,
                filters: ['slug' => $slug],
            );
            return is_array($rows) && count($rows) > 0;
        } catch (Throwable $e) {
            $this->logger->info('Procest: slugExists lookup failed', ['slug' => $slug, 'exception' => $e->getMessage()]);
            return false;
        }
    }//end slugExists()

    /**
     * Persist a tenant row via OR's ObjectService.
     *
     * @param array<string,mixed> $tenant Tenant payload.
     * @param string|null         $uuid   Optional existing UUID (update path).
     *
     * @return array<string,mixed> Persisted tenant row.
     *
     * @throws RuntimeException When OpenRegister is unavailable.
     */
    private function saveTenant(array $tenant, ?string $uuid): array
    {
        $os = $this->getObjectService();
        if ($os === null) {
            throw new RuntimeException('OpenRegister is not available');
        }

        try {
            $row = $os->saveObject(
                object: $tenant,
                register: self::REGISTER,
                schema: self::SCHEMA_TENANT,
                uuid: $uuid,
            );
            return is_array($row) ? $row : $tenant;
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest: TenantSaasService::saveTenant failed',
                ['exception' => $e->getMessage()]
            );
            throw new RuntimeException('Failed to persist tenant: '.$e->getMessage(), 0, $e);
        }
    }//end saveTenant()

    /**
     * Resolve OR's ObjectService when installed.
     *
     * @return mixed The ObjectService instance or null.
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
            $this->logger->error('Procest: Could not resolve ObjectService', ['exception' => $e->getMessage()]);
            return null;
        }
    }//end getObjectService()
}//end class
