<?php

/**
 * The upgrade recolours the statuses dossiq seeded, and nothing else.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-types/spec.md#requirement-a-shipped-status-takes-the-colour-its-boards-draw
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\RecolourShippedStatusTypes;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\StatusTypePalette;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The recolour step over a double of the object service.
 */
class RecolourShippedStatusTypesTest extends TestCase {

	/**
	 * The app config the step reads and writes.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Run the step over these status rows.
	 *
	 * @param array<int, array<string, mixed>> $rows   The stored rows.
	 * @param array<int, string>               $refuse Row ids whose write fails.
	 *
	 * @return object The object service double, holding the writes.
	 */
	private function runStep(array $rows, array $refuse = []): object {
		$objects = new class($rows, $refuse) {
			/**
			 * The patches, by row id.
			 *
			 * @var array<string, array<string, mixed>>
			 */
			public array $patches = [];

			/**
			 * @param array<int, array<string, mixed>> $rows   The rows.
			 * @param array<int, string>               $refuse Ids whose write fails.
			 */
			public function __construct(public array $rows, private array $refuse) {
			}

			/**
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>> One page.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
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
			static fn (string $key, string $default = ''): string => (['register' => 'dossiq', 'status_type_schema' => 'statusType'][$key] ?? $default)
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

		$step = new RecolourShippedStatusTypes(
			settingsService: $settings,
			appConfig: $appConfig,
			palette: new StatusTypePalette(),
			logger: new NullLogger()
		);
		$step->run(output: $this->createMock(IOutput::class));

		return $objects;
	}//end runStep()

	/**
	 * Seeded rows still on a shipped colour take the board colour; a chosen
	 * colour, an administrator's own status and an unknown name stay.
	 *
	 * @return void
	 */
	public function testOnlySeededRowsOnAShippedColourAreRecoloured(): void {
		$objects = $this->runStep(
			rows: [
				['id' => 's-1', 'name' => 'Ontvangen', '@self' => ['owner' => '__system__']],
				['id' => 's-2', 'name' => 'In behandeling', 'colour' => 'orange', '@self' => ['owner' => '__system__', 'slug' => 'melding-in-behandeling']],
				['id' => 's-3', 'name' => 'Afgehandeld', 'colour' => 'grey', '@self' => ['owner' => '__system__']],
				['id' => 's-4', 'name' => 'In behandeling', 'colour' => 'red', '@self' => ['owner' => '__system__', 'slug' => 'melding-in-behandeling']],
				['id' => 's-5', 'name' => 'Ontvangen', '@self' => ['owner' => 'admin']],
				['id' => 's-6', 'name' => 'Iets eigens', '@self' => ['owner' => '__system__']],
				['id' => 's-7', 'name' => 'Besluit', 'colour' => 'teal', '@self' => ['owner' => '__system__']],
			]
		);

		$this->assertSame(
			[
				's-1' => ['colour' => 'blue'],
				's-2' => ['colour' => 'purple'],
				's-3' => ['colour' => 'green'],
			],
			$objects->patches
		);
		$this->assertSame(RecolourShippedStatusTypes::DONE_VERSION, $this->config[RecolourShippedStatusTypes::DONE_KEY]);
	}//end testOnlySeededRowsOnAShippedColourAreRecoloured()

	/**
	 * A row that cannot be written leaves the step to run again.
	 *
	 * @return void
	 */
	public function testAFailedWriteRunsAgain(): void {
		$this->runStep(rows: [['id' => 's-1', 'name' => 'Ontvangen', '@self' => ['owner' => '__system__']]], refuse: ['s-1']);
		$this->assertArrayNotHasKey(RecolourShippedStatusTypes::DONE_KEY, $this->config);
	}//end testAFailedWriteRunsAgain()

	/**
	 * Once done, the step writes nothing again.
	 *
	 * @return void
	 */
	public function testItRunsOnce(): void {
		$this->config[RecolourShippedStatusTypes::DONE_KEY] = RecolourShippedStatusTypes::DONE_VERSION;
		$objects = $this->runStep(rows: [['id' => 's-1', 'name' => 'Ontvangen', '@self' => ['owner' => '__system__']]]);
		$this->assertSame([], $objects->patches);
	}//end testItRunsOnce()
}//end class
