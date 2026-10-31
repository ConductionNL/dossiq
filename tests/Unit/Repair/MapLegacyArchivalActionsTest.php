<?php

/**
 * Tests for the repair step that maps legacy archival actions onto the enum.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\MapLegacyArchivalActions;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Repair\MapLegacyArchivalActions
 */
class MapLegacyArchivalActionsTest extends TestCase {

	/**
	 * The stored config the version gate reads and writes.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Run the step over these result types.
	 *
	 * @param array<int, array<string, mixed>> $rows   The stored result types.
	 * @param array<int, string>               $refuse Ids whose write fails.
	 *
	 * @return object The object service double, holding the writes.
	 */
	private function runStep(array $rows, array $refuse = []): object {
		$objects = new class($rows, $refuse) {
			/** @var array<string, array<string, mixed>> The patches, by id. */
			public array $patches = [];

			/** @var array<int, string> What each read and write ran as. */
			public array $identities = [];

			/** @var boolean Whether runAsSystem is on the stack. */
			private bool $asSystem = false;

			/**
			 * @param array<int, array<string, mixed>> $rows   The result types.
			 * @param array<int, string>               $refuse Ids whose write fails.
			 */
			public function __construct(public array $rows, private array $refuse) {
			}

			/**
			 * @param callable $work The work to run as the system user.
			 *
			 * @return void
			 */
			public function runAsSystem(callable $work): void {
				$this->asSystem = true;
				try {
					$work();
				} finally {
					$this->asSystem = false;
				}
			}

			/**
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>> One page.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				$this->identities[] = ($this->asSystem === true) ? 'system' : 'user';
				return ((int)($filters['_page'] ?? 1) > 1) ? [] : $this->rows;
			}

			/**
			 * @param string               $objectId The id.
			 * @param array<string, mixed> $data     The changes.
			 * @param mixed                $register The register.
			 * @param mixed                $schema   The schema.
			 *
			 * @return array<string, mixed>
			 */
			public function patchObject(string $objectId, array $data, mixed $register, mixed $schema): array {
				$this->identities[] = ($this->asSystem === true) ? 'system' : 'user';
				if (in_array($objectId, $this->refuse, true) === true) {
					throw new RuntimeException('refused');
				}

				$this->patches[$objectId] = $data;
				return $data;
			}
		};

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (['register' => 'dossiq', 'result_type_schema' => 'resultType'][$key] ?? $default)
		);
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);

		$step = new MapLegacyArchivalActions(settingsService: $settings, appConfig: $appConfig, logger: new NullLogger());
		$step->run(output: $this->createMock(IOutput::class));

		return $objects;
	}//end runStep()

	/**
	 * Retain and destroy become bewaren and vernietigen; enum values, other
	 * values and rows without one are left alone.
	 *
	 * @return void
	 */
	public function testLegacyValuesAreMappedAndTheRestIsLeftAlone(): void {
		$objects = $this->runStep(
			rows: [
				['id' => 'r-1', 'archivalAction' => 'retain'],
				['@self' => ['id' => 'r-2'], 'archivalAction' => 'destroy'],
				['id' => 'r-3', 'archivalAction' => 'bewaren'],
				['id' => 'r-4', 'archivalAction' => 'blijvend_bewaren'],
				['id' => 'r-5'],
				['id' => 'r-6', 'archivalAction' => 'Retain'],
			]
		);

		$this->assertSame(
			[
				'r-1' => ['archivalAction' => 'bewaren'],
				'r-2' => ['archivalAction' => 'vernietigen'],
			],
			$objects->patches
		);
		$this->assertSame(MapLegacyArchivalActions::DONE_VERSION, $this->config[MapLegacyArchivalActions::DONE_KEY]);

		// Every write is one the resultType enum accepts.
		$register = new RealSchemaValidator();
		foreach ($objects->patches as $id => $patch) {
			$this->assertSame([], $register->errors(slug: 'resultType', payload: $patch, creating: false), $id);
		}
	}//end testLegacyValuesAreMappedAndTheRestIsLeftAlone()

	/**
	 * Every read and write runs as the system user, so result types the
	 * upgrading user cannot see are mapped too.
	 *
	 * @return void
	 */
	public function testItReadsAndWritesAsTheSystemUser(): void {
		$objects = $this->runStep(rows: [['id' => 'r-1', 'archivalAction' => 'destroy']]);

		$this->assertNotSame([], $objects->identities);
		$this->assertSame(['system'], array_values(array_unique($objects->identities)));
	}//end testItReadsAndWritesAsTheSystemUser()

	/**
	 * Running over already mapped rows writes nothing.
	 *
	 * @return void
	 */
	public function testASecondPassOverMappedRowsWritesNothing(): void {
		$first = $this->runStep(rows: [['id' => 'r-1', 'archivalAction' => 'retain']]);
		$this->config = [];

		$mapped = [['id' => 'r-1', 'archivalAction' => $first->patches['r-1']['archivalAction']]];
		$this->assertSame([], $this->runStep(rows: $mapped)->patches);
	}//end testASecondPassOverMappedRowsWritesNothing()

	/**
	 * A result type that cannot be written keeps the gate open for the next
	 * run, and a finished run is not repeated.
	 *
	 * @return void
	 */
	public function testAFailedWriteRunsAgainAndAFinishedRunDoesNot(): void {
		$objects = $this->runStep(rows: [['id' => 'r-1', 'archivalAction' => 'retain']], refuse: ['r-1']);
		$this->assertSame([], $objects->patches);
		$this->assertArrayNotHasKey(MapLegacyArchivalActions::DONE_KEY, $this->config);

		$this->config[MapLegacyArchivalActions::DONE_KEY] = MapLegacyArchivalActions::DONE_VERSION;
		$this->assertSame([], $this->runStep(rows: [['id' => 'r-9', 'archivalAction' => 'retain']])->patches);
	}//end testAFailedWriteRunsAgainAndAFinishedRunDoesNot()
}//end class
