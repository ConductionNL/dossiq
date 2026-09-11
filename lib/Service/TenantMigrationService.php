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
 * Idempotent BY UUID, and that is the whole point of the key. See migrateOne()
 * for why the slug was the wrong one and what a slug collision now does.
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

use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\OpenRegister\Db\Organisation;
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
	 * Register slug holding this app's schemas.
	 */
	// The OpenRegister register SLUG, not this app's id. It moves with the app
	// id: `MigrateRegisterSlug` renames the register row `procest` -> `dossiq`
	// ahead of every step that resolves a register, so this value and the row
	// say the same thing. A mismatch resolves no register and the migration
	// finds zero legacy tenants while reporting success.
	private const REGISTER_SLUG = 'dossiq';

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
		// Decision 2f. NOT `provisioning`, which the June map chose. While a
		// user's active Organisation is `provisioning`, openregister's
		// TenantQuotaMiddleware answers 403 to every request that user makes on
		// openregister's routes unless they are an instance admin, and dossiq's
		// frontend reads and writes through those routes. A dossiq tenant in
		// `onboarding` is one whose tenant admin is still working through the
		// onboarding steps, so it stays `active` on the Organisation with
		// dossiq's own onboarding progress kept beside it in tenantOnboardingTask.
		'onboarding' => 'active',
		'active' => 'active',
		'suspended' => 'suspended',
		// Decision 2e, resolved upstream rather than chosen between two bad
		// options. `retained` is openregister's terminal state for an
		// organisation with a retention duty: access ends, nothing is deleted,
		// and TenantPurgeJob can only ever delete a row in PURGEABLE_STATUS,
		// which is `archived` alone. The June map sent `terminated` to
		// `archived`, which is both terminal AND the one purgeable state, and
		// unreachable from a live termination without passing `deprovisioning`.
		'terminated' => 'retained',
	];

	/**
	 * The lifecycle status that ends access while keeping the data.
	 */
	private const STATUS_RETAINED = 'retained';

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
	 * @return array{migrated:int, skipped:int, refused:int, failed:int, total:int,
	 *               mappings:array<int,array{tenant:string, organisation:string}>,
	 *               collisions:array<int,array{tenant:string, slug:string, heldBy:string}>}
	 *
	 * @spec openspec/changes/migrate-tenant-to-or-tenant/tasks.md
	 */
	public function migrate(): array {
		$summary = [
			'migrated' => 0,
			'skipped' => 0,
			'refused' => 0,
			'failed' => 0,
			'total' => 0,
			'mappings' => [],
			'collisions' => [],
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

			if ($result['refused'] === true) {
				$summary['refused']++;
				$summary['collisions'][] = [
					'tenant' => $result['tenantUuid'],
					'slug' => ($result['slug'] ?? ''),
					'heldBy' => $result['organisationUuid'],
				];
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
				'refused' => $summary['refused'],
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
	 * @return array{created:bool, refused:bool, tenantUuid:string, organisationUuid:string, slug?:string}|null
	 *                                                                              Result, or null on failure.
	 */
	private function migrateOne(object $mapper, array $row): ?array {
		$tenantUuid = trim((string)($row['id'] ?? ($row['uuid'] ?? '')));
		$slug = trim((string)($row['slug'] ?? ''));
		if ($tenantUuid === '') {
			// The uuid is the key, so a row without one cannot be migrated at
			// all: there is nothing to preserve onto the Organisation and
			// nothing for a satellite `tenantRef` to keep resolving to.
			$this->logger->warning('Dossiq: tenant migration skipped a row with no id', ['slug' => $slug]);
			return null;
		}

		if ($slug === '') {
			$this->logger->warning('Dossiq: tenant migration skipped a row with no slug', ['tenantUuid' => $tenantUuid]);
			return null;
		}

		try {
			// 🔴 THE UUID IS THE IDEMPOTENCY KEY, NOT THE SLUG.
			//
			// This used to be keyed by slug, and that is an isolation hazard
			// rather than a style question. A tenant whose slug some OTHER
			// Organisation already holds was reported as "already migrated"
			// against that other Organisation's uuid. Rewriting `tenantRef`
			// from that report would attach one tenant's users, mandates and
			// quotas to a different organisation, and every scoping filter
			// downstream would then agree, because the rows really would say so.
			//
			// The uuid is exact: it is what the satellites already reference
			// and what this migration preserves onto the Organisation.
			$existing = $this->findOrganisationByUuid(mapper: $mapper, uuid: $tenantUuid);
			if ($existing !== null) {
				return [
					'created' => false,
					'refused' => false,
					'tenantUuid' => $tenantUuid,
					'organisationUuid' => (string)$existing->getUuid(),
				];
			}

			// A DIFFERENT Organisation holding this slug is refused, not
			// merged and not skipped. `slug` is unique on Organisation, so
			// inserting would fail anyway; refusing says WHICH organisation is
			// in the way, which is the thing an operator needs in order to fix
			// it. Nothing is written and nothing is reported as mapped.
			$collision = $this->findOrganisationBySlug(mapper: $mapper, slug: $slug);
			if ($collision !== null) {
				$this->logger->error(
					'Dossiq: tenant migration refused a slug already held by another organisation',
					[
						'tenant' => $tenantUuid,
						'slug' => $slug,
						'heldBy' => (string)$collision->getUuid(),
					],
				);

				return [
					'created' => false,
					'refused' => true,
					'tenantUuid' => $tenantUuid,
					'organisationUuid' => (string)$collision->getUuid(),
					'slug' => $slug,
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
				'refused' => false,
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
	 * Find an Organisation by uuid, returning null when absent.
	 *
	 * @param object $mapper OR OrganisationMapper.
	 * @param string $uuid   Uuid to look up.
	 *
	 * @return object|null The Organisation, or null when none matches.
	 */
	private function findOrganisationByUuid(object $mapper, string $uuid): ?object {
		try {
			return $mapper->findByUuid($uuid);
		} catch (Throwable $e) {
			// DoesNotExistException, and any other lookup failure, reads as absent.
			return null;
		}
	}//end findOrganisationByUuid()

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

		// A terminated tenant becomes `retained`, and the moment the retention
		// began is stamped on `retainedAt`, which is what openregister's own
		// retain() writes. `deprovisionedAt` is deliberately left untouched:
		// TenantPurgeJob measures its window from that column, so writing it
		// would schedule a deletion dossiq's termination does not have.
		if ($organisation->getStatus() === self::STATUS_RETAINED) {
			$organisation->setRetainedAt($this->resolveRetainedAt(row: $row));
		}

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
	 * Resolve when retention began for a terminated tenant.
	 *
	 * The tenant's own `terminatedAt` when it has one, so a retention period
	 * that started years ago is not reset to today by the migration. Now
	 * otherwise, because a retained organisation with no start date has no
	 * computable end of retention.
	 *
	 * @param array<string, mixed> $row Legacy tenant object.
	 *
	 * @return \DateTime When retention began.
	 */
	private function resolveRetainedAt(array $row): \DateTime {
		$terminatedAt = trim((string)($row['terminatedAt'] ?? ''));
		if ($terminatedAt !== '') {
			try {
				return new \DateTime($terminatedAt);
			} catch (Throwable $e) {
				$this->logger->warning(
					'Dossiq: tenant migration could not read terminatedAt, stamping retention from now',
					['terminatedAt' => $terminatedAt],
				);
			}
		}

		return new \DateTime();
	}//end resolveRetainedAt()

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
