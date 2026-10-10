<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Settings
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\Queue\QueueUrgencySettings;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Settings\AdminSettings;
use OCP\App\IAppManager;
use OCP\AppFramework\Services\IInitialState;
use OCP\ServerVersion;
use PHPUnit\Framework\TestCase;

/**
 * The settings page hands the prerequisites block the Nextcloud it runs on.
 *
 * `Prerequisites::check()` reports "unknown, so not in range" without a
 * version. This asserts the wiring from the CALLER: the form passes the
 * major version, so the Nextcloud row reads present on a supported instance.
 *
 * @spec openspec/changes/r5-admin-settings-and-tour-tell-the-truth/specs/admin-settings/spec.md
 */
class AdminSettingsPrerequisitesTest extends TestCase {

	/**
	 * The prerequisites state carries the running major and says present.
	 *
	 * @return void
	 */
	public function testTheFormPassesTheRunningNextcloud(): void {
		$serverVersion = $this->serverVersion(major: 34);
		$major = $serverVersion->getMajorVersion();

		$provided = [];
		$initialState = $this->createMock(IInitialState::class);
		$initialState->method('provideInitialState')
			->willReturnCallback(
				static function (string $key, mixed $data) use (&$provided): void {
					$provided[$key] = $data;
				}
			);

		$settingsService = $this->createMock(SettingsService::class);
		$settings = new AdminSettings(
			appManager: $this->createMock(IAppManager::class),
			initialState: $initialState,
			settingsService: $settingsService,
			queueUrgency: new QueueUrgencySettings($settingsService),
			serverVersion: $serverVersion,
		);
		$settings->getForm();

		$this->assertArrayHasKey('prerequisites', $provided);
		$this->assertSame(34, $major);
		$this->assertSame('34', $provided['prerequisites']['nextcloud']['running']);
		$this->assertTrue($provided['prerequisites']['nextcloud']['present']);
	}//end testTheFormPassesTheRunningNextcloud()

	/**
	 * A real ServerVersion that reports a given major.
	 *
	 * The class is readonly, so PHPUnit cannot double it, and its constructor
	 * reads the server's version.php, which the OCP stubs CI runs against do
	 * not ship. So the instance is made without the constructor and its
	 * version filled from inside the class scope, which a readonly property
	 * allows once.
	 *
	 * @param int $major The major version to report.
	 *
	 * @return ServerVersion The instance.
	 */
	private function serverVersion(int $major): ServerVersion {
		$instance = (new \ReflectionClass(ServerVersion::class))->newInstanceWithoutConstructor();
		\Closure::bind(
			function () use ($major): void {
				$this->version = [$major, 0, 0];
			},
			$instance,
			ServerVersion::class
		)();

		return $instance;
	}//end serverVersion()
}//end class
