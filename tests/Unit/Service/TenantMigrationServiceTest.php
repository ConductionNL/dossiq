<?php

/**
 * TenantMigrationService Unit Tests
 *
 * Verifies the one-time, idempotent migration of legacy dossiq `tenant` schema
 * objects onto OpenRegister Organisations (migrate-tenant-to-or-tenant,
 * ADR-022): row → Organisation field mapping, status vocabulary mapping, UUID
 * preservation, slug-based idempotency, and graceful no-op when OR is absent.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TenantMigrationService;
use OCA\OpenRegister\Db\Organisation;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for TenantMigrationService.
 *
 * @covers \OCA\Dossiq\Service\TenantMigrationService
 */
class TenantMigrationServiceTest extends TestCase {
	/**
	 * Build a fake OR ObjectService returning the given tenant rows on the slug path.
	 *
	 * @param array<int, array<string, mixed>> $rows Tenant rows.
	 *
	 * @return object
	 */
	private function objectServiceWithRows(array $rows): object {
		return new class($rows) {
			/** @var array<int, array<string, mixed>> */
			private array $rows;

			// phpcs:ignore
			public function __construct(array $rows) {
				$this->rows = $rows;
			}

			// phpcs:ignore
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				return $this->rows;
			}
		};
	}

	/**
	 * Build a fake OrganisationMapper.
	 *
	 * @param array<string, Organisation> $existingBySlug Pre-existing organisations keyed by slug.
	 *
	 * @return object
	 */
	private function mapperWith(array $existingBySlug): object {
		return new class($existingBySlug) {
			/** @var array<string, Organisation> */
			public array $existing;

			/** @var array<string, Organisation> */
			public array $existingByUuid = [];

			/** @var array<int, Organisation> */
			public array $inserted = [];

			// phpcs:ignore
			public function __construct(array $existing) {
				$this->existing = $existing;
				foreach ($existing as $org) {
					$uuid = (string)$org->getUuid();
					if ($uuid !== '') {
						$this->existingByUuid[$uuid] = $org;
					}
				}
			}

			// phpcs:ignore
			public function findBySlug(string $slug): Organisation {
				if (isset($this->existing[$slug]) === true) {
					return $this->existing[$slug];
				}

				throw new RuntimeException('not found');
			}

			// phpcs:ignore
			public function findByUuid(string $uuid): Organisation {
				if (isset($this->existingByUuid[$uuid]) === true) {
					return $this->existingByUuid[$uuid];
				}

				throw new RuntimeException('not found');
			}

			// phpcs:ignore
			public function insert(Organisation $org): Organisation {
				if ($org->getUuid() === null || $org->getUuid() === '') {
					$org->setUuid('generated-' . count($this->inserted));
				}

				$this->inserted[] = $org;
				$this->existing[(string)$org->getSlug()] = $org;
				$this->existingByUuid[(string)$org->getUuid()] = $org;
				return $org;
			}
		};
	}

	/**
	 * Build the service with the given fakes wired through mocked collaborators.
	 *
	 * @param object $objectService Fake OR ObjectService.
	 * @param object $mapper Fake OrganisationMapper.
	 *
	 * @return TenantMigrationService
	 */
	private function makeService(object $objectService, object $mapper): TenantMigrationService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister', 'dossiq']);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mapper);

		return new TenantMigrationService(
			$settings,
			$container,
			$appManager,
			$this->createMock(LoggerInterface::class),
		);
	}

	/**
	 * A new tenant row is projected onto an Organisation with mapped fields.
	 *
	 * @return void
	 */
	public function testMigratesNewTenantToOrganisation(): void {
		$mapper = $this->mapperWith([]);
		$service = $this->makeService(
			$this->objectServiceWithRows(
				[
					[
						'id' => 'tenant-uuid-1',
						'slug' => 'gemeente-baarn',
						'displayName' => 'Gemeente Baarn',
						'status' => 'active',
						'maxStorageMb' => 100,
						'groupId' => 'tenant_gemeente-baarn',
					],
				]
			),
			$mapper,
		);

		$summary = $service->migrate();

		$this->assertSame(1, $summary['total']);
		$this->assertSame(1, $summary['migrated']);
		$this->assertSame(0, $summary['skipped']);
		$this->assertSame(0, $summary['failed']);
		$this->assertCount(1, $mapper->inserted);

		$org = $mapper->inserted[0];
		$this->assertSame('tenant-uuid-1', $org->getUuid());
		$this->assertSame('gemeente-baarn', $org->getSlug());
		$this->assertSame('Gemeente Baarn', $org->getName());
		$this->assertSame('active', $org->getStatus());
		$this->assertSame(['tenant_gemeente-baarn'], $org->getGroups());
		$this->assertTrue($org->isActive());
		$this->assertSame((100 * 1024 * 1024), $org->getStorageQuota());

		$this->assertSame('tenant-uuid-1', $summary['mappings'][0]['tenant']);
		$this->assertSame('tenant-uuid-1', $summary['mappings'][0]['organisation']);
	}

	/**
	 * Legacy dossiq statuses map to OR's lifecycle vocabulary.
	 *
	 * @return void
	 */
	public function testStatusVocabularyIsMapped(): void {
		$mapper = $this->mapperWith([]);
		$service = $this->makeService(
			$this->objectServiceWithRows(
				[
					['id' => 't1', 'slug' => 'a', 'status' => 'onboarding'],
					['id' => 't2', 'slug' => 'b', 'status' => 'suspended'],
					['id' => 't3', 'slug' => 'c', 'status' => 'terminated'],
					['id' => 't4', 'slug' => 'd', 'isActive' => false],
				]
			),
			$mapper,
		);

		$service->migrate();

		$bySlug = [];
		foreach ($mapper->inserted as $org) {
			$bySlug[$org->getSlug()] = $org->getStatus();
		}

		// Decision 2f: an onboarding tenant stays `active` on the Organisation.
		// `provisioning` would have openregister's TenantQuotaMiddleware answer
		// 403 to every request its users make on openregister's routes, which
		// is where dossiq's frontend reads and writes.
		$this->assertSame('active', $bySlug['a']);
		$this->assertSame('suspended', $bySlug['b']);
		// Decision 2e: `retained`, not `archived`. `archived` is the ONE state
		// TenantPurgeJob may permanently delete, and dossiq's termination is
		// non-destructive by design.
		$this->assertSame('retained', $bySlug['c']);
		$this->assertNotSame('archived', $bySlug['c']);
		$this->assertSame('suspended', $bySlug['d']);
	}

	/**
	 * A terminated tenant carries the moment its retention began, and no
	 * deletion date.
	 *
	 * `TenantPurgeJob` measures its window from `deprovisionedAt`. Writing that
	 * column here would enrol every tenant terminated more than
	 * `tenantRetentionDays` ago in a hard delete on the job's first run after
	 * the migration, which is exactly what decision 2e refused.
	 *
	 * @return void
	 */
	public function testATerminatedTenantIsRetainedFromItsOwnTerminationDateAndNotScheduledForDeletion(): void {
		$mapper = $this->mapperWith([]);
		$this->makeService(
			$this->objectServiceWithRows(
				[['id' => 't9', 'slug' => 'ended', 'status' => 'terminated', 'terminatedAt' => '2021-03-04T00:00:00+00:00']]
			),
			$mapper,
		)->migrate();

		$org = $mapper->inserted[0];
		$this->assertSame('retained', $org->getStatus());
		$this->assertNotNull($org->getRetainedAt());
		$this->assertSame('2021-03-04', $org->getRetainedAt()->format('Y-m-d'));
		$this->assertNull($org->getDeprovisionedAt(), 'a retained organisation must carry no deletion date');
	}

	/**
	 * A slug held by a DIFFERENT organisation is refused, not skipped.
	 *
	 * 🔴 This is the isolation hazard the uuid key exists to close, and it is
	 * the single most important test in this file.
	 *
	 * Keyed by slug, this tenant was reported as "already migrated" against
	 * `org-someone-else`. Rewriting `tenantRef` from that report would attach
	 * this tenant's users, mandates and quotas to an organisation that is not
	 * it, and every scoping filter downstream would then agree, because the
	 * rows really would say so. Nothing would raise.
	 *
	 * So it must be refused, it must be counted separately from a skip, and
	 * the report must NOT contain a mapping. All three are asserted, because a
	 * fix that only renamed the counter would still publish the wrong mapping.
	 *
	 * @return void
	 */
	public function testASlugHeldByADifferentOrganisationIsRefusedAndNotMapped(): void {
		$other = new Organisation();
		$other->setUuid('org-someone-else');
		$other->setSlug('gemeente-baarn');

		$mapper = $this->mapperWith(['gemeente-baarn' => $other]);
		$summary = $this->makeService(
			$this->objectServiceWithRows(
				[['id' => 'tenant-uuid-1', 'slug' => 'gemeente-baarn', 'status' => 'active']]
			),
			$mapper,
		)->migrate();

		$this->assertSame(0, $summary['migrated']);
		$this->assertSame(0, $summary['skipped'], 'a collision is not a skip');
		$this->assertSame(1, $summary['refused']);
		$this->assertCount(0, $mapper->inserted, 'nothing may be written on a collision');
		$this->assertSame([], $summary['mappings'], 'a refused tenant must not be reported as mapped');
		$this->assertSame(
			[['tenant' => 'tenant-uuid-1', 'slug' => 'gemeente-baarn', 'heldBy' => 'org-someone-else']],
			$summary['collisions'],
			'the report must name which organisation holds the slug'
		);
	}

	/**
	 * An already-migrated tenant is skipped, and it is the uuid that says so.
	 *
	 * The Organisation carries a different slug from the tenant row, so a
	 * slug-keyed check would not find it and would try to insert again.
	 *
	 * @return void
	 */
	public function testAnAlreadyMigratedTenantIsSkippedByItsUuid(): void {
		$existing = new Organisation();
		$existing->setUuid('tenant-uuid-1');
		$existing->setSlug('renamed-since');

		$mapper = $this->mapperWith(['renamed-since' => $existing]);
		$summary = $this->makeService(
			$this->objectServiceWithRows(
				[['id' => 'tenant-uuid-1', 'slug' => 'gemeente-baarn', 'status' => 'active']]
			),
			$mapper,
		)->migrate();

		$this->assertSame(0, $summary['migrated']);
		$this->assertSame(1, $summary['skipped']);
		$this->assertSame(0, $summary['refused']);
		$this->assertCount(0, $mapper->inserted);
		$this->assertSame([], $summary['collisions']);
	}

	/**
	 * A row with no id cannot be migrated: there is no key to preserve.
	 *
	 * @return void
	 */
	public function testARowWithNoIdIsFailedAndNotInserted(): void {
		$mapper = $this->mapperWith([]);
		$summary = $this->makeService(
			$this->objectServiceWithRows([['slug' => 'no-id-here', 'status' => 'active']]),
			$mapper,
		)->migrate();

		$this->assertSame(1, $summary['failed']);
		$this->assertCount(0, $mapper->inserted);
	}

	/**
	 * Re-running the migration on already-migrated tenants is a no-op (idempotent).
	 *
	 * @return void
	 */
	public function testReRunIsIdempotent(): void {
		$mapper = $this->mapperWith([]);
		$rows = [['id' => 't1', 'slug' => 'a', 'status' => 'active']];

		$first = $this->makeService($this->objectServiceWithRows($rows), $mapper)->migrate();
		$this->assertSame(1, $first['migrated']);

		// Second run: the fake mapper indexed the insert by uuid, so the uuid
		// key finds it. Nothing is re-inserted and nothing is refused.
		$second = $this->makeService($this->objectServiceWithRows($rows), $mapper)->migrate();
		$this->assertSame(0, $second['migrated']);
		$this->assertSame(1, $second['skipped']);
		$this->assertSame(0, $second['refused']);
		$this->assertCount(1, $mapper->inserted);
	}

	/**
	 * When OpenRegister is not installed, the migration is a graceful no-op.
	 *
	 * @return void
	 */
	public function testNoOpWhenOpenRegisterAbsent(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objectServiceWithRows([]));

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['dossiq']);

		$container = $this->createMock(ContainerInterface::class);

		$service = new TenantMigrationService(
			$settings,
			$container,
			$appManager,
			$this->createMock(LoggerInterface::class),
		);

		$summary = $service->migrate();
		$this->assertSame(0, $summary['total']);
		$this->assertSame(0, $summary['migrated']);
	}

	/**
	 * A row without a slug is counted as failed and does not produce an Organisation.
	 *
	 * @return void
	 */
	public function testRowWithoutSlugFails(): void {
		$mapper = $this->mapperWith([]);
		$service = $this->makeService(
			$this->objectServiceWithRows([['id' => 't1', 'status' => 'active']]),
			$mapper,
		);

		$summary = $service->migrate();
		$this->assertSame(1, $summary['failed']);
		$this->assertCount(0, $mapper->inserted);
	}
}//end class
