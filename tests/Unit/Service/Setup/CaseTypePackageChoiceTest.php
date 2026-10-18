<?php

/**
 * CaseTypePackageChoice Unit Tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Setup
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

namespace OCA\Dossiq\Tests\Unit\Service\Setup;

use OCA\Dossiq\Service\CaseDefinition\PackageInstaller;
use OCA\Dossiq\Service\Setup\CaseTypePackageChoice;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CaseTypePackageChoice.
 *
 * @covers \OCA\Dossiq\Service\Setup\CaseTypePackageChoice
 */
class CaseTypePackageChoiceTest extends TestCase {

	/**
	 * Build the choice over a fixed catalogue and app config.
	 *
	 * @param array<int,array<string,mixed>> $catalogue The packages.
	 * @param array<string,string>           $config    App-config values.
	 * @param array<string,mixed>|null       $outcome   What install() answers.
	 * @param array<string,string>           $written   Receives every app-config write.
	 *
	 * @return CaseTypePackageChoice The choice.
	 */
	private function choice(array $catalogue, array $config, ?array $outcome, array &$written): CaseTypePackageChoice {
		$packages = $this->createMock(PackageInstaller::class);
		$packages->method('catalogue')->willReturn($catalogue);
		if ($outcome !== null) {
			$packages->method('install')->willReturn($outcome);
		} else {
			$packages->expects($this->never())->method('install');
		}

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $config[$key] ?? $default
		);
		$appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$written): bool {
				$written[$key] = $value;
				return true;
			}
		);

		return new CaseTypePackageChoice(packages: $packages, appConfig: $appConfig);

	}//end choice()

	/**
	 * A catalogue with one package of each kind.
	 *
	 * @param string $installed The installed version of the on-request package.
	 *
	 * @return array<int,array<string,mixed>> The catalogue.
	 */
	private function catalogue(string $installed = ''): array {
		return [
			['id' => 'subsidie', 'title' => 'Subsidie', 'install' => 'on-request', 'installed' => $installed, 'valid' => true],
			['id' => 'broken', 'title' => 'Broken', 'install' => 'on-request', 'installed' => '', 'valid' => false],
			['id' => 'vth', 'title' => 'VTH', 'install' => 'auto', 'installed' => '1.0.0', 'valid' => true],
		];

	}//end catalogue()

	/**
	 * Only valid on-request packages are offered, after the none card.
	 *
	 * @return void
	 */
	public function testOffersValidOnRequestPackagesOnly(): void {
		$written = [];
		$options = $this->choice($this->catalogue(), [], null, $written)->options();

		$this->assertSame(['none', 'subsidie'], array_column($options, 'id'));
		$this->assertSame('Not installed yet', $options[1]['description']);

	}//end testOffersValidOnRequestPackagesOnly()

	/**
	 * The step is decided by a stored choice or by an installed package.
	 *
	 * @return void
	 */
	public function testDecidedByAChoiceOrAnInstall(): void {
		$written = [];
		$this->assertFalse($this->choice($this->catalogue(), [], null, $written)->isDecided());
		$this->assertTrue($this->choice($this->catalogue(), ['case_type_package' => 'none'], null, $written)->isDecided());
		$this->assertTrue($this->choice($this->catalogue('1.0.0'), [], null, $written)->isDecided());

	}//end testDecidedByAChoiceOrAnInstall()

	/**
	 * A successful install records the choice.
	 *
	 * @return void
	 */
	public function testAnInstallRecordsTheChoice(): void {
		$written = [];
		$outcome = $this->choice($this->catalogue(), [], ['status' => PackageInstaller::CURRENT, 'message' => 'Already installed.'], $written)
			->install('subsidie');

		$this->assertTrue($outcome['success']);
		$this->assertSame(['case_type_package' => 'subsidie'], $written);

	}//end testAnInstallRecordsTheChoice()

	/**
	 * A refused install stores nothing and passes the installer's message on.
	 *
	 * @return void
	 */
	public function testARefusedInstallStoresNothing(): void {
		$written = [];
		$outcome = $this->choice($this->catalogue(), [], ['status' => PackageInstaller::REFUSED, 'message' => 'Needs decidiq.'], $written)
			->install('subsidie');

		$this->assertFalse($outcome['success']);
		$this->assertSame('Needs decidiq.', $outcome['message']);
		$this->assertSame([], $written);

	}//end testARefusedInstallStoresNothing()

	/**
	 * An invalid or automatic package is never installed from the step.
	 *
	 * @return void
	 */
	public function testAPackageTheStepDoesNotOfferIsRefused(): void {
		$written = [];
		$choice = $this->choice($this->catalogue(), [], null, $written);

		$this->assertFalse($choice->install('broken')['success']);
		$this->assertFalse($choice->install('vth')['success']);
		$this->assertFalse($choice->install('none')['success']);

	}//end testAPackageTheStepDoesNotOfferIsRefused()
}//end class
