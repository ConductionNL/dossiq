<?php

/**
 * Satellite Orphan Scanner test
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

use OCA\Dossiq\Service\SatelliteOrphanScanner;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\Organisation;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\SatelliteOrphanScanner
 */
class SatelliteOrphanScannerTest extends TestCase {
	/**
	 * A fake ObjectService answering per satellite schema.
	 *
	 * @param array<int, array<string, mixed>>                $rows     Rows for the `tenant` schema.
	 * @param array<string, array<int, array<string, mixed>>> $bySchema Rows for a named satellite schema.
	 *
	 * @return object The fake.
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
	 * A fake OrganisationMapper resolving only the organisations it was given.
	 *
	 * @param array<string, Organisation> $existingBySlug Organisations keyed by slug.
	 *
	 * @return object The fake.
	 */
	private function mapperWith(array $existingBySlug): object {
		return new class($existingBySlug) {
			/** @var array<string, Organisation> */
			public array $existingByUuid = [];

			/** @var array<int, Organisation> */
			public array $inserted = [];

			/** @var array<int, Organisation> */
			public array $updated = [];

			// phpcs:ignore
			public function __construct(array $existing) {
				foreach ($existing as $org) {
					$uuid = (string)$org->getUuid();
					if ($uuid !== '') {
						$this->existingByUuid[$uuid] = $org;
					}
				}
			}

			// phpcs:ignore
			public function findByUuid(string $uuid): Organisation {
				if (isset($this->existingByUuid[$uuid]) === true) {
					return $this->existingByUuid[$uuid];
				}

				throw new RuntimeException('not found');
			}
		};
	}

	/**
	 * The scanner over the given fakes.
	 *
	 * @param object $objectService Fake ObjectService.
	 * @param object $mapper        Fake OrganisationMapper.
	 *
	 * @return SatelliteOrphanScanner The scanner.
	 */
	private function makeScanner(object $objectService, object $mapper): SatelliteOrphanScanner {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister', 'dossiq']);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mapper);

		return new SatelliteOrphanScanner(
			$settings,
			$container,
			$appManager,
			$this->createMock(LoggerInterface::class),
		);
	}

	/**
	 * A tenantRef that resolves to nothing is REPORTED and never mapped.
	 *
	 * 🔴 There is no safe guess about which organisation an orphan meant.
	 * Attaching it to the nearest candidate would give one tenant another
	 * tenant's mandates or quotas, and every scoping filter downstream would
	 * then agree, because the row really would say so.
	 *
	 * So the assertion is in two halves and both matter: the orphan must be
	 * named in the report, AND nothing may be written. A reporter that quietly
	 * repointed the row would satisfy the first half alone.
	 *
	 * @return void
	 */
	public function testAnUnresolvableTenantRefIsReportedAndNothingIsWritten(): void {
		$known = new Organisation();
		$known->setUuid('org-real');
		$known->setSlug('gemeente-baarn');

		$mapper = $this->mapperWith(['gemeente-baarn' => $known]);
		$report = $this->makeScanner(
			$this->objectServiceWithRows(
				[],
				[
					'tenantUser' => [
						['id' => 'u1', 'tenantRef' => 'org-real'],
						['id' => 'u2', 'tenantRef' => 'org-gone'],
					],
					'tenantMandate' => [['id' => 'm1', 'tenantRef' => 'org-gone']],
				]
			),
			$mapper,
		)->reportOrphans();

		$this->assertSame(2, $report['orphans']);
		$this->assertSame(3, $report['scanned']);
		$this->assertSame(
			[
				['schema' => 'tenantUser', 'row' => 'u2', 'tenantRef' => 'org-gone'],
				['schema' => 'tenantMandate', 'row' => 'm1', 'tenantRef' => 'org-gone'],
			],
			$report['rows']
		);
		$this->assertCount(0, $mapper->inserted, 'the orphan scan must write nothing');
	}

	/**
	 * A row whose tenantRef resolves is not an orphan.
	 *
	 * The companion that catches a reporter which calls everything an orphan.
	 *
	 * @return void
	 */
	public function testARowWhoseTenantRefResolvesIsNotAnOrphan(): void {
		$known = new Organisation();
		$known->setUuid('org-real');
		$known->setSlug('gemeente-baarn');

		$report = $this->makeScanner(
			$this->objectServiceWithRows([], ['tenantQuota' => [['id' => 'q1', 'tenantRef' => 'org-real']]]),
			$this->mapperWith(['gemeente-baarn' => $known]),
		)->reportOrphans();

		$this->assertSame(0, $report['orphans']);
		$this->assertSame(1, $report['scanned']);
		$this->assertSame([], $report['rows']);
	}

	/**
	 * The shipped tier quota templates are not orphans.
	 *
	 * Twelve `tenantQuota` rows ship in the register seed, four per tier,
	 * each pointing at a sentinel uuid that belongs to no tenant. They are on
	 * EVERY install. A report that called them orphans would open with twelve
	 * false alarms and teach an operator to skim the list, which is how a real
	 * orphan goes unread.
	 *
	 * tasks.md 2b read the same twelve rows off the dev instance and called
	 * them test fixtures written on 2026-08-30. They are not. They are the
	 * register seed, and this test is what keeps them classified.
	 *
	 * @return void
	 */
	public function testTheShippedTierTemplatesAreCountedApartFromOrphans(): void {
		$report = $this->makeScanner(
			$this->objectServiceWithRows(
				[],
				[
					'tenantQuota' => [
						['id' => 'tpl-b', 'tenantRef' => '00000000-0000-0000-0000-000000000000'],
						['id' => 'tpl-s', 'tenantRef' => '00000000-0000-0000-0000-000000000001'],
						['id' => 'tpl-e', 'tenantRef' => '00000000-0000-0000-0000-000000000002'],
						['id' => 'q-real', 'tenantRef' => 'org-gone'],
					],
				]
			),
			$this->mapperWith([]),
		)->reportOrphans();

		$this->assertSame(3, $report['templates']);
		$this->assertSame(1, $report['orphans']);
		$this->assertSame([['schema' => 'tenantQuota', 'row' => 'q-real', 'tenantRef' => 'org-gone']], $report['rows']);
	}

	/**
	 * An empty tenantRef is an orphan too.
	 *
	 * It resolves to nothing, which is the definition, and a scan that only
	 * checked non-empty values would pass over the rows most likely to be
	 * wrong.
	 *
	 * @return void
	 */
	public function testAnEmptyTenantRefIsAnOrphan(): void {
		$report = $this->makeScanner(
			$this->objectServiceWithRows([], ['tenantBillingEvent' => [['id' => 'b1', 'tenantRef' => '']]]),
			$this->mapperWith([]),
		)->reportOrphans();

		$this->assertSame(1, $report['orphans']);
		$this->assertSame('', $report['rows'][0]['tenantRef']);
	}

	/**
	 * The scan covers the five satellites and does not reach tenantOnboardingTask.
	 *
	 * That schema is re-filed onto the engine Task as follow-up 7.1 of
	 * remove-casetask and still references `tenant`, so scanning it here would
	 * report seven shipped onboarding-template rows against a store this step
	 * does not own.
	 *
	 * @return void
	 */
	public function testTheScanCoversTheFiveSatellitesAndNotTheOnboardingTask(): void {
		$rowsFor = static fn (string $schema): array => [[ 'id' => $schema . '-1', 'tenantRef' => 'org-gone']];

		$report = $this->makeScanner(
			$this->objectServiceWithRows(
				[],
				[
					'tenantConfiguration' => $rowsFor('tenantConfiguration'),
					'tenantQuota' => $rowsFor('tenantQuota'),
					'tenantUser' => $rowsFor('tenantUser'),
					'tenantMandate' => $rowsFor('tenantMandate'),
					'tenantBillingEvent' => $rowsFor('tenantBillingEvent'),
					'tenantOnboardingTask' => $rowsFor('tenantOnboardingTask'),
				]
			),
			$this->mapperWith([]),
		)->reportOrphans();

		$this->assertSame(5, $report['scanned']);
		$this->assertSame(
			['tenantConfiguration', 'tenantQuota', 'tenantUser', 'tenantMandate', 'tenantBillingEvent'],
			array_keys($report['bySchema'])
		);
		$this->assertArrayNotHasKey('tenantOnboardingTask', $report['bySchema']);
	}
	/**
	 * Without OpenRegister the scan reports nothing, rather than everything.
	 *
	 * 🔴 Which way this fails is the whole question. There is no mapper to
	 * resolve a `tenantRef` against, so every row would resolve to nothing and
	 * a scan that kept going would report EVERY satellite row as an orphan.
	 * An operator acting on that report would be acting on a list produced by
	 * an absent dependency.
	 *
	 * So the scan declines instead: zero scanned, zero orphans, and the caller
	 * can tell "nothing to report" from "could not look" by the scanned count
	 * being zero too.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheScanReportsNothingRatherThanEverything(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(
			$this->objectServiceWithRows([], ['tenantUser' => [['id' => 'u1', 'tenantRef' => 'gone']]])
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['dossiq']);

		$report = (new SatelliteOrphanScanner(
			$settings,
			$this->createMock(ContainerInterface::class),
			$appManager,
			$this->createMock(LoggerInterface::class),
		))->reportOrphans();

		$this->assertSame(0, $report['orphans'], 'an absent dependency must not manufacture orphans');
		$this->assertSame(0, $report['scanned']);
		$this->assertSame([], $report['rows']);
		$this->assertSame([], $report['bySchema']);
	}

	/**
	 * A satellite schema that cannot be read is skipped, not fatal.
	 *
	 * A register missing one of the five is an ordinary state, and the other
	 * four still have something to say. Aborting would report zero orphans
	 * across the board, which reads exactly like a clean instance.
	 *
	 * @return void
	 */
	public function testASatelliteSchemaThatCannotBeReadDoesNotStopTheScan(): void {
		$objectService = new class {
			// phpcs:ignore
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				if ($schema === 'tenantQuota') {
					throw new RuntimeException('no such schema');
				}

				if ($schema === 'tenantUser') {
					return [['id' => 'u1', 'tenantRef' => 'gone']];
				}

				return [];
			}
		};

		$report = $this->makeScanner($objectService, $this->mapperWith([]))->reportOrphans();

		$this->assertSame(1, $report['orphans'], 'the readable schemas must still be reported');
		$this->assertSame(0, $report['bySchema']['tenantQuota']['scanned']);
		$this->assertSame(1, $report['bySchema']['tenantUser']['scanned']);
		$this->assertSame([['schema' => 'tenantUser', 'row' => 'u1', 'tenantRef' => 'gone']], $report['rows']);
	}

}//end class
