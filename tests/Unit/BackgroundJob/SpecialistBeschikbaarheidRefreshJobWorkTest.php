<?php

/**
 * SpecialistBeschikbaarheidRefreshJob work characterisation.
 *
 * Pins which availability records one run ages out to `afwezig`: only a
 * record with an id, a readable lastUpdate and a status other than afwezig
 * that is older than four poll intervals (at least five seconds each); a
 * failing write or read is logged and keeps the cache.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\BackgroundJob;

use OCA\Dossiq\BackgroundJob\SpecialistBeschikbaarheidRefreshJob;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MakesBackgroundServiceAccount;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * One run of the availability refresh.
 *
 * @covers \OCA\Dossiq\BackgroundJob\SpecialistBeschikbaarheidRefreshJob
 * @covers \OCA\Dossiq\BackgroundJob\AvailabilityRun
 * @covers \OCA\Dossiq\BackgroundJob\OpenRegisterObjects
 */
class SpecialistBeschikbaarheidRefreshJobWorkTest extends TestCase {
	use MakesBackgroundServiceAccount;

	/**
	 * The records the search answers.
	 *
	 * @var array<int, mixed>
	 */
	public array $records = [];

	/**
	 * Every patch as [id, changes, register, schema].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>, 2: mixed, 3: mixed}>
	 */
	public array $patches = [];

	/**
	 * Every search as [register, schema, filters].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	public array $searches = [];

	/**
	 * Thrown by the search when set.
	 *
	 * @var RuntimeException|null
	 */
	public ?RuntimeException $searchFailure = null;

	/**
	 * Configuration values.
	 *
	 * @var array<string, string>
	 */
	private array $config = ['register' => 'reg', 'specialist_beschikbaarheid_schema' => 'avail'];

	/**
	 * The poll interval setting.
	 *
	 * @var string
	 */
	private string $pollInterval = '30';

	/**
	 * Installed apps.
	 *
	 * @var array<int, string>
	 */
	private array $apps = ['openregister'];

	/**
	 * Whether the object service is there.
	 *
	 * @var bool
	 */
	private bool $hasObjectService = true;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Run the job once.
	 *
	 * @return void
	 */
	private function runJob(): void {
		$objects = new class ($this) {
			/**
			 * @param SpecialistBeschikbaarheidRefreshJobWorkTest $test The test.
			 */
			public function __construct(private SpecialistBeschikbaarheidRefreshJobWorkTest $test) {
			}

			/**
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, mixed>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				if ($this->test->searchFailure !== null) {
					throw $this->test->searchFailure;
				}

				$this->test->searches[] = [$register, $schema, $filters];
				return $this->test->records;
			}

			/**
			 * @param string               $objectId The id.
			 * @param array<string, mixed> $data     The changes.
			 * @param mixed                $register The register.
			 * @param mixed                $schema   The schema.
			 *
			 * @return array<string, mixed>
			 */
			public function patchObject(string $objectId, array $data, mixed $register = null, mixed $schema = null): array {
				if ($objectId === 'fails') {
					throw new RuntimeException('locked');
				}

				$this->test->patches[] = [$objectId, $data, $register, $schema];
				return $data;
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturnCallback(fn () => ($this->hasObjectService === true ? $objects : null));
		$settings->method('getConfigValue')->willReturnCallback(fn (string $key, string $default = ''): string => ($this->config[$key] ?? $default));
		$settings->method('getKccConfigValue')->willReturnCallback(fn (): string => $this->pollInterval);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturnCallback(fn (): array => $this->apps);

		$job = $this->buildWith(
			SpecialistBeschikbaarheidRefreshJob::class,
			[
				'time' => $this->createMock(ITimeFactory::class),
				'settingsService' => $settings,
				'appManager' => $apps,
				'logger' => $this->logger,
				'serviceAccount' => $this->backgroundAccount(),
			]
		);
		$this->runJobOnce(job: $job);
	}//end runJob()

	/**
	 * A timestamp some seconds ago.
	 *
	 * @param int $seconds How long ago.
	 *
	 * @return string
	 */
	private function ago(int $seconds): string {
		return date('c', (time() - $seconds));
	}//end ago()

	/**
	 * Only stale records that are not yet afwezig are aged out, with a fresh lastUpdate.
	 *
	 * @return void
	 */
	public function testOnlyStaleRecordsAreAgedOut(): void {
		$entity = new class ($this->ago(1000)) {
			/**
			 * @param string $lastUpdate The last update.
			 */
			public function __construct(private string $lastUpdate) {
			}

			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['uuid' => 'u-entity', 'status' => 'beschikbaar', 'lastUpdate' => $this->lastUpdate];
			}
		};
		$plain = new \stdClass();
		$plain->id = 'u-plain';
		$plain->status = 'bezet';
		$plain->lastUpdate = $this->ago(1000);
		$this->records = [
			['id' => 'stale', 'status' => 'beschikbaar', 'lastUpdate' => $this->ago(1000)],
			['id' => 'fresh', 'status' => 'beschikbaar', 'lastUpdate' => $this->ago(30)],
			['id' => 'absent', 'status' => 'afwezig', 'lastUpdate' => $this->ago(1000)],
			['id' => 'unreadable', 'status' => 'beschikbaar', 'lastUpdate' => 'not a date'],
			['status' => 'beschikbaar', 'lastUpdate' => $this->ago(1000)],
			['uuid' => 'by-uuid', 'lastUpdate' => $this->ago(1000)],
			$entity,
			$plain,
			'not a record',
		];
		$this->logger->expects($this->never())->method('warning');

		$this->runJob();

		$this->assertSame([['reg', 'avail', ['_limit' => 500]]], $this->searches);
		$this->assertSame(['stale', 'by-uuid', 'u-entity', 'u-plain'], array_column($this->patches, 0));
		foreach ($this->patches as $patch) {
			$this->assertSame(['status', 'lastUpdate'], array_keys($patch[1]));
			$this->assertSame('afwezig', $patch[1]['status']);
			$this->assertLessThanOrEqual(5, abs(time() - strtotime($patch[1]['lastUpdate'])));
			$this->assertSame(['reg', 'avail'], [$patch[2], $patch[3]]);
		}
	}//end testOnlyStaleRecordsAreAgedOut()

	/**
	 * Stale is four poll intervals, and the interval is at least five seconds.
	 *
	 * @return void
	 */
	public function testStaleIsFourPollIntervalsOfAtLeastFiveSeconds(): void {
		$this->pollInterval = '1';
		$this->records = [
			['id' => 'older', 'status' => 'beschikbaar', 'lastUpdate' => $this->ago(40)],
			['id' => 'younger', 'status' => 'beschikbaar', 'lastUpdate' => $this->ago(10)],
		];

		$this->runJob();

		$this->assertSame(['older'], array_column($this->patches, 0));
	}//end testStaleIsFourPollIntervalsOfAtLeastFiveSeconds()

	/**
	 * A failing write is logged and the next record is still aged out.
	 *
	 * @return void
	 */
	public function testAFailingWriteIsLoggedAndTheRunGoesOn(): void {
		$this->records = [
			['id' => 'fails', 'status' => 'beschikbaar', 'lastUpdate' => $this->ago(1000)],
			['id' => 'next', 'status' => 'beschikbaar', 'lastUpdate' => $this->ago(1000)],
		];
		$this->logger->expects($this->once())->method('warning')->with(
			'Dossiq: could not age out stale specialist record: locked',
			['app' => 'dossiq']
		);

		$this->runJob();

		$this->assertSame(['next'], array_column($this->patches, 0));
	}//end testAFailingWriteIsLoggedAndTheRunGoesOn()

	/**
	 * A failing read is logged and keeps the cache.
	 *
	 * @return void
	 */
	public function testAFailingReadKeepsTheCache(): void {
		$this->searchFailure = new RuntimeException('index down');
		$this->logger->expects($this->once())->method('warning')->with(
			'Dossiq: specialist availability refresh could not read records (keeping cache): index down',
			['app' => 'dossiq']
		);

		$this->runJob();

		$this->assertSame([], $this->patches);
	}//end testAFailingReadKeepsTheCache()

	/**
	 * Without OpenRegister, its object service or the configuration nothing is read.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredRunReadsNothing(): void {
		$this->apps = ['dossiq'];
		$this->runJob();
		$this->apps = ['openregister'];
		$this->hasObjectService = false;
		$this->runJob();
		$this->hasObjectService = true;
		$this->config['register'] = '';
		$this->runJob();
		$this->config = ['register' => 'reg', 'specialist_beschikbaarheid_schema' => ''];
		$this->runJob();

		$this->assertSame([], $this->searches);
	}//end testAnUnconfiguredRunReadsNothing()
}//end class
