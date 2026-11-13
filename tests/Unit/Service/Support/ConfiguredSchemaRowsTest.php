<?php

/**
 * ConfiguredSchemaRows: rows of a schema named by a config key.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\ConfiguredSchemaRows;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A configured schema is searched in the configured register; an unconfigured one has no rows.
 */
#[CoversClass(ConfiguredSchemaRows::class)]
final class ConfiguredSchemaRowsTest extends TestCase {

	/**
	 * The searches the object service double received.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * Rows over an object service answering $rows, configured as given.
	 *
	 * @param array<string, string> $config       Config key to value.
	 * @param bool                  $openRegister Whether an object service exists.
	 *
	 * @return ConfiguredSchemaRows
	 */
	private function rows(array $config, bool $openRegister = true): ConfiguredSchemaRows {
		$calls = &$this->calls;
		$service = new class($calls) {
			public function __construct(private array &$calls) {
			}

			public function searchObjects(array $query, ...$scope): array {
				$this->calls[] = $query;
				return [['id' => 'r-1'], new class implements \JsonSerializable {
					public function jsonSerialize(): array {
						return ['id' => 'r-2'];
					}
				}];
			}
		};
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($openRegister === true ? $service : null);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ($config[$key] ?? ''));

		return new ConfiguredSchemaRows($settings);
	}//end rows()

	public function testAConfiguredSchemaIsSearchedWithTheFilters(): void {
		$rows = $this->rows(['register' => '5', 'note_schema' => '30'])->search('note_schema', ['case' => 'c-1']);

		$this->assertSame([['id' => 'r-1'], ['id' => 'r-2']], $rows);
		$this->assertSame([['case' => 'c-1', '@self' => ['register' => 5, 'schema' => 30]]], $this->calls);
	}//end testAConfiguredSchemaIsSearchedWithTheFilters()

	public function testAnUnconfiguredSchemaOrNoOpenRegisterHasNoRows(): void {
		$this->assertSame([], $this->rows(['register' => '5'])->search('note_schema', []));
		$this->assertSame([], $this->rows(['note_schema' => '30'])->search('note_schema', []));
		$this->assertSame([], $this->rows(['register' => '5', 'note_schema' => '30'], openRegister: false)->search('note_schema', []));
		$this->assertSame([], $this->calls);
	}//end testAnUnconfiguredSchemaOrNoOpenRegisterHasNoRows()
}//end class
