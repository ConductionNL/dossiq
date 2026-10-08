<?php

/**
 * The SPA page hands the bundle the dossiq version Nextcloud installed.
 *
 * The settings dialog footer printed a build-time version: "dossiq
 * 0.4.47-unstable" on a 0.4.48-beta install, because the release writes its
 * version into info.xml after the bundle is built. The bundle now reads the
 * `version` initial state, so the page must provide it, from the installed
 * version Nextcloud records, not from anything baked into a file.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
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
 * @spec openspec/changes/notification-labels-and-tour-titles/specs/notification-labels/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\DashboardController;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Controller\DashboardController
 * @uses   \OCA\Dossiq\Service\Settings\MenuStructure
 */
final class DashboardControllerInstalledVersionTest extends TestCase {

	/**
	 * Render the page against an app config that knows the installed version.
	 *
	 * @param string $installed What Nextcloud recorded as installed.
	 *
	 * @return array<string, mixed> Every initial state the page provided.
	 */
	private function renderWith(string $installed): array {
		$provided = [];
		$initialState = $this->createMock(originalClassName: IInitialState::class);
		$initialState->method('provideInitialState')
			->willReturnCallback(
				static function (string $key, mixed $data) use (&$provided): void {
					$provided[$key] = $data;
				}
			);
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $default = '') use ($installed): string {
					if ($app === 'dossiq' && $key === 'installed_version') {
						return $installed;
					}

					return $default;
				}
			);

		$controller = new DashboardController(
			request: $this->createMock(originalClassName: IRequest::class),
			initialState: $initialState,
			appConfig: $appConfig,
			eventDispatcher: $this->createMock(originalClassName: IEventDispatcher::class),
		);
		$controller->page();

		return $provided;
	}//end renderWith()

	/**
	 * The page provides the installed version under `version`.
	 *
	 * @return void
	 */
	public function testThePageProvidesTheInstalledVersion(): void {
		$provided = $this->renderWith(installed: '0.4.48-beta.20261008003048');

		$this->assertArrayHasKey(key: 'version', array: $provided);
		$this->assertSame(expected: '0.4.48-beta.20261008003048', actual: $provided['version']);
	}//end testThePageProvidesTheInstalledVersion()

	/**
	 * It follows what is installed rather than a fixed value, which is the control.
	 *
	 * @return void
	 */
	public function testTheVersionFollowsTheInstall(): void {
		$this->assertSame(expected: '1.0.0', actual: $this->renderWith(installed: '1.0.0')['version']);
	}//end testTheVersionFollowsTheInstall()
}//end class
