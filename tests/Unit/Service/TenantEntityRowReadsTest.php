<?php

/**
 * Tenant services read findAll() rows as entities
 *
 * PINNING TESTS for the tenant services that read `ObjectService::findAll()`
 * rows: the tenant configuration and the month's billing events. Onboarding
 * progress and step completion left these rows for OpenRegister's task engine
 * (remove-casetask 7.1); OnboardingStepsTest pins them now.
 *
 * `findAll()` returns `ObjectEntity` objects, never arrays, and `ObjectEntity`
 * does not implement `ArrayAccess`. Every one of these services indexed the
 * rows as arrays, so on a real install each one threw, or found nothing. Their
 * suites stayed green because no test ever handed them a row in the shape
 * production does. Every row below is an `ObjectEntity`, built the way
 * `findAll()` returns it, and each test that pins a read failed on the code
 * before the read was fixed.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/tenant-onboarding/spec.md#requirement-onboarding-checklist-and-progress-dashboard-req-003-a-req-003-d
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\ShillinqIntegrationService;
use OCA\Dossiq\Service\Tenant\TenantBrandingSanitiser;
use OCA\Dossiq\Service\TenantBillingService;
use OCA\Dossiq\Service\TenantConfigurationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * The `findAll()` and `saveObject()` signatures the tenant services call.
 *
 * Both return what OpenRegister returns: `findAll()` a list of `ObjectEntity`
 * objects and `saveObject()` one `ObjectEntity`.
 */
interface TenantEntityRowObjectServiceStub {
	/**
	 * Find objects.
	 *
	 * @param array<string, mixed> $config Query configuration.
	 * @param bool $_rbac Whether RBAC applies.
	 * @param bool $_multitenancy Whether multitenancy applies.
	 *
	 * @return array<int, mixed> The rows.
	 */
	public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array;

	/**
	 * Save an object.
	 *
	 * @param array<string, mixed> $object The object data.
	 * @param string $register The register.
	 * @param string $schema The schema.
	 * @param string|null $uuid The uuid to update, or null to create.
	 *
	 * @return ObjectEntity The saved object.
	 */
	public function saveObject(array $object, string $register, string $schema, ?string $uuid = null): ObjectEntity;
}

/**
 * @covers \OCA\Dossiq\Service\TenantConfigurationService
 * @covers \OCA\Dossiq\Service\TenantBillingService
 *
 * @uses \OCA\Dossiq\Command\Backfill\OpenRegisterRowNormaliser
 * @uses \OCA\Dossiq\Service\Tenant\TenantBrandingSanitiser
 */
class TenantEntityRowReadsTest extends TestCase {
	/**
	 * The config every `findAll()` call received, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * Every `saveObject()` call, in order.
	 *
	 * @var array<int, array{object: array<string, mixed>, schema: string, uuid: string|null}>
	 */
	private array $saves = [];

	/**
	 * A row in the shape `findAll()` returns it.
	 *
	 * @param array<string, mixed> $object The object data.
	 * @param string $uuid The object's uuid.
	 *
	 * @return ObjectEntity The row.
	 */
	private function entity(array $object, string $uuid): ObjectEntity {
		$row = new ObjectEntity();
		$row->setUuid($uuid);
		$row->setObject($object);

		return $row;
	}//end entity()

	/**
	 * An app manager and container whose ObjectService answers with $rows.
	 *
	 * @param array<int, mixed> $rows What `findAll()` returns.
	 *
	 * @return array{0: IAppManager, 1: ContainerInterface} The app manager and container.
	 */
	private function openRegisterAnswering(array $rows): array {
		$objectService = $this->createMock(TenantEntityRowObjectServiceStub::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($rows): array {
				$this->queries[] = $config;

				return $rows;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, string $register, string $schema, ?string $uuid = null): ObjectEntity {
				$this->saves[] = ['object' => $object, 'schema' => $schema, 'uuid' => $uuid];

				return $this->entity(object: $object, uuid: ($uuid ?? 'created'));
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		return [$appManager, $container];
	}//end openRegisterAnswering()

	/**
	 * The configuration service over a fake OpenRegister.
	 *
	 * @param array<int, mixed> $rows What `findAll()` returns.
	 *
	 * @return TenantConfigurationService The service.
	 */
	private function configurationAnswering(array $rows): TenantConfigurationService {
		[$appManager, $container] = $this->openRegisterAnswering(rows: $rows);

		return new TenantConfigurationService(
			appManager: $appManager,
			container: $container,
			sanitiser: new TenantBrandingSanitiser(),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end configurationAnswering()

	/**
	 * A stored configuration is found when its row is an entity.
	 *
	 * Before the read was fixed, `getConfig()` returned the entity from a
	 * method typed `?array`. The TypeError was caught and read as "no
	 * configuration", so no tenant ever had branding, a locale or a flag.
	 *
	 * @return void
	 */
	public function testTheConfigurationOfAnEntityRowIsFound(): void {
		$row = $this->entity(
			object: [
				'tenantRef' => 't-1',
				'branding' => ['primaryColor' => '#123456'],
				'features' => ['beta-search'],
			],
			uuid: 'cfg-1'
		);

		$config = $this->configurationAnswering(rows: [$row])->getConfig(tenantId: 't-1');

		$this->assertNotNull($config, 'the stored configuration was not found');
		$this->assertSame('t-1', $config['tenantRef']);
		$this->assertSame(['beta-search'], $config['features']);
		$this->assertSame('#123456', $config['branding']['primaryColor']);
	}//end testTheConfigurationOfAnEntityRowIsFound()

	/**
	 * Setting a flag keeps the flags the stored row already carries.
	 *
	 * This is what a configuration nobody can read costs: every write starts
	 * from an empty configuration and drops what was there.
	 *
	 * @return void
	 */
	public function testSettingAFlagKeepsTheFlagsAlreadyStored(): void {
		$row = $this->entity(object: ['tenantRef' => 't-1', 'features' => ['beta-search']], uuid: 'cfg-1');

		$next = $this->configurationAnswering(rows: [$row])->setFeatureFlag(tenantId: 't-1', flag: 'beta-export', enabled: true);

		$this->assertSame(['beta-search', 'beta-export'], $next['features']);
		$this->assertCount(1, $this->saves);
		$this->assertSame(['beta-search', 'beta-export'], $this->saves[0]['object']['features']);
		$this->assertSame('tenantConfiguration', $this->saves[0]['schema']);
	}//end testSettingAFlagKeepsTheFlagsAlreadyStored()

	/**
	 * A row that carries nothing readable is not a configuration.
	 *
	 * This passes on the code before the fix too, where the row was returned
	 * and the TypeError caught; it guards the fix's own empty-row check.
	 *
	 * @return void
	 */
	public function testAnUnreadableConfigurationRowIsNotAConfiguration(): void {
		$this->assertNull($this->configurationAnswering(rows: [new stdClass()])->getConfig(tenantId: 't-1'));
	}//end testAnUnreadableConfigurationRowIsNotAConfiguration()

	/**
	 * The billing service over a fake OpenRegister.
	 *
	 * @param array<int, mixed> $rows What `findAll()` returns.
	 *
	 * @return TenantBillingService The service.
	 */
	private function billingAnswering(array $rows): TenantBillingService {
		[$appManager, $container] = $this->openRegisterAnswering(rows: $rows);

		return new TenantBillingService(
			appManager: $appManager,
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
			shillinq: $this->createMock(ShillinqIntegrationService::class),
		);
	}//end billingAnswering()

	/**
	 * A billing event row in the shape `findAll()` returns it.
	 *
	 * @param string $uuid The event's uuid.
	 * @param string $occurredAt When it occurred.
	 * @param float $unitPrice What one unit costs.
	 *
	 * @return ObjectEntity The row.
	 */
	private function billingEvent(string $uuid, string $occurredAt, float $unitPrice): ObjectEntity {
		return $this->entity(
			object: [
				'tenantRef' => 't-1',
				'eventType' => 'case_created',
				'quantity' => 1.0,
				'unitPrice' => $unitPrice,
				'currency' => 'EUR',
				'occurredAt' => $occurredAt,
				'invoiceRef' => null,
			],
			uuid: $uuid
		);
	}//end billingEvent()

	/**
	 * The month's billing is aggregated from the rows, and only that month's.
	 *
	 * Before the read was fixed, `$r['occurredAt']` on an `ObjectEntity` threw
	 * outside any catch, so every billing figure and every invoice run for a
	 * tenant with events died where it started.
	 *
	 * @return void
	 */
	public function testTheMonthsBillingIsAggregatedFromEntityRows(): void {
		$rows = [
			$this->billingEvent(uuid: 'e-1', occurredAt: '2026-07-05T10:00:00+00:00', unitPrice: 10.0),
			$this->billingEvent(uuid: 'e-2', occurredAt: '2026-07-28T09:00:00+00:00', unitPrice: 15.0),
			$this->billingEvent(uuid: 'e-3', occurredAt: '2026-08-01T09:00:00+00:00', unitPrice: 99.0),
		];

		$summary = $this->billingAnswering(rows: $rows)->getMonthBilling(tenantId: 't-1', month: '2026-07');

		$this->assertSame(2, $summary['eventCount'], 'an event from another month was billed');
		$this->assertSame(25.0, $summary['totalAmount']);
	}//end testTheMonthsBillingIsAggregatedFromEntityRows()

	/**
	 * A row that carries nothing readable is not billed.
	 *
	 * @return void
	 */
	public function testAnUnreadableBillingRowIsNotBilled(): void {
		$rows = [
			new stdClass(),
			$this->billingEvent(uuid: 'e-1', occurredAt: '2026-07-05T10:00:00+00:00', unitPrice: 10.0),
		];

		$summary = $this->billingAnswering(rows: $rows)->getMonthBilling(tenantId: 't-1', month: '2026-07');

		$this->assertSame(1, $summary['eventCount']);
		$this->assertSame(10.0, $summary['totalAmount']);
	}//end testAnUnreadableBillingRowIsNotBilled()
}//end class
