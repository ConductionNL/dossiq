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
		// The real class: it is readonly and cannot be doubled, and the real
		// one is what the container hands the form.
		$serverVersion = new ServerVersion();
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
		$this->assertGreaterThan(0, $major);
		$this->assertSame((string)$major, $provided['prerequisites']['nextcloud']['running']);
		$this->assertSame(
			($major >= 32 && $major <= 35),
			$provided['prerequisites']['nextcloud']['present']
		);
	}//end testTheFormPassesTheRunningNextcloud()
}//end class
