<?php

/**
 * RepairDemoDataSchemaForkPruneTest.
 *
 * The parts of the demo-fork repair around the retirement: the step's name,
 * the run without OpenRegister, the run with and without a usable
 * ObjectService, a repair that cannot complete, and the prune of dangling
 * schema ids from the demo configuration record.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\RepairDemoDataSchemaFork;
use OCA\Dossiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the run around the retirement and the configuration prune.
 *
 * @covers \OCA\Dossiq\Repair\RepairDemoDataSchemaFork
 */
class RepairDemoDataSchemaForkPruneTest extends TestCase {

	private IAppManager&MockObject $appManager;

	private ContainerInterface&MockObject $container;

	private SettingsService&MockObject $settings;

	private LoggerInterface&MockObject $logger;

	/**
	 * Info and warning lines the step wrote, in order.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $lines = [];

	/**
	 * Set up the mocks every case needs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appManager = $this->createMock(IAppManager::class);
		$this->appManager->method('getInstalledApps')->willReturn(['dossiq', 'openregister']);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->lines = [];
	}//end setUp()

	/**
	 * The name `occ maintenance:repair` shows.
	 *
	 * @return void
	 */
	public function testTheNameSaysWhatTheStepRepairs(): void {
		self::assertSame(
			'Retire the dossiq.demo schema fork and restore the Dossiq register title',
			$this->step()->getName()
		);
	}//end testTheNameSaysWhatTheStepRepairs()

	/**
	 * Without OpenRegister the step says so and resolves nothing.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingIsTouched(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['dossiq']);
		$this->container->expects(self::never())->method('get');
		$this->settings->expects(self::never())->method('getObjectService');

		(new RepairDemoDataSchemaFork($appManager, $this->container, $this->settings, $this->logger))->run($this->recordingOutput());

		self::assertSame([['info', 'Demo-fork repair: OpenRegister is not installed; nothing to repair.']], $this->lines);
	}//end testWithoutOpenRegisterNothingIsTouched()

	/**
	 * Dangling ids are dropped from the demo configuration, the live ones are
	 * kept in order, the record is saved, and the count is reported.
	 *
	 * @return void
	 */
	public function testDanglingSchemaIdsArePrunedFromTheDemoConfiguration(): void {
		$configuration = $this->configuration(schemas: [11, 12, 13, 14]);
		$mapper = $this->configurationMapper(configurations: [$configuration]);
		$this->wire(schemaMapper: $this->schemaMapper(gone: [12, 14]), configurationMapper: $mapper);

		$this->step()->run($this->recordingOutput());

		self::assertSame([11, 13], $configuration->schemas);
		self::assertSame([$configuration], $mapper->updated);
		self::assertSame(['dossiq.demo'], $mapper->asked);
		self::assertSame(
			[
				['info', 'Demo-fork repair: no `dossiq` register on this instance.'],
				['info', 'Demo-fork repair: no `dossiq.demo` schemas; nothing to retire.'],
				['info', 'Demo-fork repair: dropped 2 dangling schema id(s) from the demo configuration record.'],
			],
			$this->lines
		);
	}//end testDanglingSchemaIdsArePrunedFromTheDemoConfiguration()

	/**
	 * Every configuration record is pruned and the drops are counted together;
	 * a record without dangling ids is still saved, with the same list.
	 *
	 * @return void
	 */
	public function testEveryConfigurationIsPrunedAndTheDropsAddUp(): void {
		$first = $this->configuration(schemas: [21, 22]);
		$second = $this->configuration(schemas: [23]);
		$third = $this->configuration(schemas: null);
		$mapper = $this->configurationMapper(configurations: [$first, $second, $third]);
		$this->wire(schemaMapper: $this->schemaMapper(gone: [21, 23]), configurationMapper: $mapper);

		$this->step()->run($this->recordingOutput());

		self::assertSame([22], $first->schemas);
		self::assertSame([], $second->schemas);
		self::assertSame([], $third->schemas);
		self::assertSame([$first, $second, $third], $mapper->updated);
		self::assertContains(['info', 'Demo-fork repair: dropped 2 dangling schema id(s) from the demo configuration record.'], $this->lines);
	}//end testEveryConfigurationIsPrunedAndTheDropsAddUp()

	/**
	 * When nothing dangles nothing is reported, though the record is saved.
	 *
	 * @return void
	 */
	public function testNothingDanglingReportsNothing(): void {
		$configuration = $this->configuration(schemas: [31, 32]);
		$mapper = $this->configurationMapper(configurations: [$configuration]);
		$this->wire(schemaMapper: $this->schemaMapper(gone: []), configurationMapper: $mapper);

		$this->step()->run($this->recordingOutput());

		self::assertSame([31, 32], $configuration->schemas);
		self::assertSame([$configuration], $mapper->updated);
		self::assertSame([], array_values(array_filter($this->lines, static fn (array $line): bool => str_contains($line[1], 'dangling'))));
	}//end testNothingDanglingReportsNothing()

	/**
	 * A configuration list that cannot be read prunes nothing and does not fail the repair.
	 *
	 * @return void
	 */
	public function testAnUnreadableConfigurationListPrunesNothing(): void {
		$mapper = $this->configurationMapper(configurations: [], fails: true);
		$this->wire(schemaMapper: $this->schemaMapper(gone: []), configurationMapper: $mapper);
		$this->logger->expects(self::never())->method('warning');

		$this->step()->run($this->recordingOutput());

		self::assertSame([], $mapper->updated);
		self::assertSame(
			[
				['info', 'Demo-fork repair: no `dossiq` register on this instance.'],
				['info', 'Demo-fork repair: no `dossiq.demo` schemas; nothing to retire.'],
			],
			$this->lines
		);
	}//end testAnUnreadableConfigurationListPrunesNothing()

	/**
	 * An ObjectService that cannot be resolved runs the repair as it is.
	 *
	 * @return void
	 */
	public function testAnUnresolvableObjectServiceStillRunsTheRepair(): void {
		$configuration = $this->configuration(schemas: [41]);
		$mapper = $this->configurationMapper(configurations: [$configuration]);
		$this->wire(schemaMapper: $this->schemaMapper(gone: [41]), configurationMapper: $mapper);
		$this->settings->method('getObjectService')->willThrowException(new RuntimeException('not booted'));

		$this->step()->run($this->recordingOutput());

		self::assertSame([], $configuration->schemas);
		self::assertContains(['info', 'Demo-fork repair: dropped 1 dangling schema id(s) from the demo configuration record.'], $this->lines);
	}//end testAnUnresolvableObjectServiceStillRunsTheRepair()

	/**
	 * With an ObjectService the whole repair runs inside its system elevation.
	 *
	 * @return void
	 */
	public function testTheRepairRunsAsTheSystemWhenItCan(): void {
		$configuration = $this->configuration(schemas: [51, 52]);
		$mapper = $this->configurationMapper(configurations: [$configuration]);
		$this->wire(schemaMapper: $this->schemaMapper(gone: [52]), configurationMapper: $mapper);
		$objectService = new class {
			public int $elevations = 0;

			/**
			 * @param callable $operation The operation to run as the system.
			 *
			 * @return mixed What it returns.
			 */
			public function runAsSystem(callable $operation): mixed {
				$this->elevations++;
				return $operation();
			}
		};
		$this->settings->method('getObjectService')->willReturn($objectService);

		$this->step()->run($this->recordingOutput());

		self::assertSame(1, $objectService->elevations);
		self::assertSame([51], $configuration->schemas);
	}//end testTheRepairRunsAsTheSystemWhenItCan()

	/**
	 * A repair that cannot complete warns and logs instead of failing the upgrade.
	 *
	 * @return void
	 */
	public function testARepairThatCannotCompleteWarnsAndLogs(): void {
		$this->container->method('get')->willThrowException(new RuntimeException('no mapper'));
		$this->logger->expects(self::once())->method('warning')->with(
			'Dossiq: demo-fork repair failed',
			['app' => 'dossiq', 'exception' => 'no mapper']
		);

		$this->step()->run($this->recordingOutput());

		self::assertSame([['warning', 'Demo-fork repair could not complete: no mapper']], $this->lines);
	}//end testARepairThatCannotCompleteWarnsAndLogs()

	/**
	 * Resolve the three OpenRegister lookups the run makes before any retirement.
	 *
	 * @param object $schemaMapper        The schema mapper.
	 * @param object $configurationMapper The configuration mapper.
	 *
	 * @return void
	 */
	private function wire(object $schemaMapper, object $configurationMapper): void {
		$registerMapper = new class {
			/**
			 * @param string|int $id            The register id.
			 * @param boolean    $_rbac         Unused.
			 * @param boolean    $_multitenancy Unused.
			 *
			 * @return object Never: there is no such register.
			 */
			public function find(string|int $id, bool $_rbac = true, bool $_multitenancy = true): object {
				throw new RuntimeException('not found');
			}
		};

		$this->container->method('get')->willReturnCallback(
			static fn (string $id): object => match ($id) {
				'OCA\OpenRegister\Db\SchemaMapper' => $schemaMapper,
				'OCA\OpenRegister\Db\RegisterMapper' => $registerMapper,
				'OCA\OpenRegister\Db\ConfigurationMapper' => $configurationMapper,
				default => throw new RuntimeException('unexpected lookup ' . $id),
			}
		);
	}//end wire()

	/**
	 * A schema mapper with no forked schemas, whose `find` fails for the gone ids.
	 *
	 * @param array<int, int> $gone Ids that no longer resolve.
	 *
	 * @return object The fake mapper.
	 */
	private function schemaMapper(array $gone): object {
		return new class($gone) {
			/**
			 * @param array<int, int> $gone Ids that no longer resolve.
			 */
			public function __construct(private readonly array $gone) {
			}

			/**
			 * @param array<string, mixed>|null $filters       The filter.
			 * @param boolean                   $_rbac         Unused.
			 * @param boolean                   $_multitenancy Unused.
			 *
			 * @return array<int, object> No rows.
			 */
			public function findAll(?array $filters = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return [];
			}

			/**
			 * @param integer $id            The schema id.
			 * @param boolean $_rbac         Unused.
			 * @param boolean $_multitenancy Unused.
			 *
			 * @return object The schema.
			 */
			public function find(int $id, bool $_rbac = true, bool $_multitenancy = true): object {
				if (in_array($id, $this->gone, true) === true) {
					throw new RuntimeException('Schema ' . $id . ' not found');
				}

				return new \stdClass();
			}
		};
	}//end schemaMapper()

	/**
	 * A configuration record holding schema ids.
	 *
	 * @param array<int, int>|null $schemas The ids it lists.
	 *
	 * @return object The fake record.
	 */
	private function configuration(?array $schemas): object {
		return new class($schemas) {
			/**
			 * @param array<int, int>|null $schemas The ids it lists.
			 */
			public function __construct(public ?array $schemas) {
			}

			/**
			 * @return array<int, int>|null The ids.
			 */
			public function getSchemas(): ?array {
				return $this->schemas;
			}

			/**
			 * @param array<int, int> $schemas The ids to keep.
			 *
			 * @return void
			 */
			public function setSchemas(array $schemas): void {
				$this->schemas = $schemas;
			}
		};
	}//end configuration()

	/**
	 * A configuration mapper answering with fixed records and recording what it saved.
	 *
	 * @param array<int, object> $configurations The records under the demo application.
	 * @param boolean            $fails          Whether the lookup fails.
	 *
	 * @return object The fake mapper.
	 */
	private function configurationMapper(array $configurations, bool $fails = false): object {
		return new class($configurations, $fails) {
			/** @var array<int, object> */
			public array $updated = [];

			/** @var array<int, string> */
			public array $asked = [];

			/**
			 * @param array<int, object> $configurations The records.
			 * @param boolean            $fails          Whether the lookup fails.
			 */
			public function __construct(private readonly array $configurations, private readonly bool $fails) {
			}

			/**
			 * @param string  $app          The application id.
			 * @param boolean $systemLookup Whether to bypass the organisation filter.
			 *
			 * @return array<int, object> The records.
			 */
			public function findByApp(string $app, bool $systemLookup = false): array {
				if ($this->fails === true) {
					throw new RuntimeException('table missing');
				}

				$this->asked[] = $app;
				return $this->configurations;
			}

			/**
			 * @param object $configuration The record to save.
			 *
			 * @return object The record.
			 */
			public function update(object $configuration): object {
				$this->updated[] = $configuration;
				return $configuration;
			}
		};
	}//end configurationMapper()

	/**
	 * An output that records every info and warning line.
	 *
	 * @return IOutput The output.
	 */
	private function recordingOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		foreach (['info', 'warning'] as $level) {
			$output->method($level)->willReturnCallback(
				function (string $line) use ($level): void {
					$this->lines[] = [$level, $line];
				}
			);
		}

		return $output;
	}//end recordingOutput()

	/**
	 * The step under test.
	 *
	 * @return RepairDemoDataSchemaFork The step.
	 */
	private function step(): RepairDemoDataSchemaFork {
		return new RepairDemoDataSchemaFork($this->appManager, $this->container, $this->settings, $this->logger);
	}//end step()
}//end class
