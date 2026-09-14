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
 * Until 2026-09-11 all four lookups read those objects as arrays, so on a real
 * install none of them worked: no membership resolved, the role and matrix
 * lookups threw and denied, and the quota lookup allowed everything. They were
 * fixed together, and the tests below that feed an `ObjectEntity` are what
 * holds each of them to the fix.
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
use OCA\Dossiq\Service\OrganisationQuotaLimits;
use OCA\Dossiq\Service\TenantQuotaService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Organisation;
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

	/**
	 * Save an object.
	 *
	 * @param array<string, mixed> $object   The object data.
	 * @param string               $register The register.
	 * @param string               $schema   The schema.
	 * @param string|null          $uuid     The uuid to update, or null to create.
	 *
	 * @return array<string, mixed> The saved object.
	 */
	public function saveObject(array $object, string $register, string $schema, ?string $uuid = null): array;
}

/**
 * @covers \OCA\Dossiq\Service\TenantAuthenticationService
 * @covers \OCA\Dossiq\Service\TenantQuotaService
 *
 * @uses \OCA\Dossiq\Command\Backfill\OpenRegisterRowNormaliser
 */
class TenantScopedLookupsTest extends TestCase {
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
	 * @param string|null          $uuid   The object's uuid.
	 *
	 * @return ObjectEntity The row.
	 */
	private function entity(array $object, ?string $uuid = null): ObjectEntity {
		$row = new ObjectEntity();
		$row->setUuid($uuid);
		$row->setObject($object);

		return $row;
	}

	/**
	 * Build a container whose ObjectService records each query and answers with $rows.
	 *
	 * @param array<int, mixed>                $rows         What `findAll()` returns.
	 * @param array<string, array<int, mixed>> $rowsBySchema What it returns for a named schema instead.
	 *
	 * @return array{0: IAppManager, 1: ContainerInterface} The app manager and container.
	 */
	private function openRegisterAnswering(array $rows, array $rowsBySchema = []): array {
		$objectService = $this->createMock(TenantLookupObjectServiceStub::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($rows, $rowsBySchema): array {
				$this->queries[] = $config;
				$schema = (string)($config['filters']['schema'] ?? '');

				return ($rowsBySchema[$schema] ?? $rows);
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, string $register, string $schema, ?string $uuid = null): array {
				$this->saves[] = ['object' => $object, 'schema' => $schema, 'uuid' => $uuid];

				return $object;
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
	 * @param array<int, mixed>                $rows         What `findAll()` returns.
	 * @param array<string, array<int, mixed>> $rowsBySchema What it returns for a named schema instead.
	 *
	 * @return TenantAuthenticationService The service.
	 */
	private function authAnswering(array $rows, array $rowsBySchema = []): TenantAuthenticationService {
		[$appManager, $container] = $this->openRegisterAnswering(rows: $rows, rowsBySchema: $rowsBySchema);

		return new TenantAuthenticationService(
			appManager: $appManager,
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
		);
	}

	/**
	 * The quota service over a fake OpenRegister.
	 *
	 * @param array<int, mixed> $rows         What `findAll()` returns.
	 * @param Organisation|null $organisation The Organisation the tenant resolves to.
	 *
	 * @return TenantQuotaService The service.
	 */
	private function quotaAnswering(array $rows, ?Organisation $organisation = null): TenantQuotaService {
		[$appManager, $container] = $this->openRegisterAnswering(rows: $rows);

		// The limits collaborator is modelled rather than stubbed with a fixed
		// answer: a mock returning the same limit for every quota type would
		// let a service that overlaid the UNMAPPED types pass too.
		$limits = $this->createMock(OrganisationQuotaLimits::class);
		$limits->method('owns')->willReturnCallback(
			static fn (string $quotaType): bool => array_key_exists($quotaType, OrganisationQuotaLimits::COLUMNS)
		);
		$limits->method('read')->willReturnCallback(
			static function (string $tenantId, string $quotaType) use ($organisation): ?int {
				if ($organisation === null) {
					return null;
				}

				if ($quotaType === 'storage_gb') {
					$bytes = $organisation->getStorageQuota();
					if ($bytes === null) {
						return null;
					}

					return intdiv((int)$bytes, 1073741824);
				}

				$requests = $organisation->getRequestQuota();
				if ($requests === null) {
					return null;
				}

				return (int)$requests;
			}
		);

		return new TenantQuotaService(
			appManager: $appManager,
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
			organisationLimits: $limits,
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
		$this->assertNull($this->quotaAnswering(rows: [])->getQuota(tenantId: 'tenant-a', quotaType: 'cases_per_month'));

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
	 * A membership row as OpenRegister returns it resolves to its tenant.
	 *
	 * This test used to pin the opposite, as
	 * `testAMembershipRowInTheShapeOpenRegisterReturnsIsDropped`, and named it
	 * a defect: `listTenantsForUser()` kept only rows that were arrays, so on a
	 * real install no user had a membership and the whole SaaS middleware chain
	 * stepped aside. It was left unfixed until the role, mandate and quota
	 * lookups could be fixed with it. Fixed alone, it would have bound tenants
	 * and then denied every member's writes through the two lookups that still
	 * threw.
	 *
	 * @return void
	 */
	public function testAMembershipRowInTheShapeOpenRegisterReturnsResolves(): void {
		$rows = [$this->entity(object: ['tenantRef' => 'tenant-a', 'userRef' => 'alice', 'role' => 'tenant_admin'])];

		$this->assertSame(['tenant-a'], $this->authAnswering(rows: $rows)->listTenantsForUser(userId: 'alice'));
		$this->assertTrue($this->authAnswering(rows: $rows)->isMemberOf(tenantId: 'tenant-a', userId: 'alice'));
		$this->assertFalse($this->authAnswering(rows: $rows)->isMemberOf(tenantId: 'tenant-b', userId: 'alice'));
	}

	/**
	 * A role row as OpenRegister returns it resolves to its role.
	 *
	 * Before the fix this indexed the entity as an array, threw, and the
	 * mandate gate denied: a member would have been refused every write.
	 *
	 * @return void
	 */
	public function testARoleRowInTheShapeOpenRegisterReturnsResolves(): void {
		$rows = [$this->entity(object: ['tenantRef' => 'tenant-a', 'userRef' => 'alice', 'role' => 'case_handler'])];

		$this->assertSame('case_handler', $this->authAnswering(rows: $rows)->resolveUserRole(tenantId: 'tenant-a', userId: 'alice'));
	}

	/**
	 * A mandate row as OpenRegister returns it yields its own matrix.
	 *
	 * @return void
	 */
	public function testAMandateRowInTheShapeOpenRegisterReturnsResolves(): void {
		$matrix = ['case_handler' => ['create' => true]];
		$rows = [
			$this->entity(
				object: ['tenantRef' => 'tenant-a', 'effectiveFrom' => '2020-01-01', 'effectiveTo' => '2099-12-31', 'matrix' => $matrix]
			),
		];

		$this->assertSame($matrix, $this->authAnswering(rows: $rows)->loadActiveMatrix(tenantId: 'tenant-a'));
	}

	/**
	 * A mandate row outside its window grants nothing, read as an entity too.
	 *
	 * Reading the row differently must not stop the window from being checked.
	 * The matrix here grants everything, so a skipped window check would show.
	 *
	 * @return void
	 */
	public function testAMandateRowOutsideItsWindowGrantsNothing(): void {
		$rows = [
			$this->entity(
				object: ['tenantRef' => 'tenant-a', 'effectiveFrom' => '2020-01-01', 'effectiveTo' => '2021-01-01', 'matrix' => ['*' => ['*' => true]]]
			),
		];

		$this->assertNull($this->authAnswering(rows: $rows)->loadActiveMatrix(tenantId: 'tenant-a'));
	}

	/**
	 * A mandate row that cannot be read grants nothing.
	 *
	 * Read as an empty array it would have no window, a row with no window
	 * counts as active, and a row with no matrix falls back to the default
	 * template, which gives `tenant_admin` everything. So an unreadable row is
	 * dropped, and a tenant with nothing else has no matrix at all.
	 *
	 * @return void
	 */
	public function testAnUnreadableMandateRowGrantsNothing(): void {
		$this->assertNull($this->authAnswering(rows: ['not a row'])->loadActiveMatrix(tenantId: 'tenant-a'));
	}

	/**
	 * Once the rows are read, a member may do what the matrix grants, and only that.
	 *
	 * This is the end state the four lookups had to reach together. Neither
	 * "denies every write" (the role and matrix lookups throwing) nor "allows
	 * every write": `create` is granted to the role and `delete` is not.
	 *
	 * @return void
	 */
	public function testAMemberMayDoWhatTheMatrixGrantsAndNothingElse(): void {
		$auth = $this->authAnswering(
			rows: [],
			rowsBySchema: [
				'tenantUser' => [$this->entity(object: ['tenantRef' => 'tenant-a', 'userRef' => 'alice', 'role' => 'case_handler'])],
				'tenantMandate' => [
					$this->entity(
						object: [
							'tenantRef' => 'tenant-a',
							'effectiveFrom' => '2020-01-01',
							'effectiveTo' => '2099-12-31',
							'matrix' => ['case_handler' => ['create' => true]],
						]
					),
				],
			],
		);

		$this->assertSame(
			['allowed' => true, 'reason' => 'Authorised by mandate matrix'],
			$auth->validateMandateMatrix(tenantId: 'tenant-a', userId: 'alice', action: 'create')
		);
		$this->assertSame(
			['allowed' => false, 'reason' => 'Role case_handler is not authorised for action delete'],
			$auth->validateMandateMatrix(tenantId: 'tenant-a', userId: 'alice', action: 'delete')
		);
	}

	/**
	 * A quota row as OpenRegister returns it is read, id included.
	 *
	 * Before the fix `getQuota()` returned the entity from a method typed
	 * `?array`, the `TypeError` was caught as "no row", and every quota allowed.
	 *
	 * @return void
	 */
	public function testAQuotaRowInTheShapeOpenRegisterReturnsIsRead(): void {
		$row = $this->entity(
			object: ['tenantRef' => 'tenant-a', 'quotaType' => 'cases_per_month', 'limit' => 100, 'currentUsage' => 40, 'enforcement' => 'block'],
			uuid: 'quota-1',
		);

		$quota = $this->quotaAnswering(rows: [$row])->getQuota(tenantId: 'tenant-a', quotaType: 'cases_per_month');

		$this->assertIsArray($quota);
		$this->assertSame('quota-1', $quota['id']);
		$this->assertSame(100, $quota['limit']);
		$this->assertSame(40, $quota['currentUsage']);
	}

	/**
	 * A tenant at its limit on a blocking quota is blocked, and nothing is counted.
	 *
	 * @return void
	 */
	public function testATenantAtItsLimitIsBlocked(): void {
		$row = $this->entity(
			object: ['tenantRef' => 'tenant-a', 'quotaType' => 'cases_per_month', 'limit' => 100, 'currentUsage' => 100, 'enforcement' => 'block'],
			uuid: 'quota-1',
		);

		$decision = $this->quotaAnswering(rows: [$row])->consume(tenantId: 'tenant-a', quotaType: 'cases_per_month');

		$this->assertSame(TenantQuotaService::DECISION_BLOCK, $decision['decision']);
		$this->assertSame([], $this->saves);
	}

	/**
	 * A quota row that cannot be read is no row, and nothing is written.
	 *
	 * Read as an empty array it would carry no `id` and no `tenantRef`, and
	 * counting against it would create a new quota row belonging to nobody.
	 *
	 * @return void
	 */
	public function testAnUnreadableQuotaRowCountsNothing(): void {
		$decision = $this->quotaAnswering(rows: ['not a row'])->consume(tenantId: 'tenant-a', quotaType: 'cases_per_month');

		$this->assertSame('no_quota_row', $decision['reason']);
		$this->assertSame([], $this->saves);
	}

	/**
	 * A consumed quota is written back to the row it was read from.
	 *
	 * `persistQuota()` updates by the row's `id`. Lose it in the read and every
	 * counted request creates a new quota row at 1, so the limit is never
	 * reached and nothing in the response says so.
	 *
	 * @return void
	 */
	public function testAConsumedQuotaIsWrittenBackToTheRowItWasReadFrom(): void {
		$row = $this->entity(
			object: ['tenantRef' => 'tenant-a', 'quotaType' => 'cases_per_month', 'limit' => 100, 'currentUsage' => 40, 'enforcement' => 'block'],
			uuid: 'quota-1',
		);

		$decision = $this->quotaAnswering(rows: [$row])->consume(tenantId: 'tenant-a', quotaType: 'cases_per_month');

		$this->assertSame(TenantQuotaService::DECISION_ALLOW, $decision['decision']);
		$this->assertCount(1, $this->saves);
		$this->assertSame('tenantQuota', $this->saves[0]['schema']);
		$this->assertSame('quota-1', $this->saves[0]['uuid']);
		$this->assertSame(41, $this->saves[0]['object']['currentUsage']);
		$this->assertSame('tenant-a', $this->saves[0]['object']['tenantRef']);
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

	/**
	 * The request limit is the Organisation's, and the row's copy is ignored.
	 *
	 * Decision 2b. `api_calls_per_hour` maps to `Organisation.requestQuota`,
	 * and for a mapped type the Organisation is the ONLY place a limit lives.
	 * The row here still carries a stale `limit` of 1000000, as an install
	 * predating the move would, and it must not win: an operator who lowered
	 * the limit on the Organisation would otherwise see nothing change, and no
	 * response would say which number was being enforced.
	 *
	 * @return void
	 */
	public function testTheRequestLimitComesFromTheOrganisationAndNotFromTheRow(): void {
		$organisation = new Organisation();
		$organisation->setUuid('tenant-a');
		$organisation->setRequestQuota(10);

		$row = $this->entity(
			object: [
				'tenantRef' => 'tenant-a',
				'quotaType' => 'api_calls_per_hour',
				'limit' => 1000000,
				'currentUsage' => 0,
			],
			uuid: 'quota-1',
		);

		$quota = $this->quotaAnswering(rows: [$row], organisation: $organisation)
			->getQuota(tenantId: 'tenant-a', quotaType: 'api_calls_per_hour');

		$this->assertNotNull($quota);
		$this->assertSame(10, $quota['limit']);
		$this->assertNotSame(1000000, $quota['limit'], 'the row own limit must not win for a mapped type');
	}

	/**
	 * The storage limit is converted, not compared across units.
	 *
	 * `storage_gb` counts gigabytes and `storageQuota` stores bytes. Read
	 * straight through, a 10 GB limit would read as 10737418240 and a tenant
	 * would have roughly a billion times the headroom it was given.
	 *
	 * @return void
	 */
	public function testTheStorageLimitIsReadBackInGigabytesNotBytes(): void {
		$organisation = new Organisation();
		$organisation->setUuid('tenant-a');
		$organisation->setStorageQuota((10 * 1073741824));

		$row = $this->entity(
			object: ['tenantRef' => 'tenant-a', 'quotaType' => 'storage_gb', 'currentUsage' => 0],
			uuid: 'quota-2',
		);

		$quota = $this->quotaAnswering(rows: [$row], organisation: $organisation)
			->getQuota(tenantId: 'tenant-a', quotaType: 'storage_gb');

		$this->assertNotNull($quota);
		$this->assertSame(10, $quota['limit']);
	}

	/**
	 * The two types with no column keep their own limit on the row.
	 *
	 * `cases_per_month` and `active_users` have no Organisation column, so
	 * decision 2b leaves them exactly as they are. An overlay that reached
	 * them would blank a limit nothing else carries, and `decide()` reads a
	 * null limit as unlimited, so the quota would fail OPEN.
	 *
	 * @return void
	 */
	public function testAnUnmappedQuotaTypeKeepsTheLimitOnItsOwnRow(): void {
		$organisation = new Organisation();
		$organisation->setUuid('tenant-a');
		$organisation->setRequestQuota(10);

		$row = $this->entity(
			object: [
				'tenantRef' => 'tenant-a',
				'quotaType' => 'cases_per_month',
				'limit' => 100,
				'currentUsage' => 0,
			],
			uuid: 'quota-3',
		);

		$quota = $this->quotaAnswering(rows: [$row], organisation: $organisation)
			->getQuota(tenantId: 'tenant-a', quotaType: 'cases_per_month');

		$this->assertNotNull($quota);
		$this->assertSame(100, $quota['limit']);
	}

	/**
	 * A mapped quota whose Organisation carries no limit is unlimited.
	 *
	 * Named rather than left implicit, because it is a fail-open and a reader
	 * is entitled to know it was chosen. Refusing traffic because an
	 * Organisation lookup came back empty would take a tenant offline over an
	 * unavailable service, and OpenRegister's own TenantQuotaMiddleware is
	 * what actually enforces requestQuota.
	 *
	 * @return void
	 */
	public function testAMappedQuotaWithNoOrganisationLimitIsUnlimited(): void {
		$row = $this->entity(
			object: [
				'tenantRef' => 'tenant-a',
				'quotaType' => 'api_calls_per_hour',
				'limit' => 5,
				'currentUsage' => 99,
			],
			uuid: 'quota-4',
		);

		$quota = $this->quotaAnswering(rows: [$row], organisation: null)
			->getQuota(tenantId: 'tenant-a', quotaType: 'api_calls_per_hour');

		$this->assertNotNull($quota);
		$this->assertNull($quota['limit']);
	}

	/**
	 * Counting a mapped quota does not write a limit back onto the row.
	 *
	 * `getQuota()` puts the resolved limit into the array it returns, and
	 * `consume()` hands that same array back to be saved with the new usage.
	 * Saved as-is it would persist a second copy of the limit onto the row,
	 * which is the drift decision 2b removes, and the row's copy is the one
	 * OpenRegister's own enforcement cannot see.
	 *
	 * @return void
	 */
	public function testCountingAMappedQuotaDoesNotWriteTheLimitBackOntoTheRow(): void {
		$organisation = new Organisation();
		$organisation->setUuid('tenant-a');
		$organisation->setRequestQuota(100);

		$row = $this->entity(
			object: ['tenantRef' => 'tenant-a', 'quotaType' => 'api_calls_per_hour', 'currentUsage' => 1],
			uuid: 'quota-5',
		);

		$this->quotaAnswering(rows: [$row], organisation: $organisation)
			->consume(tenantId: 'tenant-a', quotaType: 'api_calls_per_hour');

		$this->assertCount(1, $this->saves);
		$this->assertArrayNotHasKey('limit', $this->saves[0]['object']);
		$this->assertSame(2, $this->saves[0]['object']['currentUsage']);
	}
}
