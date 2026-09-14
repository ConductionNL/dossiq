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
	 * @param array<int, array<string, mixed>>                       $rows     Tenant rows.
	 * @param array<string, array<int, array<string, mixed>>>         $bySchema Rows for a named satellite schema.
	 *
	 * @return object
	 */
	private function objectServiceWithRows(array $rows, array $bySchema = []): object {
		return new class($rows, $bySchema) {
			/** @var array<int, array<string, mixed>> */
			private array $rows;

			/** @var array<string, array<int, array<string, mixed>>> */
			private array $bySchema;

			// phpcs:ignore
			public function __construct(array $rows, array $bySchema = []) {
				$this->rows = $rows;
				$this->bySchema = $bySchema;
			}

			// phpcs:ignore
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				if (array_key_exists($schema, $this->bySchema) === true) {
					return $this->bySchema[$schema];
				}

				if ($schema === 'tenant') {
					return $this->rows;
				}

				return [];
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

			/** @var array<int, Organisation> */
			public array $updated = [];

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
	 * A slug collision is refused and NEVER repaired.
	 *
	 * 🔴 The case neither half of this change had on its own, and the one
	 * where they meet.
	 *
	 * The repair fires on the uuid path, where the Organisation is provably
	 * this tenant. Here it is provably NOT: the uuid does not match, so the
	 * row belongs to somebody else and only shares a name. It is also sitting
	 * in `archived` with a `terminated` tenant pointing at it, which is
	 * exactly the shape the repair looks for, so a repair written against the
	 * slug would fire on it.
	 *
	 * That would be worse than the mis-report the refusal already prevents: it
	 * would WRITE a lifecycle status onto another organisation on the strength
	 * of a name, moving somebody else's tenant out of `archived` and stamping
	 * a retention date on it. Refused, unrepaired, untouched.
	 *
	 * @return void
	 */
	public function testASlugCollisionThatLooksRepairableIsRefusedAndNotRepaired(): void {
		$someoneElse = new Organisation();
		$someoneElse->setUuid('org-someone-else');
		$someoneElse->setSlug('gemeente-stopgezet');
		$someoneElse->setStatus('archived');
		$someoneElse->setDeprovisionedAt(new \DateTime('2024-02-02 10:00:00'));

		$mapper = $this->mapperWith(['gemeente-stopgezet' => $someoneElse]);
		$summary = $this->makeService(
			$this->objectServiceWithRows(
				[['id' => 'tenant-term', 'slug' => 'gemeente-stopgezet', 'status' => 'terminated']]
			),
			$mapper,
		)->migrate();

		$this->assertSame(1, $summary['refused']);
		$this->assertSame(0, $summary['repaired'], 'a collision must never be counted as a repair');
		$this->assertSame(0, $summary['skipped']);
		$this->assertSame([], $summary['mappings']);
		$this->assertCount(0, $mapper->inserted);
		$this->assertCount(0, $mapper->updated, 'nothing may be written to another organisation on a slug match');
		$this->assertSame('archived', $someoneElse->getStatus(), "the other organisation's status must be untouched");
		$this->assertNotNull($someoneElse->getDeprovisionedAt(), 'its deprovisionedAt must be untouched too');
		$this->assertNull($someoneElse->getRetainedAt(), 'and no retention date may be stamped onto it');
		$this->assertSame(
			[['tenant' => 'tenant-term', 'slug' => 'gemeente-stopgezet', 'heldBy' => 'org-someone-else']],
			$summary['collisions']
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
		$damaged->setUuid('tenant-term');
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
		$damaged->setUuid('tenant-onb');
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
		$damaged->setUuid('tenant-term');
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
		$moved->setUuid('tenant-term');
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
		$damaged->setUuid('tenant-term');
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
	/**
	 * A row whose status the legacy model never set becomes an active tenant.
	 *
	 * `resolveStatus()` falls through to `active` when the row carries neither
	 * a mapped `status` nor `isActive: false`. That default decides whether a
	 * tenant can transact after the migration, so it is a behaviour rather
	 * than a tidy-up, and it is asserted here rather than left to be inferred
	 * from the two branches above it.
	 *
	 * @return void
	 */
	public function testARowWithNoStatusAtAllBecomesAnActiveOrganisation(): void {
		$mapper = $this->mapperWith([]);
		$this->makeService(
			$this->objectServiceWithRows([['id' => 't-bare', 'slug' => 'bare']]),
			$mapper,
		)->migrate();

		$this->assertCount(1, $mapper->inserted);
		$this->assertSame('active', $mapper->inserted[0]->getStatus());
		$this->assertTrue($mapper->inserted[0]->getActive());
	}

	/**
	 * An unreadable termination date still dates the retention.
	 *
	 * A retained organisation with no `retainedAt` has no computable end of
	 * retention, so a date this migration cannot parse must fall back to now
	 * rather than to null. The alternative fails silently: the status is
	 * right, the row looks migrated, and the retention period has no start.
	 *
	 * @return void
	 */
	public function testAnUnreadableTerminationDateStillDatesTheRetention(): void {
		$mapper = $this->mapperWith([]);
		$this->makeService(
			$this->objectServiceWithRows(
				[['id' => 't-bad', 'slug' => 'bad-date', 'status' => 'terminated', 'terminatedAt' => 'not a date']]
			),
			$mapper,
		)->migrate();

		$this->assertCount(1, $mapper->inserted);
		$org = $mapper->inserted[0];
		$this->assertSame('retained', $org->getStatus());
		$this->assertNotNull($org->getRetainedAt(), 'a retained organisation must carry a retention start');
		$this->assertNull($org->getDeprovisionedAt());
	}

	/**
	 * One row that throws is counted as failed and the rest still migrate.
	 *
	 * A migration that aborted on the first bad row would leave an install
	 * half-migrated, with the satellites of the tenants it did not reach still
	 * pointing at a tenant store that is about to be retired.
	 *
	 * @return void
	 */
	public function testOneFailingRowIsCountedAndTheOthersStillMigrate(): void {
		$mapper = new class extends \stdClass {
			/** @var array<int, Organisation> */
			public array $inserted = [];

			/** @var array<int, Organisation> */
			public array $updated = [];

			// phpcs:ignore
			public function findByUuid(string $uuid): Organisation {
				throw new RuntimeException('not found');
			}

			// phpcs:ignore
			public function findBySlug(string $slug): Organisation {
				throw new RuntimeException('not found');
			}

			// phpcs:ignore
			public function insert(Organisation $org): Organisation {
				if ($org->getSlug() === 'explodes') {
					throw new RuntimeException('the store refused this row');
				}

				$this->inserted[] = $org;
				return $org;
			}
		};

		$summary = $this->makeService(
			$this->objectServiceWithRows(
				[
					['id' => 't-ok-1', 'slug' => 'fine-one', 'status' => 'active'],
					['id' => 't-bad', 'slug' => 'explodes', 'status' => 'active'],
					['id' => 't-ok-2', 'slug' => 'fine-two', 'status' => 'active'],
				]
			),
			$mapper,
		)->migrate();

		$this->assertSame(3, $summary['total']);
		$this->assertSame(2, $summary['migrated']);
		$this->assertSame(1, $summary['failed']);
		$this->assertCount(2, $mapper->inserted);
		$this->assertCount(2, $summary['mappings'], 'a failed row must not be reported as mapped');
	}

	/**
	 * A register with no legacy tenant schema migrates nothing and says so.
	 *
	 * The common case on a fresh install, and the one where an exception would
	 * be read as a broken migration rather than as an empty one.
	 *
	 * @return void
	 */
	public function testAnAbsentLegacyTenantSchemaIsAnEmptyRunAndNotAFailure(): void {
		$objectService = new class {
			// phpcs:ignore
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				throw new RuntimeException('no such schema');
			}
		};

		$mapper = $this->mapperWith([]);
		$summary = $this->makeService($objectService, $mapper)->migrate();

		$this->assertSame(0, $summary['total']);
		$this->assertSame(0, $summary['migrated']);
		$this->assertSame(0, $summary['failed']);
		$this->assertSame(0, $summary['refused']);
		$this->assertSame(0, $summary['repaired']);
		$this->assertCount(0, $mapper->inserted);
	}

}//end class
