<?php

/**
 * Organisation Quota Limits test
 *
 * The unit conversion between `storage_gb` and `storageQuota` is tested here,
 * against the real class, because `TenantScopedLookupsTest` models this
 * collaborator with a mock and a mock's conversion is not evidence about the
 * code's.
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
use OCA\Dossiq\Service\TenantOrganisationResolver;
use OCA\OpenRegister\Db\Organisation;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The `update` seam the writer calls on OpenRegister's mapper.
 */
interface QuotaLimitMapperStub {
	/**
	 * Persist an Organisation.
	 *
	 * @param Organisation $organisation The organisation.
	 *
	 * @return Organisation The organisation.
	 */
	public function update(Organisation $organisation): Organisation;
}

/**
 * @covers \OCA\Dossiq\Service\OrganisationQuotaLimits
 */
class OrganisationQuotaLimitsTest extends TestCase {
	/**
	 * Organisations handed to the mapper's update(), in order.
	 *
	 * @var array<int, Organisation>
	 */
	private array $updated = [];

	/**
	 * The limits reader and writer over one Organisation.
	 *
	 * @param Organisation|null $organisation What the resolver answers with.
	 *
	 * @return OrganisationQuotaLimits The collaborator.
	 */
	private function limitsFor(?Organisation $organisation): OrganisationQuotaLimits {
		$mapper = $this->createMock(QuotaLimitMapperStub::class);
		$mapper->method('update')->willReturnCallback(
			function (Organisation $org): Organisation {
				$this->updated[] = $org;

				return $org;
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mapper);

		$resolver = $this->createMock(TenantOrganisationResolver::class);
		$resolver->method('findOrganisation')->willReturn($organisation);

		return new OrganisationQuotaLimits(
			appManager: $appManager,
			container: $container,
			organisations: $resolver,
			logger: $this->createMock(LoggerInterface::class),
		);
	}

	/**
	 * An Organisation with the given uuid.
	 *
	 * @return Organisation The organisation.
	 */
	private function organisation(): Organisation {
		$organisation = new Organisation();
		$organisation->setUuid('tenant-a');

		return $organisation;
	}

	/**
	 * Exactly two quota types have their limit on the Organisation.
	 *
	 * The other two have no column, so decision 2b keeps them as rows. A map
	 * that grew a third entry would blank a limit nothing else carries, and
	 * `decide()` reads a null limit as unlimited, so that quota would fail OPEN.
	 *
	 * @return void
	 */
	public function testOnlyStorageAndRequestsAreOwnedByTheOrganisation(): void {
		$limits = $this->limitsFor($this->organisation());

		$this->assertTrue($limits->owns(quotaType: 'storage_gb'));
		$this->assertTrue($limits->owns(quotaType: 'api_calls_per_hour'));
		$this->assertFalse($limits->owns(quotaType: 'cases_per_month'));
		$this->assertFalse($limits->owns(quotaType: 'active_users'));
		$this->assertSame(
			['storage_gb', 'api_calls_per_hour'],
			array_keys(OrganisationQuotaLimits::COLUMNS)
		);
	}

	/**
	 * Gigabytes are read back out of a byte column, not straight through.
	 *
	 * Read straight through, a 10 GB limit reads as 10737418240 and a tenant
	 * has roughly a billion times the headroom it was given. Nothing in the
	 * response would say so, because the number is a perfectly valid limit.
	 *
	 * @return void
	 */
	public function testAStorageLimitIsReadBackInGigabytes(): void {
		$organisation = $this->organisation();
		$organisation->setStorageQuota((10 * 1073741824));

		$this->assertSame(10, $this->limitsFor($organisation)->read(tenantId: 'tenant-a', quotaType: 'storage_gb'));
	}

	/**
	 * Gigabytes are written into a byte column, not straight through.
	 *
	 * @return void
	 */
	public function testAStorageLimitIsWrittenInBytes(): void {
		$organisation = $this->organisation();

		$this->assertTrue(
			$this->limitsFor($organisation)->write(tenantId: 'tenant-a', quotaType: 'storage_gb', limit: 10)
		);
		$this->assertCount(1, $this->updated);
		$this->assertSame((10 * 1073741824), $this->updated[0]->getStorageQuota());
	}

	/**
	 * A storage limit survives a write and a read unchanged.
	 *
	 * The round trip is the assertion that catches a conversion applied in one
	 * direction only, which each half alone would pass.
	 *
	 * @return void
	 */
	public function testAStorageLimitSurvivesAWriteAndAReadUnchanged(): void {
		$organisation = $this->organisation();
		$limits = $this->limitsFor($organisation);

		$limits->write(tenantId: 'tenant-a', quotaType: 'storage_gb', limit: 250);

		$this->assertSame(250, $limits->read(tenantId: 'tenant-a', quotaType: 'storage_gb'));
	}

	/**
	 * Requests are counted in whole requests, so nothing is converted.
	 *
	 * @return void
	 */
	public function testARequestLimitIsNotConverted(): void {
		$organisation = $this->organisation();
		$limits = $this->limitsFor($organisation);

		$limits->write(tenantId: 'tenant-a', quotaType: 'api_calls_per_hour', limit: 1000);

		$this->assertSame(1000, $this->updated[0]->getRequestQuota());
		$this->assertSame(1000, $limits->read(tenantId: 'tenant-a', quotaType: 'api_calls_per_hour'));
	}

	/**
	 * An unlimited quota is stored as null, not as zero.
	 *
	 * Zero is a limit of nothing, which would refuse every request. Null is
	 * what `decide()` reads as unlimited.
	 *
	 * @return void
	 */
	public function testUnlimitedIsStoredAsNullAndNotAsZero(): void {
		$organisation = $this->organisation();
		$organisation->setRequestQuota(500);
		$limits = $this->limitsFor($organisation);

		$limits->write(tenantId: 'tenant-a', quotaType: 'api_calls_per_hour', limit: null);

		$this->assertNull($this->updated[0]->getRequestQuota());
		$this->assertNotSame(0, $this->updated[0]->getRequestQuota());
		$this->assertNull($limits->read(tenantId: 'tenant-a', quotaType: 'api_calls_per_hour'));
	}

	/**
	 * A tenant with no Organisation reads as unlimited and writes nothing.
	 *
	 * The fail-open is deliberate and named. Refusing traffic because an
	 * Organisation lookup came back empty would take a tenant offline over an
	 * unavailable service, and OpenRegister's own TenantQuotaMiddleware is
	 * what actually enforces requestQuota.
	 *
	 * @return void
	 */
	public function testATenantWithNoOrganisationReadsUnlimitedAndWritesNothing(): void {
		$limits = $this->limitsFor(null);

		$this->assertNull($limits->read(tenantId: 'ghost', quotaType: 'api_calls_per_hour'));
		$this->assertFalse($limits->write(tenantId: 'ghost', quotaType: 'api_calls_per_hour', limit: 10));
		$this->assertSame([], $this->updated);
	}

	/**
	 * An unmapped quota type is neither read from nor written to the entity.
	 *
	 * @return void
	 */
	public function testAnUnmappedQuotaTypeIsNeverReadOrWritten(): void {
		$limits = $this->limitsFor($this->organisation());

		$this->assertNull($limits->read(tenantId: 'tenant-a', quotaType: 'cases_per_month'));
		$this->assertFalse($limits->write(tenantId: 'tenant-a', quotaType: 'cases_per_month', limit: 100));
		$this->assertSame([], $this->updated);
	}
	/**
	 * Without OpenRegister a limit write fails and says so.
	 *
	 * `write()` returning true on an instance with nowhere to write would have
	 * `initialize()` seed a tier, report success, and leave every mapped quota
	 * unlimited. `decide()` reads a null limit as unlimited, so the failure
	 * would surface as a tenant with no ceiling rather than as an error.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterAWriteFailsRatherThanReportingSuccess(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['dossiq']);

		$organisation = $this->organisation();
		$resolver = $this->createMock(TenantOrganisationResolver::class);
		$resolver->method('findOrganisation')->willReturn($organisation);

		$limits = new OrganisationQuotaLimits(
			appManager: $appManager,
			container: $this->createMock(ContainerInterface::class),
			organisations: $resolver,
			logger: $this->createMock(LoggerInterface::class),
		);

		$this->assertFalse($limits->write(tenantId: 'tenant-a', quotaType: 'api_calls_per_hour', limit: 10));
		$this->assertSame([], $this->updated);
		$this->assertNull(
			$organisation->getRequestQuota(),
			'nothing may be left half-written on the entity when the store is unreachable'
		);
	}

}//end class
