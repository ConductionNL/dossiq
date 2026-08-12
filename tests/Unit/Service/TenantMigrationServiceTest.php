<?php

/**
 * TenantMigrationService Unit Tests
 *
 * Verifies the one-time, idempotent migration of legacy procest `tenant` schema
 * objects onto OpenRegister Organisations (migrate-tenant-to-or-tenant,
 * ADR-022): row → Organisation field mapping, status vocabulary mapping, UUID
 * preservation, slug-based idempotency, and graceful no-op when OR is absent.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\OpenRegister\Db\Organisation;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\TenantMigrationService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for TenantMigrationService.
 *
 * @covers \OCA\Procest\Service\TenantMigrationService
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
		$appManager->method('getInstalledApps')->willReturn(['openregister', 'procest']);

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
	 * Legacy procest statuses map to OR's lifecycle vocabulary.
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

		$this->assertSame('provisioning', $bySlug['a']);
		$this->assertSame('suspended', $bySlug['b']);
		$this->assertSame('archived', $bySlug['c']);
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
		$appManager->method('getInstalledApps')->willReturn(['procest']);

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
