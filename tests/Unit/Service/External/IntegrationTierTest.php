<?php

/**
 * External-integration tier + adapter unit tests (external-integrations-test-environments).
 *
 * Proves the config-tier model: IntegrationMode defaults every seam to
 * `log` (fail-closed to no external call) and rejects unknown modes; the
 * live BRP/KvK adapters shape the mock/test API responses into the
 * existing result value-objects and never log the BSN. DigiD and eHerkenning
 * are integriq's identity broker (`OCA\Integriq\Auth\Idp`), not dossiq's.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\External
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/external-integration-test-wiring/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\External;

use OCA\Dossiq\Service\External\IntegrationMode;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\External\IntegrationMode
 */
class IntegrationTierTest extends TestCase {
	/**
	 * Build an IntegrationMode over a config map.
	 *
	 * @param array<string,string> $config integration.<x>.<y> => value.
	 *
	 * @return IntegrationMode
	 */
	private function mode(array $config): IntegrationMode {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($config): string {
				return $config[$key] ?? $default;
			}
		);

		return new IntegrationMode(appConfig: $appConfig);
	}//end mode()

	/**
	 * Unset / unknown modes fall back to `log` (fail-closed).
	 *
	 * @return void
	 */
	public function testModeDefaultsToLogAndRejectsUnknown(): void {
		$this->assertSame('log', $this->mode([])->resolve('brp', ['mock', 'test']));
		$this->assertSame(
			'log',
			$this->mode(['integration.brp.mode' => 'wharrgarbl'])->resolve('brp', ['mock', 'test']),
			'an unknown mode must not enable an external tier'
		);
		$this->assertSame(
			'test',
			$this->mode(['integration.brp.mode' => 'test'])->resolve('brp', ['mock', 'test'])
		);
	}//end testModeDefaultsToLogAndRejectsUnknown()
}//end class
