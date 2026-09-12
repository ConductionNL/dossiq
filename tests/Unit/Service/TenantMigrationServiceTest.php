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

			/** @var array<int, Organisation> */
			public array $inserted = [];

			/** @var array<int, Organisation> */
			public array $updated = [];

			// phpcs:ignore
			public function __construct(array $existing) {
				$this->existing = $existing;
			}

			// phpcs:ignore
			public function findBySlug(string $slug): Organisation {
				if (isset($this->existing[$slug]) === true) {
					return $this->existing[$slug];
				}

				throw new RuntimeException('not found');
			}

			// phpcs:ignore
			public function update(Organisation $org): Organisation {
				$this->updated[] = $org;
				return $org;
			}

			// phpcs:ignore
			public function insert(Organisation $org): Organisation {
				if ($org->getUuid() === null || $org->getUuid() === '') {
					$org->setUuid('generated-' . count($this->inserted));
				}

				$this->inserted[] = $org;
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

		// 2f: onboarding is `active`, not `provisioning`.
		$this->assertSame('active', $bySlug['a']);
		$this->assertSame('suspended', $bySlug['b']);
		// 2e: terminated is `retained`, not the purgeable `archived`.
		$this->assertSame('retained', $bySlug['c']);
		$this->assertSame('suspended', $bySlug['d']);
	}

	/**
	 * A tenant whose slug already exists as an Organisation is skipped (idempotency).
	 *
	 * @return void
	 */
	public function testExistingSlugIsSkipped(): void {
		$existing = new Organisation();
		$existing->setUuid('org-existing');
		$existing->setSlug('gemeente-baarn');

		$mapper = $this->mapperWith(['gemeente-baarn' => $existing]);
		$service = $this->makeService(
			$this->objectServiceWithRows(
				[['id' => 'tenant-uuid-1', 'slug' => 'gemeente-baarn', 'status' => 'active']]
			),
			$mapper,
		);

		$summary = $service->migrate();

		$this->assertSame(0, $summary['migrated']);
		$this->assertSame(1, $summary['skipped']);
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

		// Second run: the slug now exists in the mapper → skipped.
		$mapper->existing['a'] = $mapper->inserted[0];
		$second = $this->makeService($this->objectServiceWithRows($rows), $mapper)->migrate();
		$this->assertSame(0, $second['migrated']);
		$this->assertSame(1, $second['skipped']);
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

	/**
	 * Would OpenRegister's TenantPurgeJob delete this organisation?
	 *
	 * Mirrors the job's own two conditions rather than restating field values,
	 * so the assertion says what is actually at stake: the job selects on
	 * `PURGEABLE_STATUS` ('archived'), re-checks that status on the row, and
	 * then deletes permanently once `deprovisionedAt` is set and older than the
	 * cutoff. A row it never selects is safe by rule, not by omission.
	 *
	 * @param Organisation $org The organisation to assess.
	 *
	 * @return bool True when the purge job would enrol this row.
	 */
	private function purgeJobWouldEnrol(Organisation $org): bool {
		if ($org->getStatus() !== 'archived') {
			return false;
		}

		return ($org->getDeprovisionedAt() !== null);
	}

	/**
	 * A terminated tenant lands in `retained`, and the purge job never enrols it.
	 *
	 * This is the specific harm decision 2e avoids. Mapping `terminated` onto
	 * `archived` put every terminated tenant into the one status
	 * `TenantPurgeJob` selects on, and dossiq's termination is deliberately
	 * non-destructive.
	 *
	 * @return void
	 */
	public function testTerminatedTenantIsRetainedAndNeverEnrolledInAPurge(): void {
		$mapper = $this->mapperWith([]);
		$service = $this->makeService(
			$this->objectServiceWithRows(
				[['id' => 'tenant-term', 'slug' => 'gemeente-stopgezet', 'status' => 'terminated']]
			),
			$mapper,
		);

		$service->migrate();

		$this->assertCount(1, $mapper->inserted);
		$org = $mapper->inserted[0];

		$this->assertSame(
			'retained',
			$org->getStatus(),
			'A terminated tenant must reach the retained state, not the purgeable archived state.'
		);
		$this->assertNotNull(
			$org->getRetainedAt(),
			'A retained organisation must date its retention, the way TenantLifecycleService::retain() does.'
		);
		$this->assertNull(
			$org->getDeprovisionedAt(),
			'deprovisionedAt is the purge window start; the migration must never schedule a delete.'
		);
		$this->assertFalse(
			$this->purgeJobWouldEnrol($org),
			'TenantPurgeJob must not enrol a tenant this migration wrote.'
		);
	}

	/**
	 * An onboarding tenant is `active`, so its admin can perform the onboarding.
	 *
	 * Decision 2f. An Organisation held in `provisioning` is refused by
	 * OpenRegister's TenantQuotaMiddleware on every OR route, which is exactly
	 * where dossiq's frontend reads and writes.
	 *
	 * @return void
	 */
	public function testOnboardingTenantIsActiveSoItsAdminCanTransact(): void {
		$mapper = $this->mapperWith([]);
		$service = $this->makeService(
			$this->objectServiceWithRows(
				[['id' => 'tenant-onb', 'slug' => 'gemeente-nieuw', 'status' => 'onboarding']]
			),
			$mapper,
		);

		$service->migrate();

		$this->assertCount(1, $mapper->inserted);
		$this->assertSame(
			'active',
			$mapper->inserted[0]->getStatus(),
			'An onboarding tenant must be active, or its admin is 403d off the routes onboarding runs through.'
		);
		$this->assertTrue(
			$mapper->inserted[0]->getActive(),
			'The active flag must follow the active status.'
		);
	}

	/**
	 * A tenant the June mapping archived is repaired to `retained` on a re-run.
	 *
	 * Correcting STATUS_MAP alone leaves these rows behind: the slug guard
	 * skips them and reports success over them.
	 *
	 * @return void
	 */
	public function testRepairsATerminatedTenantTheJuneMappingArchived(): void {
		$damaged = new Organisation();
		$damaged->setUuid('org-term');
		$damaged->setSlug('gemeente-stopgezet');
		$damaged->setStatus('archived');

		$mapper = $this->mapperWith(['gemeente-stopgezet' => $damaged]);
		$service = $this->makeService(
			$this->objectServiceWithRows(
				[['id' => 'tenant-term', 'slug' => 'gemeente-stopgezet', 'status' => 'terminated']]
			),
			$mapper,
		);

		$summary = $service->migrate();

		$this->assertSame(1, $summary['repaired'], 'The run must report the repair, not a silent skip.');
		$this->assertSame(0, $summary['skipped']);
		$this->assertCount(0, $mapper->inserted, 'A repair must not create a second Organisation.');
		$this->assertCount(1, $mapper->updated, 'The repaired organisation must be saved.');
		$this->assertSame(
			'retained',
			$damaged->getStatus(),
			'A tenant archived by the superseded mapping must be moved out of the purgeable state.'
		);
		$this->assertFalse(
			$this->purgeJobWouldEnrol($damaged),
			'After repair, TenantPurgeJob must not enrol the tenant.'
		);
	}

	/**
	 * A tenant the June mapping left in `provisioning` is repaired to `active`.
	 *
	 * @return void
	 */
	public function testRepairsAnOnboardingTenantTheJuneMappingLeftProvisioning(): void {
		$damaged = new Organisation();
		$damaged->setUuid('org-onb');
		$damaged->setSlug('gemeente-nieuw');
		$damaged->setStatus('provisioning');

		$mapper = $this->mapperWith(['gemeente-nieuw' => $damaged]);
		$service = $this->makeService(
			$this->objectServiceWithRows(
				[['id' => 'tenant-onb', 'slug' => 'gemeente-nieuw', 'status' => 'onboarding']]
			),
			$mapper,
		);

		$summary = $service->migrate();

		$this->assertSame(1, $summary['repaired']);
		$this->assertSame(
			'active',
			$damaged->getStatus(),
			'A tenant stuck in provisioning must be released to active so onboarding can proceed.'
		);
	}

	/**
	 * Running the repair twice changes nothing the second time.
	 *
	 * The idempotency proof the migration owes: the second run must find
	 * nothing to repair and write nothing, because the status no longer
	 * carries the superseded value.
	 *
	 * @return void
	 */
	public function testRepairRunTwiceIsIdempotent(): void {
		$damaged = new Organisation();
		$damaged->setUuid('org-term');
		$damaged->setSlug('gemeente-stopgezet');
		$damaged->setStatus('archived');

		$mapper = $this->mapperWith(['gemeente-stopgezet' => $damaged]);
		$rows = [['id' => 'tenant-term', 'slug' => 'gemeente-stopgezet', 'status' => 'terminated']];

		$first = $this->makeService($this->objectServiceWithRows($rows), $mapper)->migrate();
		$this->assertSame(1, $first['repaired']);
		$this->assertCount(1, $mapper->updated);

		$statusAfterFirst = $damaged->getStatus();
		$retainedAtAfterFirst = $damaged->getRetainedAt();

		$second = $this->makeService($this->objectServiceWithRows($rows), $mapper)->migrate();

		$this->assertSame(0, $second['repaired'], 'The second run must find nothing left to repair.');
		$this->assertSame(1, $second['skipped'], 'The second run must report a plain skip.');
		$this->assertCount(0, $mapper->inserted, 'Neither run may create an Organisation.');
		$this->assertCount(1, $mapper->updated, 'The second run must not write to the mapper at all.');
		$this->assertSame($statusAfterFirst, $damaged->getStatus(), 'The status must be unchanged by the second run.');
		$this->assertSame(
			$retainedAtAfterFirst,
			$damaged->getRetainedAt(),
			'The retention date must not be restamped by a re-run, or the window would restart on every run.'
		);
	}

	/**
	 * The repair leaves an operator's own lifecycle move alone.
	 *
	 * A terminated tenant an operator has since deliberately deprovisioned is
	 * their decision. The repair only corrects the exact value this migration
	 * wrote, so it cannot overrule one.
	 *
	 * @return void
	 */
	public function testRepairDoesNotOverruleAnOperatorsLifecycleMove(): void {
		$moved = new Organisation();
		$moved->setUuid('org-term');
		$moved->setSlug('gemeente-stopgezet');
		$moved->setStatus('deprovisioning');

		$mapper = $this->mapperWith(['gemeente-stopgezet' => $moved]);
		$service = $this->makeService(
			$this->objectServiceWithRows(
				[['id' => 'tenant-term', 'slug' => 'gemeente-stopgezet', 'status' => 'terminated']]
			),
			$mapper,
		);

		$summary = $service->migrate();

		$this->assertSame(0, $summary['repaired']);
		$this->assertSame(1, $summary['skipped']);
		$this->assertCount(0, $mapper->updated, 'A deliberate lifecycle move must not be written over.');
		$this->assertSame('deprovisioning', $moved->getStatus());
	}

	/**
	 * A repair keeps a retention date the organisation already carried.
	 *
	 * Reachable when a tenant was retained, then archived through
	 * deprovisioning, leaving `retainedAt` behind. Restamping it there would
	 * silently restart a retention window that began much earlier, which is
	 * the one thing a retention date exists to prevent.
	 *
	 * @return void
	 */
	public function testRepairKeepsAnExistingRetentionDate(): void {
		$originallyRetained = new \DateTime('2024-01-15 09:00:00');

		$damaged = new Organisation();
		$damaged->setUuid('org-term');
		$damaged->setSlug('gemeente-stopgezet');
		$damaged->setStatus('archived');
		$damaged->setRetainedAt($originallyRetained);

		$mapper = $this->mapperWith(['gemeente-stopgezet' => $damaged]);
		$service = $this->makeService(
			$this->objectServiceWithRows(
				[['id' => 'tenant-term', 'slug' => 'gemeente-stopgezet', 'status' => 'terminated']]
			),
			$mapper,
		);

		$summary = $service->migrate();

		$this->assertSame(1, $summary['repaired']);
		$this->assertSame('retained', $damaged->getStatus());
		$this->assertSame(
			$originallyRetained,
			$damaged->getRetainedAt(),
			'The repair must not restart a retention window that already began.'
		);
	}
}//end class
