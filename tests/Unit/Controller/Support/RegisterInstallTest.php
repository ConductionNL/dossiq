<?php

/**
 * RegisterInstall Unit Tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller\Support
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
 *
 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller\Support;

use OCA\Dossiq\Controller\Support\RegisterInstall;
use OCA\Dossiq\Kernel\Contracts\PackageInstallerInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RegisterInstall.
 *
 * @covers \OCA\Dossiq\Controller\Support\RegisterInstall
 */
final class RegisterInstallTest extends TestCase {

	/**
	 * App-config values written through the fake, by key.
	 *
	 * @var array<string,string>
	 */
	private array $written = [];

	/**
	 * Build the class over a fixed app config.
	 *
	 * @param array<string,string>     $config   App-config values.
	 * @param bool                     $online   Whether OpenRegister is available.
	 * @param array<string,mixed>|null $outcome  What the package installer answers.
	 * @param SettingsServiceInterface|null $settings A settings double to use instead.
	 *
	 * @return RegisterInstall
	 */
	private function install(
		array $config=[],
		bool $online=true,
		?array $outcome=null,
		?SettingsServiceInterface $settings=null
	): RegisterInstall {
		$this->written = [];
		$appConfig     = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default='') => ($config[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->written[$key] = $value;
				return true;
			}
		);

		if ($settings === null) {
			$settings = $this->createMock(originalClassName: SettingsServiceInterface::class);
			$settings->method('isOpenRegisterAvailable')->willReturn($online);
		}

		$packages = $this->createMock(originalClassName: PackageInstallerInterface::class);
		if ($outcome !== null) {
			$packages->expects($this->once())->method('install')->with(RegisterInstall::SEED_PACKAGE)->willReturn($outcome);
		}

		return new RegisterInstall(appConfig: $appConfig, settingsService: $settings, packages: $packages);
	}//end install()

	/**
	 * Ready only with OpenRegister online and both the register and the case type schema configured.
	 *
	 * @return void
	 */
	public function testReadyNeedsOpenRegisterTheRegisterAndTheCaseTypeSchema(): void {
		$both = ['register' => 'dossiq', 'case_type_schema' => 'caseType'];

		$this->assertTrue($this->install(config: $both)->isReady());
		$this->assertFalse($this->install(config: $both, online: false)->isReady());
		$this->assertFalse($this->install(config: ['register' => 'dossiq'])->isReady());
		$this->assertFalse($this->install(config: ['case_type_schema' => 'caseType'])->isReady());
	}//end testReadyNeedsOpenRegisterTheRegisterAndTheCaseTypeSchema()

	/**
	 * Initialising forces the register import.
	 *
	 * @return void
	 */
	public function testInitialiseForcesTheRegisterImport(): void {
		$settings = $this->createMock(originalClassName: SettingsServiceInterface::class);
		$settings->expects($this->once())->method('loadConfiguration')->with(true)->willReturn([]);

		$response = $this->install(settings: $settings)->initialise();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}//end testInitialiseForcesTheRegisterImport()

	/**
	 * Installed and already current both complete the step and record it.
	 *
	 * @return void
	 */
	public function testInstalledOrCurrentRecordsTheSeed(): void {
		foreach ([PackageInstallerInterface::INSTALLED, PackageInstallerInterface::CURRENT] as $status) {
			$outcome  = ['status' => $status, 'message' => 'ok'];
			$response = $this->install(outcome: $outcome)->installSeedPackage();

			$this->assertSame(Http::STATUS_OK, $response->getStatus());
			$this->assertSame(['success' => true, 'message' => 'ok', 'detail' => $outcome], $response->getData());
			$this->assertSame(['setup_seed_done' => '1'], $this->written);
		}
	}//end testInstalledOrCurrentRecordsTheSeed()

	/**
	 * A refusal or a failure answers 422 with the installer's message and records nothing.
	 *
	 * @return void
	 */
	public function testARefusalAnswers422AndRecordsNothing(): void {
		foreach ([PackageInstallerInterface::REFUSED, PackageInstallerInterface::FAILED] as $status) {
			$outcome  = ['status' => $status, 'message' => 'no'];
			$response = $this->install(outcome: $outcome)->installSeedPackage();

			$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
			$this->assertSame(['success' => false, 'message' => 'no', 'detail' => $outcome], $response->getData());
			$this->assertSame([], $this->written);
		}
	}//end testARefusalAnswers422AndRecordsNothing()
}//end class
