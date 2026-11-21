<?php

/**
 * Unit tests for IntegrationMode: an integration's tier is read from its mode
 * setting case- and space-insensitively and falls back to log outside the
 * allowed tiers, and its other settings are read trimmed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\External;

use OCA\Dossiq\Service\External\IntegrationMode;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\External\IntegrationMode
 */
class IntegrationModeTest extends TestCase {

	/**
	 * A mode over the given app config values, recording each read.
	 *
	 * @param array<string, string> $config Values by key.
	 * @param list<array{string, string, string}> $reads The reads made.
	 */
	private function mode(array $config, array &$reads = []): IntegrationMode {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($config, &$reads): string {
				$reads[] = [$app, $key, $default];
				return $config[$key] ?? $default;
			}
		);
		return new IntegrationMode($appConfig);
	}//end mode()

	public function testTheTierIsReadInAnyCaseWithLogAsItsDefault(): void {
		$reads = [];
		$mode = $this->mode(['integration.woz.mode' => ' LIVE '], $reads);

		$this->assertSame('live', $mode->resolve('woz', [IntegrationMode::TEST, IntegrationMode::LIVE]));
		$this->assertSame([['dossiq', 'integration.woz.mode', 'log']], $reads);
	}//end testTheTierIsReadInAnyCaseWithLogAsItsDefault()

	public function testATierOutsideTheAllowedOnesIsLog(): void {
		$mode = $this->mode(['integration.woz.mode' => 'preprod']);

		$this->assertSame('log', $mode->resolve('woz', [IntegrationMode::TEST, IntegrationMode::LIVE]));
		$this->assertSame('preprod', $mode->resolve('woz', [IntegrationMode::PREPROD]));
	}//end testATierOutsideTheAllowedOnesIsLog()

	public function testASettingIsReadTrimmedUnderTheIntegrationsKey(): void {
		$reads = [];
		$mode = $this->mode(['integration.brk.baseUrl' => ' https://brk.example/api/ '], $reads);

		$this->assertSame('https://brk.example/api/', $mode->setting('brk', 'baseUrl', 'https://default'));
		$this->assertSame('https://default', $mode->setting('brk', 'other', ' https://default '));
		$this->assertSame('', $mode->setting('brk', 'apiKey'));
		$this->assertSame(
			[
				['dossiq', 'integration.brk.baseUrl', 'https://default'],
				['dossiq', 'integration.brk.other', ' https://default '],
				['dossiq', 'integration.brk.apiKey', ''],
			],
			$reads
		);
	}//end testASettingIsReadTrimmedUnderTheIntegrationsKey()
}//end class
