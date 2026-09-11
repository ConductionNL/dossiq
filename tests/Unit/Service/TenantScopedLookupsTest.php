<?php

/**
 * Tenant-scoped lookup pinning tests
 *
 * PINNING TESTS for the four OpenRegister lookups that decide whose rows a
 * tenant request reads: a user's memberships, their role inside a tenant, the
 * tenant's mandate matrix and its quota rows. Every one of them scopes by a
 * filter on `tenantRef` or `userRef`, and every one of them is re-pointed when
 * the tenant moves onto OpenRegister's Organisation.
 *
 * Measured before these existed (2026-09-11): deleting the `tenantRef` or
 * `userRef` filter from any of the four left the whole tenancy suite green. A
 * scoping filter no test notices is not a check, and those are exactly the
 * filters the move rewrites.
 *
 * The fake answers `findAll()` the way OpenRegister does. Measured on the dev
 * instance: `ObjectService::findAll()` returns `ObjectEntity` objects, never
 * arrays, and a filter on a property the schema lacks returns no rows rather
 * than every row.
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

use OCA\Dossiq\Service\TenantAuthenticationService;
use OCA\Dossiq\Service\TenantQuotaService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The named-argument-free `findAll()` signature the lookups call.
 *
 * A `getMockBuilder(\stdClass::class)->addMethods()` stub is deprecated in
 * PHPUnit 10, so the seam is declared here instead.
 */
interface TenantLookupObjectServiceStub {
	/**
	 * Find objects.
	 *
	 * @param array<string, mixed> $config        Query configuration.
	 * @param bool                 $_rbac         Whether RBAC applies.
	 * @param bool                 $_multitenancy Whether multitenancy applies.
	 *
	 * @return array<int, mixed> The rows.
	 */
	public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array;
}

/**
 * @covers \OCA\Dossiq\Service\TenantAuthenticationService
 * @covers \OCA\Dossiq\Service\TenantQuotaService
 */
class TenantScopedLookupsTest extends TestCase {
	/**
	 * The config every `findAll()` call received, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * Build a container whose ObjectService records each query and answers with $rows.
	 *
	 * @param array<int, mixed> $rows What `findAll()` returns.
	 *
	 * @return array{0: IAppManager, 1: ContainerInterface} The app manager and container.
	 */
	private function openRegisterAnswering(array $rows): array {
		$objectService = $this->createMock(TenantLookupObjectServiceStub::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($rows): array {
				$this->queries[] = $config;
				return $rows;
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		return [$appManager, $container];
	}

	/**
	 * The authentication service over a fake OpenRegister.
	 *
	 * @param array<int, mixed> $rows What `findAll()` returns.
	 *
	 * @return TenantAuthenticationService The service.
	 */
	private function authAnswering(array $rows): TenantAuthenticationService {
		[$appManager, $container] = $this->openRegisterAnswering(rows: $rows);

		return new TenantAuthenticationService(
			appManager: $appManager,
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
		);
	}

	/**
	 * The filters of the only query made.
	 *
	 * @return array<string, mixed> The filters.
	 */
	private function onlyFilters(): array {
		$this->assertCount(1, $this->queries);

		return (array)($this->queries[0]['filters'] ?? []);
	}

	/**
	 * A user's memberships are read for THAT user only.
	 *
	 * Without the `userRef` filter the lookup reads every membership in the
	 * register, and the session would let anyone switch to any tenant that has
	 * a member.
	 *
	 * @return void
	 */
	public function testTheMembershipLookupIsScopedToTheUser(): void {
		$this->authAnswering(rows: [])->listTenantsForUser(userId: 'alice');

		$filters = $this->onlyFilters();
		$this->assertSame('tenantUser', $filters['schema']);
		$this->assertSame('alice', $filters['userRef']);
	}

	/**
	 * A user's role is read inside the tenant asked about, not any tenant.
	 *
	 * The lookup takes the first row it gets. Without the `tenantRef` filter
	 * that row is the user's role in whichever tenant OpenRegister lists first.
	 *
	 * @return void
	 */
	public function testTheRoleLookupIsScopedToTheTenantAndTheUser(): void {
		$this->assertNull($this->authAnswering(rows: [])->resolveUserRole(tenantId: 'tenant-a', userId: 'alice'));

		$filters = $this->onlyFilters();
		$this->assertSame('tenantUser', $filters['schema']);
		$this->assertSame('tenant-a', $filters['tenantRef']);
		$this->assertSame('alice', $filters['userRef']);
	}

	/**
	 * A tenant's mandate matrix is read from that tenant's mandates only.
	 *
	 * Without the `tenantRef` filter the active window of ANOTHER tenant's
	 * mandate decides what this tenant's users may do.
	 *
	 * @return void
	 */
	public function testTheMandateLookupIsScopedToTheTenant(): void {
		$this->assertNull($this->authAnswering(rows: [])->loadActiveMatrix(tenantId: 'tenant-a'));

		$filters = $this->onlyFilters();
		$this->assertSame('tenantMandate', $filters['schema']);
		$this->assertSame('tenant-a', $filters['tenantRef']);
	}

	/**
	 * A quota row is read for the tenant being charged.
	 *
	 * Without the `tenantRef` filter one tenant's traffic is counted against
	 * another's allowance, and neither response says so.
	 *
	 * @return void
	 */
	public function testTheQuotaLookupIsScopedToTheTenant(): void {
		[$appManager, $container] = $this->openRegisterAnswering(rows: []);
		$quota = new TenantQuotaService(
			appManager: $appManager,
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
		);

		$this->assertNull($quota->getQuota(tenantId: 'tenant-a', quotaType: 'cases_per_month'));

		$filters = $this->onlyFilters();
		$this->assertSame('tenantQuota', $filters['schema']);
		$this->assertSame('tenant-a', $filters['tenantRef']);
		$this->assertSame('cases_per_month', $filters['quotaType']);
	}

	/**
	 * No membership means no membership, whatever tenant is asked about.
	 *
	 * @return void
	 */
	public function testSomeoneWithNoMembershipIsAMemberOfNothing(): void {
		$this->assertFalse($this->authAnswering(rows: [])->isMemberOf(tenantId: 'tenant-a', userId: 'alice'));
	}

	/**
	 * PINNED AS-IS, AND A DEFECT: a membership row as OpenRegister returns it is dropped.
	 *
	 * `listTenantsForUser()` keeps only rows that are arrays, and
	 * `ObjectService::findAll()` returns `ObjectEntity` objects. So on a real
	 * install no user has a membership, the session never resolves a tenant,
	 * and the whole SaaS middleware chain (context, isolation, claim, mandate,
	 * quota) sees an unbound request and steps aside.
	 *
	 * Not fixed here, on purpose. Fixing only this lookup would bind tenants,
	 * and the mandate gate would then read the role and the matrix through
	 * `resolveUserRole()` and `loadActiveMatrix()`, which index the same
	 * entities as arrays, throw, and deny every write by every member. The
	 * three have to move together, and the tenancy move rewrites all three.
	 * This test turning red is the signal that someone has.
	 *
	 * @return void
	 */
	public function testAMembershipRowInTheShapeOpenRegisterReturnsIsDropped(): void {
		$row = new ObjectEntity();
		$row->setObject(['tenantRef' => 'tenant-a', 'userRef' => 'alice', 'role' => 'tenant_admin']);

		$this->assertSame([], $this->authAnswering(rows: [$row])->listTenantsForUser(userId: 'alice'));
	}

	/**
	 * A membership row as an array resolves, so the filter above is not vacuous.
	 *
	 * @return void
	 */
	public function testAMembershipRowAsAnArrayResolves(): void {
		$rows = [['tenantRef' => 'tenant-a', 'userRef' => 'alice']];

		$this->assertSame(['tenant-a'], $this->authAnswering(rows: $rows)->listTenantsForUser(userId: 'alice'));
		$this->assertTrue($this->authAnswering(rows: $rows)->isMemberOf(tenantId: 'tenant-a', userId: 'alice'));
		$this->assertFalse($this->authAnswering(rows: $rows)->isMemberOf(tenantId: 'tenant-b', userId: 'alice'));
	}
}
