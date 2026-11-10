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
 * Re-running also REPAIRS. An earlier version of this service mapped
 * `terminated` onto `archived` and `onboarding` onto `provisioning`, and the
 * idempotency guard means a plain re-run would skip straight over the rows it
 * wrote. A run now corrects those two statuses where it still finds them, and
 * only where it still finds them, so the second run of any pair is a no-op.
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
use OCA\Dossiq\Service\TenantMigration\LegacyTenant;
use OCA\Dossiq\Service\TenantMigration\TenantMigrationRun;
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
	 * The migration writes.
	 */
	public const MODE_APPLY = 'apply';

	/**
	 * The migration decides and reports, and writes nothing.
	 */
	public const MODE_PREVIEW = 'preview';

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
	 * The summary a run starts from.
	 *
	 * @var array<string, mixed>
	 */
	private const EMPTY_SUMMARY = [
		'migrated' => 0,
		'repaired' => 0,
		'skipped' => 0,
		'refused' => 0,
		'failed' => 0,
		'total' => 0,
		'unmigrated' => 0,
		'dryRun' => false,
		'mappings' => [],
		'collisions' => [],
	];

	/**
	 * Per outcome of a tenant that was not refused: [created, repaired, hasOrganisation].
	 *
	 * A dry run's `preview` has no Organisation yet; every other outcome does.
	 *
	 * @var array<string, array{0:bool, 1:bool, 2:bool}>
	 */
	private const OUTCOMES = [
		'unchanged' => [false, false, true],
		'repaired' => [false, true, true],
		'preview' => [true, false, false],
		'inserted' => [true, false, true],
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
	 * A dry run reads and decides exactly as a real run does, and writes
	 * nothing: no Organisation is inserted or updated. Its summary is the one
	 * a real run would print, so a person can read the collisions and orphans
	 * before anything moves (REQ-TOO-001).
	 *
	 * `unmigrated` counts the stored tenants that have no Organisation of the
	 * same uuid once the run is over: refused collisions and failed rows
	 * included, and in a dry run every tenant that would have been created.
	 * The repair step reports the same number, so the release that deletes
	 * this class can wait for it to read zero (decision Q1).
	 *
	 * @param string $mode MODE_APPLY writes, MODE_PREVIEW writes nothing. A mode, not a
	 *                     flag, so the walk stays one method and the two cannot drift apart.
	 *
	 * @return array{migrated:int, repaired:int, skipped:int, refused:int, failed:int, total:int,
	 *               unmigrated:int, dryRun:bool,
	 *               mappings:array<int,array{tenant:string, organisation:string}>,
	 *               collisions:array<int,array{tenant:string, slug:string, heldBy:string}>}
	 *
	 * @spec openspec/changes/migrate-tenant-to-or-tenant/tasks.md
	 * @spec openspec/changes/tenancy-onto-openregister-organisation/specs/tenant-organisation-boundary/spec.md
	 */
	public function migrate(string $mode = self::MODE_APPLY): array {
		$run = new TenantMigrationRun();
		$run->dryRun = ($mode === self::MODE_PREVIEW);
		$run->summary = array_replace(self::EMPTY_SUMMARY, ['dryRun' => $run->dryRun]);

		$rows = $this->legacyRows(run: $run);
		if ($rows === null) {
			return $run->summary;
		}

		$run->summary['total'] = count($rows);
		foreach ($rows as $row) {
			$this->tally(run: $run, result: $this->migrateOne(run: $run, row: $row));
		}

		$this->logComplete(run: $run);
		return $run->summary;
	}//end migrate()

	/**
	 * Resolve the OpenRegister services and read the legacy tenant rows.
	 *
	 * @param TenantMigrationRun $run The run, given its mapper here.
	 *
	 * @return array<int, array<string, mixed>>|null The rows, or null when the run cannot start.
	 */
	private function legacyRows(TenantMigrationRun $run): ?array {
		$objectService = $this->settingsService->getObjectService();
		$mapper = $this->getOrganisationMapper();
		if ($objectService === null || $mapper === null) {
			$this->logger->warning('Dossiq: tenant migration skipped — OpenRegister tenant services unavailable');
			return null;
		}

		$run->mapper = $mapper;
		return $this->readLegacyTenants(objectService: $objectService);
	}//end legacyRows()

	/**
	 * Read the legacy `tenant` objects.
	 *
	 * @param object $objectService OR ObjectService.
	 *
	 * @return array<int, array<string, mixed>>|null The rows, or null when the schema is absent or the read fails.
	 */
	private function readLegacyTenants(object $objectService): ?array {
		$rows = null;
		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService, register: self::REGISTER_SLUG, schema: self::TENANT_SCHEMA_SLUG, filters: ['_limit' => 5000]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: tenant migration found no legacy tenant rows (schema absent or empty)',
				['exception' => $e->getMessage()],
			);
		}

		return $rows;
	}//end readLegacyTenants()

	/**
	 * Log the counts of a finished run.
	 *
	 * @param TenantMigrationRun $run The finished run.
	 *
	 * @return void
	 */
	private function logComplete(TenantMigrationRun $run): void {
		$this->logger->info(
			'Dossiq: tenant migration complete',
			[
				'total' => $run->summary['total'],
				'migrated' => $run->summary['migrated'],
				'repaired' => $run->summary['repaired'],
				'skipped' => $run->summary['skipped'],
				'refused' => $run->summary['refused'],
				'failed' => $run->summary['failed'],
				'unmigrated' => $run->summary['unmigrated'],
				'dryRun' => $run->dryRun,
			],
		);
	}//end logComplete()

	/**
	 * Count one row's result into the summary.
	 *
	 * @param TenantMigrationRun        $run    The run whose summary is updated.
	 * @param array<string, mixed>|null $result The row's result, or null when it failed.
	 *
	 * @return void
	 */
	private function tally(TenantMigrationRun $run, ?array $result): void {
		if ($result === null) {
			$run->summary['failed']++;
			$run->summary['unmigrated']++;
			return;
		}

		if ($result['hasOrganisation'] === false) {
			$run->summary['unmigrated']++;
		}

		$this->tallyOutcome(run: $run, result: $result);
	}//end tally()

	/**
	 * Count a row that did not fail: refused, migrated, repaired or skipped.
	 *
	 * @param TenantMigrationRun   $run    The run whose summary is updated.
	 * @param array<string, mixed> $result The row's result.
	 *
	 * @return void
	 */
	private function tallyOutcome(TenantMigrationRun $run, array $result): void {
		if ($result['refused'] === true) {
			$this->tallyRefusal(run: $run, result: $result);
			return;
		}

		if ($result['created'] === true) {
			$this->tallyMapping(run: $run, result: $result);
			return;
		}

		// A repair is reported separately from a skip. Both leave the
		// row count alone, but one of them CHANGED a tenant's
		// lifecycle status and an operator must be able to see that in
		// the summary rather than infer it from the log.
		$counter = 'skipped';
		if (($result['repaired'] ?? false) === true) {
			$counter = 'repaired';
		}

		$run->summary[$counter]++;
	}//end tallyOutcome()

	/**
	 * Count a refused collision and report which organisation holds the slug.
	 *
	 * @param TenantMigrationRun   $run    The run whose summary is updated.
	 * @param array<string, mixed> $result The refusal.
	 *
	 * @return void
	 */
	private function tallyRefusal(TenantMigrationRun $run, array $result): void {
		$run->summary['refused']++;
		$run->summary['collisions'][] = [
			'tenant' => $result['tenantUuid'],
			'slug' => ($result['slug'] ?? ''),
			'heldBy' => $result['organisationUuid'],
		];
	}//end tallyRefusal()

	/**
	 * Count a created Organisation and report the tenant it maps.
	 *
	 * @param TenantMigrationRun   $run    The run whose summary is updated.
	 * @param array<string, mixed> $result The creation.
	 *
	 * @return void
	 */
	private function tallyMapping(TenantMigrationRun $run, array $result): void {
		$run->summary['migrated']++;
		$run->summary['mappings'][] = [
			'tenant' => $result['tenantUuid'],
			'organisation' => $result['organisationUuid'],
		];
	}//end tallyMapping()

	/**
	 * Migrate one legacy tenant row to an OR Organisation.
	 *
	 * @param TenantMigrationRun   $run The run.
	 * @param array<string, mixed> $row Legacy tenant object.
	 *
	 * @return array{created:bool, refused:bool, repaired:bool, hasOrganisation:bool, tenantUuid:string, organisationUuid:string, slug?:string}|null
	 *                                                                              Result, or null on failure.
	 */
	private function migrateOne(TenantMigrationRun $run, array $row): ?array {
		$tenant = $this->legacyTenant(row: $row);
		if ($tenant === null) {
			return null;
		}

		try {
			return $this->placeTenant(run: $run, tenant: $tenant);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: tenant migration failed for one row',
				['tenant' => $tenant->uuid, 'slug' => $tenant->slug, 'exception' => $e->getMessage()],
			);
			return null;
		}//end try
	}//end migrateOne()

	/**
	 * Read the uuid and slug off a legacy row, or skip a row missing either.
	 *
	 * @param array<string, mixed> $row Legacy tenant object.
	 *
	 * @return LegacyTenant|null The tenant, or null when the row cannot be migrated.
	 */
	private function legacyTenant(array $row): ?LegacyTenant {
		$tenant = new LegacyTenant();
		$tenant->row = $row;
		$tenant->uuid = trim((string)($row['id'] ?? ($row['uuid'] ?? '')));
		$tenant->slug = trim((string)($row['slug'] ?? ''));
		if ($tenant->uuid === '') {
			// The uuid is the key, so a row without one cannot be migrated at
			// all: there is nothing to preserve onto the Organisation and
			// nothing for a satellite `tenantRef` to keep resolving to.
			$this->logger->warning('Dossiq: tenant migration skipped a row with no id', ['slug' => $tenant->slug]);
			return null;
		}

		if ($tenant->slug === '') {
			$this->logger->warning('Dossiq: tenant migration skipped a row with no slug', ['tenantUuid' => $tenant->uuid]);
			return null;
		}

		return $tenant;
	}//end legacyTenant()

	/**
	 * Place one tenant: keep (and maybe repair) its Organisation, refuse a collision, or create one.
	 *
	 * @param TenantMigrationRun $run    The run.
	 * @param LegacyTenant       $tenant The tenant.
	 *
	 * @return array{created:bool, refused:bool, repaired:bool, hasOrganisation:bool, tenantUuid:string, organisationUuid:string, slug?:string}
	 *         The row's result.
	 */
	private function placeTenant(TenantMigrationRun $run, LegacyTenant $tenant): array {
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
		$existing = OrganisationLookup::byUuid(mapper: $run->mapper, uuid: $tenant->uuid);
		if ($existing !== null) {
			$tenant->organisation = $existing;
			return $this->keepExisting(run: $run, tenant: $tenant);
		}

		// A DIFFERENT Organisation holding this slug is refused, not
		// merged and not skipped. `slug` is unique on Organisation, so
		// inserting would fail anyway; refusing says WHICH organisation is
		// in the way, which is the thing an operator needs in order to fix
		// it. Nothing is written and nothing is reported as mapped.
		//
		// 🔴 AND IT IS NOT REPAIRED. The repair above runs on the uuid
		// path, where the Organisation is provably this tenant. Here it is
		// provably NOT: the uuid did not match, so this row belongs to
		// somebody else and only shares a name. Repairing it would write a
		// lifecycle status onto another organisation on the strength of a
		// slug, which is exactly the write the refusal exists to prevent,
		// and it would be a write rather than a mis-report. The repair is
		// deliberately absent from this branch.
		$collision = $this->findOrganisationBySlug(mapper: $run->mapper, slug: $tenant->slug);
		if ($collision !== null) {
			return $this->refuseCollision(collision: $collision, tenant: $tenant);
		}

		return $this->createOrganisation(run: $run, tenant: $tenant);
	}//end placeTenant()

	/**
	 * Keep the Organisation a tenant already has, repairing a superseded status on it.
	 *
	 * @param TenantMigrationRun $run    The run.
	 * @param LegacyTenant       $tenant The tenant, with its existing Organisation.
	 *
	 * @return array{created:bool, refused:bool, repaired:bool, hasOrganisation:bool, tenantUuid:string, organisationUuid:string}
	 *         The row's result.
	 */
	private function keepExisting(TenantMigrationRun $run, LegacyTenant $tenant): array {
		$kind = 'unchanged';
		if ($this->repairSupersededStatus(run: $run, tenant: $tenant) === true) {
			$kind = 'repaired';
		}

		return $this->outcome(tenant: $tenant, kind: $kind);
	}//end keepExisting()

	/**
	 * Create the Organisation for a tenant, or only build it in a dry run.
	 *
	 * @param TenantMigrationRun $run    The run.
	 * @param LegacyTenant       $tenant The tenant, given its Organisation here.
	 *
	 * @return array{created:bool, refused:bool, repaired:bool, hasOrganisation:bool, tenantUuid:string, organisationUuid:string}
	 *         The row's result.
	 */
	private function createOrganisation(TenantMigrationRun $run, LegacyTenant $tenant): array {
		$tenant->organisation = $this->buildOrganisation(tenant: $tenant);
		if ($run->dryRun === true) {
			// The uuid a real run would give it: buildOrganisation() keeps the tenant's.
			return $this->outcome(tenant: $tenant, kind: 'preview');
		}

		$tenant->organisation = $run->mapper->insert($tenant->organisation);

		$this->logger->info(
			'Dossiq: migrated tenant to OR Organisation',
			['tenant' => $tenant->uuid, 'organisation' => (string)$tenant->organisation->getUuid(), 'slug' => $tenant->slug],
		);

		return $this->outcome(tenant: $tenant, kind: 'inserted');
	}//end createOrganisation()

	/**
	 * The result of a tenant that was not refused, against its Organisation.
	 *
	 * @param LegacyTenant $tenant The tenant, with its Organisation.
	 * @param string       $kind   A key of OUTCOMES.
	 *
	 * @return array{created:bool, refused:bool, repaired:bool, hasOrganisation:bool, tenantUuid:string, organisationUuid:string}
	 *         The row's result.
	 */
	private function outcome(LegacyTenant $tenant, string $kind): array {
		[$created, $repaired, $hasOrganisation] = self::OUTCOMES[$kind];
		return [
			'created' => $created,
			'refused' => false,
			'repaired' => $repaired,
			'hasOrganisation' => $hasOrganisation,
			'tenantUuid' => $tenant->uuid,
			'organisationUuid' => (string)$tenant->organisation->getUuid(),
		];
	}//end outcome()

	/**
	 * Refuse a tenant whose slug another Organisation already holds.
	 *
	 * Nothing is written, including no repair: see the call site for why a
	 * slug match is the one place a lifecycle correction must not fire.
	 *
	 * @param object       $collision The Organisation holding the slug.
	 * @param LegacyTenant $tenant    The tenant.
	 *
	 * @return array{created:bool, refused:bool, repaired:bool, hasOrganisation:bool, tenantUuid:string, organisationUuid:string, slug:string}
	 *         The refusal, for the summary to report.
	 */
	private function refuseCollision(object $collision, LegacyTenant $tenant): array {
		$this->logger->error(
			'Dossiq: tenant migration refused a slug already held by another organisation',
			['tenant' => $tenant->uuid, 'slug' => $tenant->slug, 'heldBy' => (string)$collision->getUuid()],
		);

		return [
			'created' => false,
			'refused' => true,
			'repaired' => false,
			'hasOrganisation' => false,
			'tenantUuid' => $tenant->uuid,
			'organisationUuid' => (string)$collision->getUuid(),
			'slug' => $tenant->slug,
		];
	}//end refuseCollision()


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
	 * @param TenantMigrationRun $run    The run; a dry run reports the repair and changes nothing,
	 *                                   not even the entity in memory.
	 * @param LegacyTenant       $tenant The tenant, with its existing Organisation.
	 *
	 * @return bool True when the organisation was repaired and saved, or would be in a dry run.
	 *
	 * @spec openspec/changes/tenancy-onto-openregister-organisation/tasks.md
	 */
	private function repairSupersededStatus(TenantMigrationRun $run, LegacyTenant $tenant): bool {
		$legacyStatus = (string)($tenant->row['status'] ?? '');
		$repair = (self::SUPERSEDED_STATUS_REPAIRS[$legacyStatus] ?? null);
		if ($repair === null || (string)$tenant->organisation->getStatus() !== $repair[0]) {
			return false;
		}

		if ($run->dryRun === true) {
			return true;
		}

		$this->applyCorrectStatus(organisation: $tenant->organisation, status: $repair[1]);
		$run->mapper->update($tenant->organisation);
		$this->logRepair(tenant: $tenant, legacyStatus: $legacyStatus);

		return true;
	}//end repairSupersededStatus()

	/**
	 * Put the correct status on an Organisation, leaving the purgeable state cleanly.
	 *
	 * @param object $organisation The Organisation being repaired.
	 * @param string $status       The correct status.
	 *
	 * @return void
	 */
	private function applyCorrectStatus(object $organisation, string $status): void {
		$organisation->setStatus($status);
		if ($status !== self::STATUS_RETAINED) {
			return;
		}

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
	}//end applyCorrectStatus()

	/**
	 * Log a repaired status.
	 *
	 * @param LegacyTenant $tenant       The tenant, with its repaired Organisation.
	 * @param string       $legacyStatus The legacy status the repair is keyed by.
	 *
	 * @return void
	 */
	private function logRepair(LegacyTenant $tenant, string $legacyStatus): void {
		[$supersededStatus, $correctStatus] = self::SUPERSEDED_STATUS_REPAIRS[$legacyStatus];
		$this->logger->info(
			'Dossiq: repaired a tenant Organisation this migration had written a superseded status onto',
			[
				'slug' => $tenant->slug,
				'organisation' => (string)$tenant->organisation->getUuid(),
				'legacyStatus' => $legacyStatus,
				'from' => $supersededStatus,
				'to' => $correctStatus,
			],
		);
	}//end logRepair()

	/**
	 * Build an OR Organisation entity from a legacy tenant row.
	 *
	 * @param LegacyTenant $tenant The tenant; its uuid is preserved on the Organisation.
	 *
	 * @return object The unsaved Organisation entity.
	 */
	private function buildOrganisation(LegacyTenant $tenant): object {
		$organisation = new Organisation();

		// Preserve the tenant UUID so stored `_tenantId` references keep resolving.
		if ($tenant->uuid !== '') {
			$organisation->setUuid($tenant->uuid);
		}

		$organisation->setSlug($tenant->slug);
		$organisation->setName((string)($tenant->row['displayName'] ?? ($tenant->row['name'] ?? $tenant->slug)));
		$this->applyLifecycle(organisation: $organisation, row: $tenant->row);

		// The NC group used by procest for tenant routing.
		$groupId = (string)($tenant->row['groupId'] ?? (self::TENANT_GROUP_PREFIX . $tenant->slug));
		$organisation->setGroups([$groupId]);

		$this->applyStorageQuota(organisation: $organisation, row: $tenant->row);

		return $organisation;
	}//end buildOrganisation()

	/**
	 * Set the storage quota of a new Organisation, when the legacy row has one.
	 *
	 * @param object               $organisation The unsaved Organisation.
	 * @param array<string, mixed> $row          Legacy tenant object.
	 *
	 * @return void
	 */
	private function applyStorageQuota(object $organisation, array $row): void {
		$storageQuota = $this->resolveStorageQuotaBytes(row: $row);
		if ($storageQuota !== null) {
			$organisation->setStorageQuota($storageQuota);
		}
	}//end applyStorageQuota()

	/**
	 * Set the lifecycle status, active flag and retention start of a new Organisation.
	 *
	 * @param object               $organisation The unsaved Organisation.
	 * @param array<string, mixed> $row          Legacy tenant object.
	 *
	 * @return void
	 */
	private function applyLifecycle(object $organisation, array $row): void {
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

		$organisation->setActive($organisation->getStatus() === 'active');

		// A terminated tenant becomes `retained`, and the moment the retention
		// began is stamped on `retainedAt`, which is what openregister's own
		// retain() writes. `deprovisionedAt` is deliberately left untouched:
		// TenantPurgeJob measures its window from that column, so writing it
		// would schedule a deletion dossiq's termination does not have.
		if ($organisation->getStatus() === self::STATUS_RETAINED) {
			$organisation->setRetainedAt($this->resolveRetainedAt(row: $row));
		}
	}//end applyLifecycle()

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
	 * @return DateTime When retention began.
	 */
	private function resolveRetainedAt(array $row): DateTime {
		$terminatedAt = trim((string)($row['terminatedAt'] ?? ''));
		if ($terminatedAt !== '') {
			try {
				return new DateTime($terminatedAt);
			} catch (Throwable $e) {
				$this->logger->warning(
					'Dossiq: tenant migration could not read terminatedAt, stamping retention from now',
					['terminatedAt' => $terminatedAt],
				);
			}
		}

		return new DateTime();
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
		$lookup = new OrganisationLookup(appManager: $this->appManager, container: $this->container);
		return $lookup->mapper(logger: $this->logger, failureMessage: 'Dossiq: Could not get OrganisationMapper for tenant migration');
	}//end getOrganisationMapper()
}//end class
