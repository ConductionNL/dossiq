<?php

/**
 * The map hosts dossiq allows: the base-map tiles and the PDOK map layers
 * the location dialog draws over them.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\AppInfo
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

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use OCA\Dossiq\AppInfo\Registrar\MapCspRegistrar;
use OCA\Dossiq\Service\Map\MapLayerHosts;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\Security\IContentSecurityPolicyManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * The image hosts of the map policy.
 */
class MapCspRegistrarTest extends TestCase {

	/**
	 * A WMS layer is an image per map tile, so PDOK's service host is an
	 * allowed image source beside the base-map tiles.
	 *
	 * @return void
	 */
	public function testPdokMapLayersMayLoadTheirImages(): void {
		$policy  = null;
		$manager = $this->createMock(originalClassName: IContentSecurityPolicyManager::class);
		$manager->method('addDefaultPolicy')->willReturnCallback(
			static function (ContentSecurityPolicy $added) use (&$policy): void {
				$policy = $added;
			}
		);
		$server = $this->createMock(originalClassName: ContainerInterface::class);
		$server->method('get')->willReturn(value: $manager);

		(new MapCspRegistrar())->register(server: $server);

		$this->assertInstanceOf(expected: ContentSecurityPolicy::class, actual: $policy);
		$imageSources = $this->directive(policy: $policy->buildPolicy(), directive: 'img-src');
		$this->assertContains(needle: 'https://service.pdok.nl', haystack: $imageSources);
		$this->assertContains(needle: 'https://*.tile.openstreetmap.org', haystack: $imageSources);
	}//end testPdokMapLayersMayLoadTheirImages()

	/**
	 * The hosts of the configured map layers are allowed image sources too.
	 *
	 * @return void
	 */
	public function testConfiguredLayerHostsMayLoadTheirImages(): void {
		$hosts = $this->createMock(originalClassName: MapLayerHosts::class);
		$hosts->method('hosts')->willReturn(['https://gis.example.nl', 'https://services.arcgis.com']);

		$imageSources = $this->directive(policy: $this->policyWith(hosts: $hosts)->buildPolicy(), directive: 'img-src');

		$this->assertContains(needle: 'https://gis.example.nl', haystack: $imageSources);
		$this->assertContains(needle: 'https://services.arcgis.com', haystack: $imageSources);
		$this->assertContains(needle: 'https://service.pdok.nl', haystack: $imageSources);
	}//end testConfiguredLayerHostsMayLoadTheirImages()

	/**
	 * Hosts that cannot be read leave the base policy standing.
	 *
	 * @return void
	 */
	public function testUnreadableLayerHostsLeaveTheBasePolicy(): void {
		$imageSources = $this->directive(policy: $this->policyWith(hosts: new \RuntimeException('no config'))->buildPolicy(), directive: 'img-src');

		$this->assertContains(needle: 'https://service.pdok.nl', haystack: $imageSources);
		$this->assertNotContains(needle: 'https://gis.example.nl', haystack: $imageSources);
	}//end testUnreadableLayerHostsLeaveTheBasePolicy()

	/**
	 * The policy registered over a container that answers these layer hosts.
	 *
	 * @param MapLayerHosts|\Throwable $hosts The hosts, or what resolving them throws.
	 *
	 * @return ContentSecurityPolicy
	 */
	private function policyWith(MapLayerHosts|\Throwable $hosts): ContentSecurityPolicy {
		$policy  = null;
		$manager = $this->createMock(originalClassName: IContentSecurityPolicyManager::class);
		$manager->method('addDefaultPolicy')->willReturnCallback(
			static function (ContentSecurityPolicy $added) use (&$policy): void {
				$policy = $added;
			}
		);
		$server = $this->createMock(originalClassName: ContainerInterface::class);
		$server->method('get')->willReturnCallback(
			static function (string $id) use ($manager, $hosts): object {
				if ($id === MapLayerHosts::class) {
					if ($hosts instanceof \Throwable) {
						throw $hosts;
					}

					return $hosts;
				}

				return $manager;
			}
		);

		(new MapCspRegistrar())->register(server: $server);

		$this->assertInstanceOf(expected: ContentSecurityPolicy::class, actual: $policy);
		return $policy;
	}//end policyWith()

	/**
	 * The sources of one directive of a built policy.
	 *
	 * @param string $policy    The policy header value.
	 * @param string $directive The directive, such as img-src.
	 *
	 * @return list<string>
	 */
	private function directive(string $policy, string $directive): array {
		foreach (explode(separator: ';', string: $policy) as $part) {
			$words = explode(separator: ' ', string: trim(string: $part));
			if ($words[0] === $directive) {
				return array_slice(array: $words, offset: 1);
			}
		}

		return [];
	}//end directive()
}//end class
