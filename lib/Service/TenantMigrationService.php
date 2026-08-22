<?php

/**
 * Tenant Migration Service
 *
 * One-time, idempotent migration of legacy procest `tenant` schema objects onto
 * OpenRegister's Organisation entity per `migrate-tenant-to-or-tenant` (ADR-022,
 * consume-or-tenant-fleet-wide). Dossiq no longer writes its private `tenant`
 * schema — tenant identity, lifecycle status, and quotas live on OR's
 * Organisation. This service reads any pre-existing `tenant` rows and projects
 * each onto an Organisation, preserving the row UUID so stored `_tenantId`
 * references keep resolving.
 *
 * Idempotent: an Organisation whose `slug` already exists is skipped, so the
 * migration is safe to re-run.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/migrate-tenant-to-or-tenant/tasks.md
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\OpenRegister\Db\Organisation;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Migrates legacy procest `tenant` objects to OR Organisations.
 *
 * @spec openspec/changes/migrate-tenant-to-or-tenant/tasks.md
 */
class TenantMigrationService {

	use SearchesObjects;

	/**
	 * Register slug holding the procest schemas.
	 */
	// FROZEN: OpenRegister register SLUG, not this app's id, and unchanged by
	// the procest -> dossiq rename — a renamed value resolves no register and
	// the migration would find zero legacy tenants and report success.
	private const REGISTER_SLUG = 'procest';

	/**
	 * Legacy tenant schema slug being migrated away from.
	 */
	private const TENANT_SCHEMA_SLUG = 'tenant';

	/**
	 * NC group-id prefix used for tenant routing (mirrors TenantService).
	 */
	private const TENANT_GROUP_PREFIX = 'tenant_';

	/**
	 * Map of legacy procest tenant status → OR Organisation lifecycle status.
	 */
	private const STATUS_MAP = [
		'onboarding' => 'provisioning',
		'active' => 'active',
		'suspended' => 'suspended',
		'terminated' => 'archived',
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Dossiq settings/OR bridge (provides ObjectService).
	 * @param ContainerInterface $container DI container (resolves OR's OrganisationMapper).
	 * @param IAppManager $appManager Detects whether OpenRegister is installed.
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Run the migration.
	 *
	 * Reads all legacy `tenant` objects and inserts one OR Organisation per
	 * tenant whose slug is not already present.
	 *
	 * @return array{migrated:int, skipped:int, failed:int, total:int, mappings:array<int,array{tenant:string, organisation:string}>}
	 *
	 * @spec openspec/changes/migrate-tenant-to-or-tenant/tasks.md
	 */
	public function migrate(): array {
		$summary = [
			'migrated' => 0,
			'skipped' => 0,
			'failed' => 0,
			'total' => 0,
			'mappings' => [],
		];

		$objectService = $this->settingsService->getObjectService();
		$mapper = $this->getOrganisationMapper();
		if ($objectService === null || $mapper === null) {
			$this->logger->warning('Dossiq: tenant migration skipped — OpenRegister tenant services unavailable');
			return $summary;
		}

		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: self::REGISTER_SLUG,
				schema: self::TENANT_SCHEMA_SLUG,
				filters: ['_limit' => 5000],
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: tenant migration found no legacy tenant rows (schema absent or empty)',
				['exception' => $e->getMessage()],
			);
			return $summary;
		}

		$summary['total'] = count($rows);

		foreach ($rows as $row) {
			$result = $this->migrateOne(mapper: $mapper, row: $row);
			if ($result === null) {
				$summary['failed']++;
				continue;
			}

			if ($result['created'] === false) {
				$summary['skipped']++;
				continue;
			}

			$summary['migrated']++;
			$summary['mappings'][] = [
				'tenant' => $result['tenantUuid'],
				'organisation' => $result['organisationUuid'],
			];
		}

		$this->logger->info(
			'Dossiq: tenant migration complete',
			[
				'total' => $summary['total'],
				'migrated' => $summary['migrated'],
				'skipped' => $summary['skipped'],
				'failed' => $summary['failed'],
			],
		);

		return $summary;
	}//end migrate()

	/**
	 * Migrate one legacy tenant row to an OR Organisation.
	 *
	 * @param object $mapper OR OrganisationMapper.
	 * @param array<string, mixed> $row Legacy tenant object.
	 *
	 * @return array{created:bool, tenantUuid:string, organisationUuid:string}|null
	 *                                                                              Result, or null on failure.
	 */
	private function migrateOne(object $mapper, array $row): ?array {
		$tenantUuid = (string)($row['id'] ?? ($row['uuid'] ?? ''));
		$slug = (string)($row['slug'] ?? '');
		if ($slug === '') {
			$this->logger->warning('Dossiq: tenant migration skipped a row with no slug', ['tenantUuid' => $tenantUuid]);
			return null;
		}

		try {
			// Idempotency guard: skip when an Organisation already owns this slug.
			$existing = $this->findOrganisationBySlug(mapper: $mapper, slug: $slug);
			if ($existing !== null) {
				return [
					'created' => false,
					'tenantUuid' => $tenantUuid,
					'organisationUuid' => (string)$existing->getUuid(),
				];
			}

			$organisation = $this->buildOrganisation(row: $row, slug: $slug, tenantUuid: $tenantUuid);
			$saved = $mapper->insert($organisation);

			$this->logger->info(
				'Dossiq: migrated tenant to OR Organisation',
				['tenant' => $tenantUuid, 'organisation' => (string)$saved->getUuid(), 'slug' => $slug],
			);

			return [
				'created' => true,
				'tenantUuid' => $tenantUuid,
				'organisationUuid' => (string)$saved->getUuid(),
			];
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: tenant migration failed for one row',
				['tenant' => $tenantUuid, 'slug' => $slug, 'exception' => $e->getMessage()],
			);
			return null;
		}//end try
	}//end migrateOne()

	/**
	 * Build an OR Organisation entity from a legacy tenant row.
	 *
	 * @param array<string, mixed> $row Legacy tenant object.
	 * @param string $slug Tenant slug.
	 * @param string $tenantUuid Tenant UUID (preserved on the Organisation).
	 *
	 * @return object The unsaved Organisation entity.
	 */
	private function buildOrganisation(array $row, string $slug, string $tenantUuid): object {
		$organisation = new Organisation();

		// Preserve the tenant UUID so stored `_tenantId` references keep resolving.
		if ($tenantUuid !== '') {
			$organisation->setUuid($tenantUuid);
		}

		$organisation->setSlug($slug);
		$organisation->setName((string)($row['displayName'] ?? ($row['name'] ?? $slug)));
		$organisation->setStatus($this->resolveStatus(row: $row));

		// The NC group used by procest for tenant routing.
		$groupId = (string)($row['groupId'] ?? (self::TENANT_GROUP_PREFIX . $slug));
		$organisation->setGroups([$groupId]);

		$active = ($organisation->getStatus() === 'active');
		$organisation->setActive($active);

		$storageQuota = $this->resolveStorageQuotaBytes(row: $row);
		if ($storageQuota !== null) {
			$organisation->setStorageQuota($storageQuota);
		}

		return $organisation;
	}//end buildOrganisation()

	/**
	 * Resolve the OR lifecycle status from the legacy tenant row.
	 *
	 * Prefers the tenant's own `status` (mapped to OR's vocabulary); falls back
	 * to the legacy `isActive` boolean when no status is present.
	 *
	 * @param array<string, mixed> $row Legacy tenant object.
	 *
	 * @return string An OR Organisation status.
	 */
	private function resolveStatus(array $row): string {
		$legacyStatus = (string)($row['status'] ?? '');
		if ($legacyStatus !== '' && isset(self::STATUS_MAP[$legacyStatus]) === true) {
			return self::STATUS_MAP[$legacyStatus];
		}

		$isActive = ($row['isActive'] ?? null);
		if ($isActive === false) {
			return 'suspended';
		}

		return 'active';
	}//end resolveStatus()

	/**
	 * Resolve a storage quota in bytes from the legacy `maxStorageMb` field.
	 *
	 * @param array<string, mixed> $row Legacy tenant object.
	 *
	 * @return int|null Quota in bytes, or null when not set.
	 */
	private function resolveStorageQuotaBytes(array $row): ?int {
		$maxStorageMb = ($row['maxStorageMb'] ?? null);
		if (is_numeric($maxStorageMb) === true && (int)$maxStorageMb > 0) {
			return ((int)$maxStorageMb * 1024 * 1024);
		}

		return null;
	}//end resolveStorageQuotaBytes()

	/**
	 * Find an Organisation by slug, returning null when absent.
	 *
	 * @param object $mapper OR OrganisationMapper.
	 * @param string $slug Slug to look up.
	 *
	 * @return object|null The Organisation, or null when none matches.
	 */
	private function findOrganisationBySlug(object $mapper, string $slug): ?object {
		try {
			return $mapper->findBySlug($slug);
		} catch (Throwable $e) {
			// DoesNotExistException (and any other lookup failure) → treat as absent.
			return null;
		}
	}//end findOrganisationBySlug()

	/**
	 * Resolve OR's OrganisationMapper from the DI container.
	 *
	 * Mirrors TenantService: gated on OpenRegister being installed, returns null
	 * (handled gracefully by callers) when OR is absent.
	 *
	 * @return object|null The OrganisationMapper, or null when OR is unavailable.
	 */
	private function getOrganisationMapper(): ?object {
		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			return null;
		}

		try {
			return $this->container->get('OCA\\OpenRegister\\Db\\OrganisationMapper');
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: Could not get OrganisationMapper for tenant migration',
				['exception' => $e->getMessage()],
			);
			return null;
		}
	}//end getOrganisationMapper()
}//end class
