<?php

/**
 * Tests for the setup check that says whether DSO intake is on.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\SetupCheck
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/dso-intake-on-by-default/specs/vth-dso-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\SetupCheck;

use OCA\Dossiq\SetupCheck\DsoIntakeCheck;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\SetupCheck\SetupResult;
use PHPUnit\Framework\TestCase;

/**
 * An empty `dso_vergunningaanvraag_schema` means no DSO verzoek becomes a case.
 *
 * @covers \OCA\Dossiq\SetupCheck\DsoIntakeCheck
 * @uses   \OCA\Dossiq\Support\FleetAppId
 */
class DsoIntakeCheckTest extends TestCase {

	/**
	 * Without integriq the check warns that DSO intake is off.
	 *
	 * @return void
	 */
	public function testWarnsWithoutIntegriq(): void {
		$result = $this->check(value: '', installed: ['openregister'])->run();

		$this->assertSame(SetupResult::WARNING, $result->getSeverity());
		$this->assertStringContainsString('integriq is not installed', (string)$result->getDescription());
	}//end testWarnsWithoutIntegriq()

	/**
	 * With integriq and an empty value the check warns that intake was turned off.
	 *
	 * @return void
	 */
	public function testWarnsWhenTheValueIsEmpty(): void {
		$result = $this->check(value: '', installed: ['integriq'])->run();

		$this->assertSame(SetupResult::WARNING, $result->getSeverity());
		$this->assertStringContainsString('No DSO intake schema is set', (string)$result->getDescription());
	}//end testWarnsWhenTheValueIsEmpty()

	/**
	 * A set value passes and names it.
	 *
	 * @return void
	 */
	public function testPassesWhenSet(): void {
		$result = $this->check(value: '60', installed: ['integriq'])->run();

		$this->assertSame(SetupResult::SUCCESS, $result->getSeverity());
		$this->assertStringContainsString('60', (string)$result->getDescription());
	}//end testPassesWhenSet()

	/**
	 * Registered with Nextcloud.
	 *
	 * @return void
	 */
	public function testIsRegistered(): void {
		$source = (string)file_get_contents(__DIR__.'/../../../lib/AppInfo/Application.php');

		$this->assertStringContainsString('registerSetupCheck(\OCA\Dossiq\SetupCheck\DsoIntakeCheck::class)', $source);
	}//end testIsRegistered()

	/**
	 * Build the check.
	 *
	 * @param string       $value     The stored value.
	 * @param list<string> $installed The installed app ids.
	 *
	 * @return DsoIntakeCheck
	 */
	private function check(string $value, array $installed): DsoIntakeCheck {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($app === 'dossiq' && $key === 'dso_vergunningaanvraag_schema') ? $value : $default
		);

		$manager = $this->createMock(IAppManager::class);
		$manager->method('isInstalled')->willReturnCallback(
			fn (string $appId): bool => in_array($appId, $installed, true)
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		return new DsoIntakeCheck(appConfig: $config, appManager: $manager, l10n: $l10n);
	}//end check()
}//end class
