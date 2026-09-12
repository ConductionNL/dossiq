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
 * Idempotent: an Organisation whose `slug` already exists is never created a
 * second time, so the migration is safe to re-run.
 *
 * Re-running also REPAIRS. An earlier version of this service mapped
 * `terminated` onto `archived` and `onboarding` onto `provisioning`, and the
 * slug guard means a plain re-run would skip straight over the rows it wrote.
 * A run now corrects those two statuses where it still finds them, and only
 * where it still finds them, so the second run of any pair is a no-op.
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

use DateTime;
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
	 *
	 * TWO OF THESE FOUR ROWS WERE WRONG, and both were decided against on
	 * 2026-09-11 (tenancy-onto-openregister-organisation, 2e and 2f).
	 *
	 * `terminated` mapped to `archived`. `archived` is
	 * `TenantLifecycleService::PURGEABLE_STATUS`: it is the one status
	 * `TenantPurgeJob` selects on, and everything it then deletes is
	 * permanent. dossiq's termination is deliberately NOT destructive — the
	 * irreversible whole-tenant delete was removed from this app on purpose —
	 * so projecting it onto the one status that exists to be deleted from
	 * inverted the decision it was migrating. It does not purge TODAY only
	 * because the purge also requires `deprovisionedAt`, which this service
	 * never sets; that is an omission, not a rule, and anything that later
	 * stamps that field turns every terminated tenant into a scheduled
	 * delete. `retained` is the rule: OpenRegister added it precisely so a
	 * tenancy can end without the data ending, `retain()` stamps `retainedAt`
	 * and leaves `deprovisionedAt` alone, and the purge's own re-check refuses
	 * any row not in `archived`.
	 *
	 * `onboarding` mapped to `provisioning`. An Organisation in
	 * `provisioning` is not a state a tenant can transact from —
	 * OpenRegister's `TenantQuotaMiddleware` answers 403 to every request the
	 * tenant admin makes on OR routes unless they are an instance admin — and
	 * dossiq's frontend reads and writes through exactly those routes. So the
	 * tenant admin could not perform the onboarding they were in the middle
	 * of. The Organisation goes to `active` and dossiq keeps its own
	 * onboarding state beside it: "may this organisation act?" is the
	 * platform's question, "has this customer finished setting up?" is
	 * dossiq's, and they are not the same question.
	 */
	private const STATUS_MAP = [
		'onboarding' => 'active',
		'active' => 'active',
		'suspended' => 'suspended',
		'terminated' => 'retained',
	];

	/**
	 * Lifecycle status meaning "access ended, data kept" (OR `STATUS_RETAINED`).
	 */
	private const STATUS_RETAINED = 'retained';

	/**
	 * The status OR's `TenantPurgeJob` selects on (OR `PURGEABLE_STATUS`).
	 */
	private const STATUS_PURGEABLE = 'archived';

	/**
	 * Superseded mappings this migration itself wrote, and their correction.
	 *
	 * Fixing STATUS_MAP only fixes tenants migrated from here on. The June
	 * change shipped the two rows above and any instance that ran it already
	 * has terminated tenants sitting in `archived`, and onboarding tenants
	 * sitting in `provisioning` unable to onboard. The slug guard in
	 * `migrateOne()` skips exactly those rows on a re-run, so without a repair
	 * step the damage is permanent and a re-run reports "skipped" over it.
	 *
	 * Keyed by legacy status → [status this migration wrongly wrote, correct
	 * status]. The repair fires ONLY when the Organisation still carries the
	 * superseded value, so a lifecycle move an operator made since — a
	 * terminated tenant they deliberately deprovisioned, say — is never
	 * overwritten, and a second run finds nothing left to repair.
	 *
	 * @var array<string, array{0:string, 1:string}>
	 */
	private const SUPERSEDED_STATUS_REPAIRS = [
		'terminated' => [self::STATUS_PURGEABLE, self::STATUS_RETAINED],
		'onboarding' => ['provisioning', 'active'],
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
	 * @return array{migrated:int, repaired:int, skipped:int, failed:int, total:int, mappings:array<int,array{tenant:string, organisation:string}>}
	 *
	 * @spec openspec/changes/migrate-tenant-to-or-tenant/tasks.md
	 */
	public function migrate(): array {
		$summary = [
			'migrated' => 0,
			'repaired' => 0,
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
				// A repair is reported separately from a skip. Both leave the
				// row count alone, but one of them CHANGED a tenant's
				// lifecycle status and an operator must be able to see that in
				// the summary rather than infer it from the log.
				if (($result['repaired'] ?? false) === true) {
					$summary['repaired']++;
					continue;
				}

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
				'repaired' => $summary['repaired'],
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
	 * @return array{created:bool, repaired?:bool, tenantUuid:string, organisationUuid:string}|null
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
			// Idempotency guard: an Organisation already owns this slug, so
			// nothing is created. It may still need repairing — see
			// SUPERSEDED_STATUS_REPAIRS for why a skip alone is not enough.
			$existing = $this->findOrganisationBySlug(mapper: $mapper, slug: $slug);
			if ($existing !== null) {
				$repaired = $this->repairSupersededStatus(mapper: $mapper, organisation: $existing, row: $row, slug: $slug);

				return [
					'created' => false,
					'repaired' => $repaired,
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
	 * Repair an Organisation this migration previously wrote a superseded status onto.
	 *
	 * Narrow on purpose. It corrects a status ONLY when the legacy row still
	 * says what it said in June AND the Organisation still carries exactly the
	 * value this migration wrote for it. An operator who has since moved the
	 * organisation somewhere else — deprovisioned a terminated tenant
	 * deliberately, reactivated a suspended one — has made a lifecycle
	 * decision, and a migration re-run is not the place to overrule it.
	 *
	 * That narrowness is also what makes the repair idempotent: after the
	 * first run the status no longer matches the superseded value, so the
	 * second run finds nothing to do and writes nothing.
	 *
	 * @param object $mapper OR OrganisationMapper.
	 * @param object $organisation The existing Organisation.
	 * @param array<string, mixed> $row Legacy tenant object.
	 * @param string $slug Tenant slug, for logging.
	 *
	 * @return bool True when the organisation was repaired and saved.
	 *
	 * @spec openspec/changes/tenancy-onto-openregister-organisation/tasks.md
	 */
	private function repairSupersededStatus(object $mapper, object $organisation, array $row, string $slug): bool {
		$legacyStatus = (string)($row['status'] ?? '');
		if (isset(self::SUPERSEDED_STATUS_REPAIRS[$legacyStatus]) === false) {
			return false;
		}

		[$supersededStatus, $correctStatus] = self::SUPERSEDED_STATUS_REPAIRS[$legacyStatus];
		if ((string)$organisation->getStatus() !== $supersededStatus) {
			return false;
		}

		$organisation->setStatus($correctStatus);

		if ($correctStatus === self::STATUS_RETAINED) {
			// Date the retention, the way `retain()` does, but only if nothing
			// has dated it already.
			if ($organisation->getRetainedAt() === null) {
				$organisation->setRetainedAt(new DateTime());
			}

			// Leaving `archived` means leaving the purgeable state, and a
			// `deprovisionedAt` left behind from that state is a loaded gun:
			// the purge measures its retention window from it, so anything
			// that returned the row to `archived` would delete it at once.
			$organisation->setDeprovisionedAt(null);
		}

		$mapper->update($organisation);

		$this->logger->info(
			'Dossiq: repaired a tenant Organisation this migration had written a superseded status onto',
			[
				'slug' => $slug,
				'organisation' => (string)$organisation->getUuid(),
				'legacyStatus' => $legacyStatus,
				'from' => $supersededStatus,
				'to' => $correctStatus,
			],
		);

		return true;
	}//end repairSupersededStatus()

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

		$status = $this->resolveStatus(row: $row);
		$organisation->setStatus($status);

		// A retained organisation dates its retention from `retainedAt`, the
		// way `TenantLifecycleService::retain()` does. `deprovisionedAt` stays
		// null and is never set here: it is what the purge measures its window
		// from, so writing it would schedule the delete this mapping exists to
		// avoid.
		if ($status === self::STATUS_RETAINED) {
			$organisation->setRetainedAt(new DateTime());
		}

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
