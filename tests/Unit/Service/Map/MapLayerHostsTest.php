<?php

/**
 * The remembered hosts of the configured map layers.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Map
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
 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/gis-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Map;

use OCA\Dossiq\Service\Map\MapLayerHosts;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Which URLs give an origin, and how the list is read and grown.
 *
 * @covers \OCA\Dossiq\Service\Map\MapLayerHosts
 */
class MapLayerHostsTest extends TestCase {

	/**
	 * The stored value and every write.
	 *
	 * @var array<int, string>
	 */
	private array $writes = [];

	/**
	 * Hosts over an app config holding this value.
	 *
	 * @param string $stored The stored JSON.
	 *
	 * @return MapLayerHosts
	 */
	private function hosts(string $stored): MapLayerHosts {
		$this->writes = [];
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default) use (&$stored): string {
				$this->assertSame('dossiq', $app);
				$this->assertSame(MapLayerHosts::KEY, $key);
				return $stored;
			}
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) use (&$stored): bool {
				$this->writes[] = $value;
				$stored = $value;
				return true;
			}
		);

		return new MapLayerHosts($config);
	}

	public function testTheOriginOfAnHttpsServiceUrl(): void {
		$this->assertSame('https://services.arcgis.com', MapLayerHosts::originOf('https://Services.ArcGIS.com/abc/arcgis/rest/services/Trees/MapServer?f=json'));
		$this->assertSame('https://gis.example.nl:8443', MapLayerHosts::originOf(' https://gis.example.nl:8443/wms '));
		$this->assertSame('https://gis.example.nl', MapLayerHosts::originOf('https://gis.example.nl:443/wms'));
	}

	public function testOnlyAPlainHttpsHostGivesAnOrigin(): void {
		$this->assertNull(MapLayerHosts::originOf('http://gis.example.nl/wms'));
		$this->assertNull(MapLayerHosts::originOf('https://user:pw@gis.example.nl/wms'));
		$this->assertNull(MapLayerHosts::originOf('https://*.example.nl/wms'));
		$this->assertNull(MapLayerHosts::originOf('https://localhost/wms'));
		$this->assertNull(MapLayerHosts::originOf('https://gis.example.nl;script-src/x'));
		$this->assertNull(MapLayerHosts::originOf('not a url'));
		$this->assertNull(MapLayerHosts::originOf(''));
	}

	public function testAStoredListIsReadBackThroughTheSameCheck(): void {
		$hosts = $this->hosts('["https://a.example.nl","http://b.example.nl","https://a.example.nl/path","nonsense"]');

		$this->assertSame(['https://a.example.nl'], $hosts->hosts());
		$this->assertSame([], $this->hosts('{broken')->hosts());
		$this->assertSame([], $this->hosts('"https://a.example.nl"')->hosts());
	}

	public function testANewOriginIsAddedOnceAndAnUnusableUrlNever(): void {
		$hosts = $this->hosts('["https://a.example.nl"]');

		$this->assertTrue($hosts->remember('https://gis.example.nl/arcgis/rest/services/Bomen/MapServer'));
		$this->assertFalse($hosts->remember('https://gis.example.nl/other'));
		$this->assertFalse($hosts->remember('http://plain.example.nl/'));

		$this->assertSame(['["https:\/\/a.example.nl","https:\/\/gis.example.nl"]'], $this->writes);
		$this->assertSame(['https://a.example.nl', 'https://gis.example.nl'], $hosts->hosts());
	}

	public function testTheListStopsGrowingAtItsCeiling(): void {
		$full = [];
		for ($i = 0; $i < MapLayerHosts::MAX_HOSTS; $i++) {
			$full[] = 'https://h' . $i . '.example.nl';
		}

		$hosts = $this->hosts((string)json_encode($full));

		$this->assertFalse($hosts->remember('https://one-more.example.nl/wms'));
		$this->assertSame([], $this->writes);
	}
}//end class
