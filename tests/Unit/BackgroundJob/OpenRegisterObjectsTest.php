<?php

/**
 * OpenRegisterObjects: the object service only while OpenRegister is installed.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\BackgroundJob;

use JsonSerializable;
use OCA\Dossiq\BackgroundJob\OpenRegisterObjects;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * A background job reaches OpenRegister's object service only when the app is
 * installed, and reads what it returns as arrays.
 *
 * @covers \OCA\Dossiq\BackgroundJob\OpenRegisterObjects
 * @uses \OCA\Dossiq\Service\Support\CastArray
 */
class OpenRegisterObjectsTest extends TestCase {

	/**
	 * The helper, with the installed apps and the settings' object service given.
	 *
	 * @param list<string> $installed     The installed apps.
	 * @param object|null  $objectService What the settings resolve.
	 * @param int          $resolveCalls  How often the settings may be asked.
	 *
	 * @return OpenRegisterObjects
	 */
	private function objects(array $installed, ?object $objectService, int $resolveCalls): OpenRegisterObjects {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn($installed);

		$settings = $this->createMock(SettingsServiceInterface::class);
		$settings->expects($this->exactly($resolveCalls))->method('getObjectService')->willReturn($objectService);

		return new OpenRegisterObjects(appManager: $apps, settingsService: $settings);
	}//end objects()

	/**
	 * With OpenRegister installed the settings' object service is returned.
	 *
	 * @return void
	 */
	public function testTheObjectServiceWhenOpenRegisterIsInstalled(): void {
		$service = new stdClass();

		$this->assertSame($service, $this->objects(installed: ['files', 'openregister'], objectService: $service, resolveCalls: 1)->objectService());
	}//end testTheObjectServiceWhenOpenRegisterIsInstalled()

	/**
	 * Without OpenRegister the settings are not even asked.
	 *
	 * @return void
	 */
	public function testNothingWithoutOpenRegister(): void {
		$this->assertNull($this->objects(installed: ['files', 'openconnector'], objectService: new stdClass(), resolveCalls: 0)->objectService());
	}//end testNothingWithoutOpenRegister()

	/**
	 * A result is read as an array: arrays as they are, serialisable entities
	 * through jsonSerialize(), plain objects by their properties, anything else empty.
	 *
	 * @return void
	 */
	public function testAResultIsReadAsAnArray(): void {
		$objects = $this->objects(installed: [], objectService: null, resolveCalls: 0);
		$entity = new class () implements JsonSerializable {
			/**
			 * The entity as JSON data.
			 *
			 * @return array<string, string>
			 */
			public function jsonSerialize(): array {
				return ['id' => 'case-1', 'title' => 'Bouwvergunning'];
			}//end jsonSerialize()
		};
		$plain = new stdClass();
		$plain->id = 'case-2';

		$this->assertSame(['id' => 'case-0'], $objects->toArray(result: ['id' => 'case-0']));
		$this->assertSame(['id' => 'case-1', 'title' => 'Bouwvergunning'], $objects->toArray(result: $entity));
		$this->assertSame(['id' => 'case-2'], $objects->toArray(result: $plain));
		$this->assertSame([], $objects->toArray(result: 'not an object'));
	}//end testAResultIsReadAsAnArray()
}//end class
