<?php

/**
 * The archival migration runs once, under a system identity, and only when OpenRegister can take it.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\MigrateArchivalToOpenRegister;
use OCA\Dossiq\Service\SettingsService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Repair\MigrateArchivalToOpenRegister
 * @uses   \OCA\Dossiq\Repair\Support\RunsUnderSystemIdentity
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class MigrateArchivalRunTest extends TestCase {

	/**
	 * What the step reported, prefixed with the level.
	 *
	 * @var list<string>
	 */
	public array $said = [];

	/**
	 * What the step did to its collaborators, in order.
	 *
	 * @var list<string>
	 */
	public array $calls = [];

	/**
	 * Declare the OpenRegister classes the step probes for, when absent.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		foreach ([
			'OCA\\OpenRegister\\Service\\Archival' => 'LegalHoldService',
			'OCA\\OpenRegister\\Db' => 'MagicMapper',
		] as $namespace => $class) {
			if (class_exists($namespace . '\\' . $class) === false) {
				eval('namespace ' . $namespace . '; class ' . $class . ' {}');
			}
		}

		if (class_exists('OCA\\OpenRegister\\Db\\RegisterMapper') === false) {
			eval('namespace OCA\\OpenRegister\\Db; class RegisterMapper {}');
		}
	}//end setUp()

	/**
	 * An output that records every line.
	 *
	 * @return IOutput The output.
	 */
	private function recordingOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(function (string $line): void {
			$this->said[] = 'info: ' . $line;
		});
		$output->method('warning')->willReturnCallback(function (string $line): void {
			$this->said[] = 'warning: ' . $line;
		});

		return $output;
	}//end recordingOutput()

	/**
	 * The step over its collaborators.
	 *
	 * @param bool                  $done      Whether the completion marker is set.
	 * @param bool                  $available Whether OpenRegister is available.
	 * @param array<string, string> $config    The dossiq config values.
	 * @param object|null           $store     The OpenRegister store.
	 * @param array<string, object> $services  Container services by class-name suffix.
	 *
	 * @return MigrateArchivalToOpenRegister The step.
	 */
	private function step(bool $done, bool $available, array $config, ?object $store=null, array $services=[]): MigrateArchivalToOpenRegister {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn($available);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ($config[$key] ?? ''));

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services): object {
				foreach ($services as $suffix => $service) {
					if (str_ends_with($id, $suffix) === true) {
						return $service;
					}
				}

				throw new RuntimeException('no ' . $id);
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturnCallback(
			static fn (string $app, string $key): bool => ($app === 'dossiq' && $key === 'archival_migration_completed' && $done)
		);
		$appConfig->method('setValueBool')->willReturnCallback(
			function (string $app, string $key, bool $value): bool {
				$this->calls[] = 'marker ' . $app . ' ' . $key . ' ' . var_export($value, true);
				return true;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context=[]) use ($level): void {
					$this->calls[] = $level . ' ' . $message . ' ' . json_encode($context);
				}
			);
		}

		return new MigrateArchivalToOpenRegister($settings, $container, $appConfig, $logger);
	}//end step()

	/**
	 * A store that runs work as the system and answers each schema with fixture rows.
	 *
	 * @param array<string, list<array>> $rows       Rows per schema.
	 * @param list<string>               $failSaves  Case ids whose save fails.
	 *
	 * @return object The store.
	 */
	private function store(array $rows, array $failSaves=[]): object {
		return new class($this, $rows, $failSaves) {
			/**
			 * The store.
			 *
			 * @param MigrateArchivalRunTest $test      The test, recording calls.
			 * @param array                  $rows      Rows per schema.
			 * @param list<string>           $failSaves Case ids whose save fails.
			 */
			public function __construct(
				private readonly MigrateArchivalRunTest $test,
				private readonly array $rows,
				private readonly array $failSaves,
			) {
			}//end __construct()

			/**
			 * Run work as the system.
			 *
			 * @param callable $work The work.
			 *
			 * @return void
			 */
			public function runAsSystem(callable $work): void {
				$this->test->calls[] = 'system start';
				$work();
				$this->test->calls[] = 'system end';
			}//end runAsSystem()

			/**
			 * The rows of a schema.
			 *
			 * @param array $query The query.
			 *
			 * @return array The rows.
			 */
			public function searchObjects(array $query): array {
				return ($this->rows[(string)$query['@self']['schema']] ?? []);
			}//end searchObjects()

			/**
			 * Record a save.
			 *
			 * @param array  $object   The object.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return array The object.
			 */
			public function saveObject(array $object, string $register, string $schema): array {
				if (in_array($object['case'], $this->failSaves, true) === true) {
					throw new RuntimeException('disk full');
				}

				$this->test->calls[] = 'save ' . $register . '/' . $schema . ' ' . $object['case'] . ' ' . $object['document'];
				return $object;
			}//end saveObject()
		};
	}//end store()

	/**
	 * A completed migration is not run again.
	 *
	 * @return void
	 */
	public function testACompletedMigrationIsNotRunAgain(): void {
		$this->step(done: true, available: true, config: ['register' => '5'])->run($this->recordingOutput());

		self::assertSame(['info: Archival migration already completed — skipping.'], $this->said);
		self::assertSame([], $this->calls);
	}//end testACompletedMigrationIsNotRunAgain()

	/**
	 * Without OpenRegister the migration is deferred and the marker left unset.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheMigrationIsDeferred(): void {
		$this->step(done: false, available: false, config: ['register' => '5'])->run($this->recordingOutput());

		self::assertSame(['warning: OpenRegister archival abstractions unavailable — archival migration deferred.'], $this->said);
		self::assertSame([], $this->calls);
	}//end testWithoutOpenRegisterTheMigrationIsDeferred()

	/**
	 * Without a configured register the migration is deferred and the marker left unset.
	 *
	 * @return void
	 */
	public function testWithoutARegisterTheMigrationIsDeferred(): void {
		$this->step(done: false, available: true, config: [])->run($this->recordingOutput());

		self::assertSame(['warning: Dossiq register not configured — archival migration deferred.'], $this->said);
		self::assertSame([], $this->calls);
	}//end testWithoutARegisterTheMigrationIsDeferred()

	/**
	 * Proof records are exported to the case documents as the system, then the marker is set.
	 *
	 * @return void
	 */
	public function testProofRecordsAreExportedAsTheSystemAndTheMarkerSet(): void {
		$store = $this->store(
			rows: [
				'31' => [
					['caseId' => 'case-a', 'archivId' => 'arch-1'],
					['caseId' => '', 'archivId' => 'orphan'],
					['caseId' => 'case-b', 'id' => 'p-2'],
					['caseId' => 'case-c'],
					['caseId' => 'case-d'],
				],
			],
			failSaves: ['case-d']
		);

		$this->step(
			done: false,
			available: true,
			config: ['register' => '5', 'archief_bewijs_schema' => '31', 'case_document_schema' => '32'],
			store: $store
		)->run($this->recordingOutput());

		self::assertSame(
			[
				'system start',
				'save 5/32 case-a arch-1',
				'save 5/32 case-b p-2',
				'save 5/32 case-c proof',
				'error Archival migration: proof export failed — source record preserved in place {"caseId":"case-d","error":"disk full"}',
				'marker dossiq archival_migration_completed true',
				'system end',
			],
			$this->calls
		);
		self::assertSame(
			['info: Archival migration complete: 0 legal hold(s) placed, 3 proof-of-transfer record(s) exported to the zaakdossier.'],
			$this->said
		);
	}//end testProofRecordsAreExportedAsTheSystemAndTheMarkerSet()

	/**
	 * An unreadable proof schema exports nothing but still completes.
	 *
	 * @return void
	 */
	public function testAnUnreadableProofSchemaExportsNothing(): void {
		$store = new class {
			/**
			 * Fail every search.
			 *
			 * @param array $query The query.
			 *
			 * @return array Never.
			 */
			public function searchObjects(array $query): array {
				throw new RuntimeException('index offline');
			}//end searchObjects()
		};

		$this->step(
			done: false,
			available: true,
			config: ['register' => '5', 'archief_bewijs_schema' => '31', 'case_document_schema' => '32'],
			store: $store
		)->run($this->recordingOutput());

		self::assertSame(
			[
				'warning Archival migration: could not read proof records {"error":"index offline"}',
				'marker dossiq archival_migration_completed true',
			],
			$this->calls
		);
		self::assertSame(
			['info: Archival migration complete: 0 legal hold(s) placed, 0 proof-of-transfer record(s) exported to the zaakdossier.'],
			$this->said
		);
	}//end testAnUnreadableProofSchemaExportsNothing()

	/**
	 * Unreadable suspended triggers place no hold.
	 *
	 * @return void
	 */
	public function testUnreadableSuspendedTriggersPlaceNoHold(): void {
		$store = new class {
			/**
			 * Fail every search.
			 *
			 * @param array $query The query.
			 *
			 * @return array Never.
			 */
			public function searchObjects(array $query): array {
				throw new RuntimeException('index offline');
			}//end searchObjects()
		};
		$legalHold = new \OCA\OpenRegister\Service\Archival\LegalHoldService();
		$mapper = new \OCA\OpenRegister\Db\MagicMapper();

		$this->step(
			done: false,
			available: true,
			config: ['register' => '5', 'overdracht_trigger_schema' => '40'],
			store: $store,
			services: ['LegalHoldService' => $legalHold, 'MagicMapper' => $mapper]
		)->run($this->recordingOutput());

		self::assertSame(
			[
				'warning Archival migration: could not read suspended triggers {"error":"index offline"}',
				'marker dossiq archival_migration_completed true',
			],
			$this->calls
		);
	}//end testUnreadableSuspendedTriggersPlaceNoHold()
}//end class
