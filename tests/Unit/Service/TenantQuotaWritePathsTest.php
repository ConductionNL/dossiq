<?php

/**
 * Tenant quota write paths test
 *
 * `getQuota()` reads the limit back and `persistQuota()` keeps it off the row.
 * Those two were tested when the limits moved onto the Organisation. The two
 * that WRITE a limit, `initialize()` and `setLimit()`, were not, and they are
 * the halves that decide where a limit ends up in the first place. A bug in
 * either puts the limit back on the row, or leaves the Organisation without
 * one, and `decide()` reads a null limit as unlimited, so the quota fails OPEN
 * and no response says so.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tenancy-onto-openregister-organisation/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\OrganisationQuotaLimits;
use OCA\Dossiq\Service\TenantQuotaService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\TenantQuotaService
 *
 * @uses \OCA\Dossiq\Command\Backfill\OpenRegisterRowNormaliser
 */
class TenantQuotaWritePathsTest extends TestCase {
	/**
	 * Every saveObject() payload, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saves = [];

	/**
	 * Every limit written onto the Organisation, in order.
	 *
	 * @var array<int, array{quotaType: string, limit: int|null}>
	 */
	private array $organisationWrites = [];

	/**
	 * The quota service over a fake OpenRegister and a recording limits collaborator.
	 *
	 * The collaborator is MODELLED, not stubbed with a fixed answer: `owns()`
	 * reads the real map, so a service that treated every type as mapped, or
	 * none, is caught rather than accommodated.
	 *
	 * @param array<int, mixed> $rows What `findAll()` returns.
	 *
	 * @return TenantQuotaService The service.
	 */
	private function quotaService(array $rows = []): TenantQuotaService {
		$objectService = $this->createMock(TenantLookupObjectServiceStub::class);
		$objectService->method('findAll')->willReturn($rows);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, string $register, string $schema, ?string $uuid = null): array {
				$this->saves[] = $object;

				return $object;
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$limits = $this->createMock(OrganisationQuotaLimits::class);
		$limits->method('owns')->willReturnCallback(
			static fn (string $quotaType): bool => array_key_exists($quotaType, OrganisationQuotaLimits::COLUMNS)
		);
		$limits->method('write')->willReturnCallback(
			function (string $tenantId, string $quotaType, ?int $limit): bool {
				$this->organisationWrites[] = ['quotaType' => $quotaType, 'limit' => $limit];

				return true;
			}
		);
		$limits->method('read')->willReturn(null);

		return new TenantQuotaService(
			appManager: $appManager,
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
			organisationLimits: $limits,
		);
	}

	/**
	 * Seeding a tier puts each limit in exactly one place.
	 *
	 * The whole point of decision 2b, asserted as a partition rather than as
	 * two separate facts: `storage_gb` and `api_calls_per_hour` go to the
	 * Organisation and carry NO `limit` on their row, `cases_per_month` and
	 * `active_users` keep theirs on the row and never reach the Organisation.
	 * Nothing may appear in both places, which is the drift this removes.
	 *
	 * @return void
	 */
	public function testSeedingATierPutsEachLimitInExactlyOnePlace(): void {
		$this->quotaService()->initialize(tenantId: 'tenant-a', tier: 'basic');

		$onTheRow = [];
		foreach ($this->saves as $row) {
			if (array_key_exists('limit', $row) === true) {
				$onTheRow[$row['quotaType']] = $row['limit'];
			}
		}

		$onTheOrganisation = [];
		foreach ($this->organisationWrites as $write) {
			$onTheOrganisation[$write['quotaType']] = $write['limit'];
		}

		$this->assertSame(['cases_per_month' => 100, 'active_users' => 5], $onTheRow);
		$this->assertSame(['storage_gb' => 10, 'api_calls_per_hour' => 1000], $onTheOrganisation);
		$this->assertSame(
			[],
			array_intersect_key($onTheRow, $onTheOrganisation),
			'no quota type may carry its limit in both places'
		);
	}

	/**
	 * Every quota type still gets a row, limit or no limit.
	 *
	 * Omitting the `limit` key must not omit the ROW. The row carries
	 * `currentUsage`, `resetAt`, `softLimitWarningPercent` and `enforcement`,
	 * which the Organisation has no home for, so a mapped type losing its row
	 * would lose its enforcement policy and its counter with it.
	 *
	 * @return void
	 */
	public function testEveryQuotaTypeStillGetsARowIncludingTheMappedTwo(): void {
		$this->quotaService()->initialize(tenantId: 'tenant-a', tier: 'standard');

		$written = array_column($this->saves, 'quotaType');
		sort($written);

		$this->assertSame(
			['active_users', 'api_calls_per_hour', 'cases_per_month', 'storage_gb'],
			$written
		);

		foreach ($this->saves as $row) {
			$this->assertSame('tenant-a', $row['tenantRef']);
			$this->assertSame(0, $row['currentUsage']);
			$this->assertArrayHasKey('enforcement', $row);
			$this->assertArrayHasKey('resetAt', $row);
		}
	}

	/**
	 * An unlimited tier writes null to the Organisation rather than skipping it.
	 *
	 * Skipping the write would leave whatever the Organisation carried before,
	 * so a tenant moved from basic to enterprise would keep the old ceiling
	 * while every screen said unlimited.
	 *
	 * @return void
	 */
	public function testAnUnlimitedTierWritesNullOntoTheOrganisation(): void {
		$this->quotaService()->initialize(tenantId: 'tenant-a', tier: 'enterprise');

		$byType = [];
		foreach ($this->organisationWrites as $write) {
			$byType[$write['quotaType']] = $write['limit'];
		}

		$this->assertArrayHasKey('storage_gb', $byType);
		$this->assertArrayHasKey('api_calls_per_hour', $byType);
		$this->assertNull($byType['storage_gb']);
		$this->assertNull($byType['api_calls_per_hour']);
	}

	/**
	 * Setting a mapped limit writes the Organisation and NOT the row.
	 *
	 * Writing both is the second copy decision 2b removes, and the row's copy
	 * is the one OpenRegister's own enforcement cannot see.
	 *
	 * @return void
	 */
	public function testSettingAMappedLimitWritesTheOrganisationAndNotTheRow(): void {
		$row = new ObjectEntity();
		$row->setUuid('quota-1');
		$row->setObject(['tenantRef' => 'tenant-a', 'quotaType' => 'api_calls_per_hour', 'currentUsage' => 4]);

		$quota = $this->quotaService(rows: [$row])
			->setLimit(tenantId: 'tenant-a', quotaType: 'api_calls_per_hour', limit: 250);

		$this->assertNotNull($quota);
		$this->assertSame(250, $quota['limit'], 'the caller must see the limit it just set');
		$this->assertSame(
			[['quotaType' => 'api_calls_per_hour', 'limit' => 250]],
			$this->organisationWrites
		);
		$this->assertSame([], $this->saves, 'a mapped limit must not be written back to the row');
	}

	/**
	 * Setting an unmapped limit writes the row and NOT the Organisation.
	 *
	 * The companion that catches a service which sent everything to the
	 * Organisation. `cases_per_month` has no column there, so the write would
	 * go nowhere and the limit would silently cease to exist.
	 *
	 * @return void
	 */
	public function testSettingAnUnmappedLimitWritesTheRowAndNotTheOrganisation(): void {
		$row = new ObjectEntity();
		$row->setUuid('quota-2');
		$row->setObject(['tenantRef' => 'tenant-a', 'quotaType' => 'cases_per_month', 'limit' => 10, 'currentUsage' => 1]);

		$quota = $this->quotaService(rows: [$row])
			->setLimit(tenantId: 'tenant-a', quotaType: 'cases_per_month', limit: 99);

		$this->assertNotNull($quota);
		$this->assertSame(99, $quota['limit']);
		$this->assertSame([], $this->organisationWrites, 'an unmapped type has no column to write to');
		$this->assertCount(1, $this->saves);
		$this->assertSame(99, $this->saves[0]['limit']);
	}

	/**
	 * Setting a limit on a tenant with no quota row changes nothing.
	 *
	 * There is no row to attach the policy to, and inventing one here would
	 * create a quota belonging to nobody.
	 *
	 * @return void
	 */
	public function testSettingALimitWithNoQuotaRowWritesNothingAnywhere(): void {
		$this->assertNull(
			$this->quotaService(rows: [])->setLimit(tenantId: 'tenant-a', quotaType: 'api_calls_per_hour', limit: 250)
		);
		$this->assertSame([], $this->organisationWrites);
		$this->assertSame([], $this->saves);
	}

	/**
	 * An unknown tier is refused before anything is written.
	 *
	 * @return void
	 */
	public function testAnUnknownTierIsRefusedBeforeAnythingIsWritten(): void {
		$this->expectException(\InvalidArgumentException::class);

		try {
			$this->quotaService()->initialize(tenantId: 'tenant-a', tier: 'platinum');
		} finally {
			$this->assertSame([], $this->saves);
			$this->assertSame([], $this->organisationWrites);
		}
	}
}//end class
