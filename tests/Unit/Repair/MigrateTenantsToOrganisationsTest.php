<?php

/**
 * The tenant migration runs as a repair step for one release and reports what is left.
 *
 * Built on the real repair step and TenantMigrationService, with only the two
 * OpenRegister seams doubled.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/tenancy-onto-openregister-organisation/specs/tenant-organisation-boundary/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\MigrateTenantsToOrganisations;
use OCA\Dossiq\Service\SatelliteOrphanScanner;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TenantMigrationService;
use OCA\Dossiq\Service\TenantOrganisationResolver;
use OCA\Dossiq\Service\TenantService;
use OCA\Dossiq\Tests\Support\EntityAnsweringRegister;
use OCA\Dossiq\Tests\Support\MakesTenantMigration;
use OCA\Dossiq\Tests\Support\RefusableRegister;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Repair\MigrateTenantsToOrganisations
 *
 * @uses \OCA\Dossiq\Service\TenantMigrationService
 * @uses \OCA\Dossiq\Service\TenantOrganisationResolver
 * @uses \OCA\Dossiq\Service\TenantService
 */
class MigrateTenantsToOrganisationsTest extends TestCase {
	use MakesTenantMigration;

	/**
	 * What the step wrote to its output: info and warning lines.
	 *
	 * @var array{info: array<int, string>, warning: array<int, string>}
	 */
	private array $said = ['info' => [], 'warning' => []];

	/**
	 * Run the real step over the given tenants.
	 *
	 * @param array<int, array<string, mixed>> $tenants The legacy tenant rows.
	 *
	 * @return void
	 */
	private function runStep(array $tenants): void {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(function (string $line): void {
			$this->said['info'][] = $line;
		});
		$output->method('warning')->willReturnCallback(function (string $line): void {
			$this->said['warning'][] = $line;
		});

		(new MigrateTenantsToOrganisations(
			migration: $this->realMigration(tenants: $tenants),
			settingsService: $this->createMock(SettingsService::class),
			logger: $this->recordingLogger(),
			tenants: $this->createMock(TenantService::class),
		))->run($output);
	}

	/**
	 * One in-memory OpenRegister for the whole chain: the legacy tenants, the
	 * satellites, and the tenant objects anchors are written to.
	 *
	 * @param RefusableRegister $store The rows.
	 *
	 * @return object An ObjectService with the methods the chain calls.
	 */
	private function objectServiceOver(RefusableRegister $store): object {
		return new class($store) {
			private EntityAnsweringRegister $entities;

			public function __construct(private readonly RefusableRegister $store) {
				$this->entities = new EntityAnsweringRegister(register: $store);
			}

			public function searchObjectsBySlug(string $register, string $schema, array $filters = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return $this->store->searchObjectsBySlug(register: $register, schema: $schema, filters: $filters);
			}

			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return $this->store->findAll(config: $config);
			}

			public function find(int|string $id, mixed $_extend = null, bool $files = false, int|string $register = '', int|string $schema = '', bool $_rbac = true, bool $_multitenancy = true): object {
				return $this->entities->find(id: $id, register: $register, schema: $schema);
			}

			public function saveObject(array $object, int|string $register = '', int|string $schema = '', ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				return $this->store->saveObject(object: $object, register: $register, schema: $schema, uuid: $uuid);
			}
		};
	}

	/**
	 * Run the real step, migration and anchors both real, over one store.
	 *
	 * @param RefusableRegister $store The rows.
	 *
	 * @return void
	 */
	private function runRealStepOver(RefusableRegister $store): void {
		$objects = $this->objectServiceOver(store: $store);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister', 'dossiq']);

		$organisations = $this->organisations;
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => match ($id) {
				'OCA\\OpenRegister\\Db\\OrganisationMapper' => $organisations,
				'OCA\\OpenRegister\\Service\\ObjectService' => $objects,
				default => throw new RuntimeException('unknown service '.$id),
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$tenants = new TenantService(
			groupManager: $this->createMock(IGroupManager::class),
			organisations: new TenantOrganisationResolver(appManager: $apps, container: $container, logger: $logger),
			appManager: $apps,
			container: $container,
			logger: $logger,
		);

		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(function (string $line): void {
			$this->said['info'][] = $line;
		});

		(new MigrateTenantsToOrganisations(
			migration: new TenantMigrationService($settings, $container, $apps, $this->recordingLogger()),
			settingsService: $settings,
			logger: $this->recordingLogger(),
			tenants: $tenants,
		))->run($output);
	}

	/**
	 * The step migrates and reports what is left (REQ-TOO-001).
	 *
	 * @return void
	 */
	public function testTheRepairMigratesAndReportsWhatIsLeft(): void {
		$this->startOrganisations(existing: [$this->storedOrganisation(uuid: 'org-else', slug: 'gemeente-eemnes')]);

		$this->runStep(tenants: [
			['id' => 't-1', 'slug' => 'gemeente-baarn', 'status' => 'active', 'displayName' => 'Gemeente Baarn'],
			['id' => 't-2', 'slug' => 'gemeente-eemnes', 'status' => 'active', 'displayName' => 'Gemeente Eemnes'],
		]);

		$this->assertSame(['t-1'], array_map(static fn ($o): string => (string) $o->getUuid(), $this->organisations->inserted));
		$this->assertStringContainsString('unmigrated = 1', implode("\n", $this->said['info']));
		$this->assertCount(1, $this->said['warning'], 'a count above zero is a warning in the repair output');
		$this->assertContains(1, array_map(static fn (array $line): int => (int) ($line[1]['unmigrated'] ?? -1), $this->migrationWarnings), 'and in the log, at warning level');
	}

	/**
	 * A refused collision is counted as unmigrated and never mapped (REQ-TOO-001).
	 *
	 * @return void
	 */
	public function testARefusedCollisionIsCountedAsUnmigrated(): void {
		$this->startOrganisations(existing: [$this->storedOrganisation(uuid: 'org-else', slug: 'gemeente-baarn')]);

		$this->runStep(tenants: [['id' => 't-3', 'slug' => 'gemeente-baarn', 'status' => 'active', 'displayName' => 'Gemeente Baarn']]);

		$this->assertSame([], $this->organisations->inserted, 'a refused collision is never mapped');
		$this->assertStringContainsString('refused 1', implode("\n", $this->said['info']));
		$this->assertStringContainsString('unmigrated = 1', implode("\n", $this->said['info']));
	}

	/**
	 * A second run migrates nothing and reports zero (REQ-TOO-001).
	 *
	 * @return void
	 */
	public function testASecondRunMigratesNothingAndReportsZero(): void {
		$this->startOrganisations(existing: []);
		$tenants = [['id' => 't-1', 'slug' => 'gemeente-baarn', 'status' => 'active', 'displayName' => 'Gemeente Baarn']];

		$this->runStep(tenants: $tenants);
		$this->said = ['info' => [], 'warning' => []];
		$this->runStep(tenants: $tenants);

		$this->assertCount(1, $this->organisations->inserted, 'the second run must insert nothing');
		$this->assertStringContainsString('migrated 0', implode("\n", $this->said['info']));
		$this->assertStringContainsString('unmigrated = 0', implode("\n", $this->said['info']));
		$this->assertSame([], $this->said['warning']);
	}

	/**
	 * An Organisation a tenantUser row points at, with no anchor, gets one (REQ-TOO-006).
	 *
	 * `org-new` was created in OpenRegister after the migration and has a
	 * member but no tenant object. `org-old` has its anchor already, and is
	 * left alone. A member row pointing at nothing gets no anchor.
	 *
	 * @return void
	 */
	public function testAMemberOrganisationWithoutAnAnchorGetsOne(): void {
		$this->startOrganisations(existing: [$this->storedOrganisation(uuid: 'org-new', slug: 'gemeente-laren'), $this->storedOrganisation(uuid: 'org-old', slug: 'gemeente-blaricum')]);
		$store = new RefusableRegister();
		$store->seed(schema: 'tenant', uuid: 'org-old', row: ['slug' => 'gemeente-blaricum', 'displayName' => 'Gemeente Blaricum', 'status' => 'active', 'tier' => 'basic']);
		$store->seed(schema: 'tenantUser', uuid: 'u-1', row: ['tenantRef' => 'org-new', 'userRef' => 'anna']);
		$store->seed(schema: 'tenantUser', uuid: 'u-2', row: ['tenantRef' => 'org-old', 'userRef' => 'bram']);
		$store->seed(schema: 'tenantUser', uuid: 'u-3', row: ['tenantRef' => 'org-gone', 'userRef' => 'cor']);

		$this->runRealStepOver(store: $store);

		$this->assertSame(['gemeente-laren'], array_column(array_filter($store->all(schema: 'tenant'), static fn (array $r): bool => $r['id'] === 'org-new'), 'slug'));
		$this->assertSame('Gemeente Blaricum', $store->row(schema: 'tenant', uuid: 'org-old')['displayName'], 'an existing anchor is left alone');
		$this->assertSame([], $store->row(schema: 'tenant', uuid: 'org-gone'), 'no Organisation, no anchor');
		$this->assertStringContainsString('2 of 3 organisations with members have their tenant audit anchor', implode("\n", $this->said['info']));
	}

	/**
	 * After the real run every satellite row resolves to an Organisation (REQ-TOO-001).
	 *
	 * The dry run on dossiq#3466 reported 15 satellite rows pointing at the
	 * three tenants that had no Organisation yet. A dry run cannot prove the
	 * real run fixes that, so this does: the satellites keep their tenantRef,
	 * the migration gives each tenant an Organisation of the same uuid, and
	 * the orphan scan then finds nothing.
	 *
	 * @return void
	 */
	public function testAfterTheRealRunEverySatelliteRowResolves(): void {
		$this->startOrganisations(existing: []);
		$store = new RefusableRegister();
		$tenants = ['t-a' => 'gemeente-huizen', 't-b' => 'gemeente-weesp', 't-c' => 'gemeente-muiden'];
		foreach ($tenants as $uuid => $slug) {
			$store->seed(schema: 'tenant', uuid: $uuid, row: ['slug' => $slug, 'displayName' => ucfirst($slug), 'status' => 'active', 'tier' => 'standard']);
			foreach (['tenantConfiguration', 'tenantQuota', 'tenantUser', 'tenantMandate', 'tenantBillingEvent'] as $satellite) {
				$store->seed(schema: $satellite, uuid: $satellite.'-'.$uuid, row: ['tenantRef' => $uuid]);
			}
		}

		$scan = function () use ($store): array {
			$objects = $this->objectServiceOver(store: $store);
			$settings = $this->createMock(SettingsService::class);
			$settings->method('getObjectService')->willReturn($objects);
			$apps = $this->createMock(IAppManager::class);
			$apps->method('getInstalledApps')->willReturn(['openregister']);
			$organisations = $this->organisations;
			$container = $this->createMock(ContainerInterface::class);
			$container->method('get')->willReturn($organisations);

			return (new SatelliteOrphanScanner($settings, $container, $apps, $this->createMock(LoggerInterface::class)))->reportOrphans();
		};

		$this->assertSame(15, $scan()['orphans'], 'before the run, as the dry run reported');

		$this->runRealStepOver(store: $store);

		$this->assertSame(['t-a', 't-b', 't-c'], array_map(static fn ($o): string => (string) $o->getUuid(), $this->organisations->inserted));
		$this->assertSame(0, $scan()['orphans'], 'after the run every satellite row resolves to its Organisation');
		foreach (array_keys($tenants) as $uuid) {
			$this->assertSame($uuid, $store->row(schema: 'tenantUser', uuid: 'tenantUser-'.$uuid)['tenantRef'], 'the stored reference is kept, not rewritten');
		}
	}

	/**
	 * The step is registered under post-migration in appinfo/info.xml.
	 *
	 * @return void
	 */
	public function testTheMigrationStepIsRegistered(): void {
		$info = simplexml_load_file(dirname(__DIR__, 3).'/appinfo/info.xml');
		$this->assertNotFalse($info);
		$steps = array_map('strval', iterator_to_array($info->{'repair-steps'}->{'post-migration'}->step, false));

		$this->assertContains(MigrateTenantsToOrganisations::class, $steps);
	}
}
