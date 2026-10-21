<?php

/**
 * The SPA page hands the bundle the app config its lists resolve `@config.<key>` against.
 *
 * The contacts and organisations lists open on the municipality's own city
 * ("Woonplaats: Zuiddrecht", boards DqContacten and DqOrganisaties). The city
 * is an admin setting, and the library reads it off the `config` initial
 * state as `@config.municipalityCity`.
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
 * @spec openspec/specs/admin-settings/spec.md
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
final class DashboardControllerAppConfigTest extends TestCase {

	/**
	 * Render the page against an app config holding a municipality city.
	 *
	 * @param string $city What the administrator typed.
	 *
	 * @return array<string, mixed> Every initial state the page provided.
	 */
	private function renderWith(string $city): array {
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
				static function (string $app, string $key, string $default = '') use ($city): string {
					if ($app === 'dossiq' && $key === 'municipality_city') {
						return $city;
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
	 * The page provides the city under `config.municipalityCity`.
	 *
	 * @return void
	 */
	public function testThePageProvidesTheMunicipalityCity(): void {
		$provided = $this->renderWith(city: ' Zuiddrecht ');

		$this->assertArrayHasKey(key: 'config', array: $provided);
		$this->assertSame(expected: ['municipalityCity' => 'Zuiddrecht'], actual: $provided['config']);
	}//end testThePageProvidesTheMunicipalityCity()

	/**
	 * Without a city the key is empty, so the lens that names it is left out.
	 *
	 * @return void
	 */
	public function testWithoutACityTheKeyIsEmpty(): void {
		$this->assertSame(expected: ['municipalityCity' => ''], actual: $this->renderWith(city: '')['config']);
	}//end testWithoutACityTheKeyIsEmpty()
}//end class
