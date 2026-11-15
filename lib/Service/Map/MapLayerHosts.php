<?php

/**
 * Dossiq map layer hosts
 *
 * Remembers the hosts of the map layers an administrator configured, so the
 * page's image policy lets their tiles load. Leaflet draws every layer tile as
 * a plain image, and a host the policy does not name renders a blank layer
 * without an error.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Map
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
 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/gis-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Map;

use OCA\Dossiq\Kernel\AppIdentity;
use OCP\IAppConfig;

/**
 * The https origins of the configured map layers.
 *
 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/gis-integration/spec.md
 */
class MapLayerHosts {

	/**
	 * The app config key the origins are kept under, as a JSON list.
	 *
	 * @var string
	 */
	public const KEY = 'map_layer_hosts';

	/**
	 * The most origins kept; a layer past it is drawn only from an origin already named.
	 *
	 * @var int
	 */
	public const MAX_HOSTS = 50;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Where the origins are kept.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The origins remembered so far.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/gis-integration/spec.md#requirement-the-map-can-show-layers-from-an-arcgis-service-req-crs-41
	 */
	public function hosts(): array {
		$stored = json_decode($this->appConfig->getValueString(AppIdentity::APP_ID, self::KEY, '[]'), true);
		if (is_array($stored) === false) {
			return [];
		}

		$hosts = [];
		foreach ($stored as $host) {
			// Read back through the same check that wrote it: a value set by
			// hand in the config table is held to the same shape.
			$origin = self::originOf(url: (string)$host);
			if ($origin !== null && in_array($origin, $hosts, true) === false) {
				$hosts[] = $origin;
			}
		}

		return $hosts;
	}//end hosts()

	/**
	 * Remember the origin of a layer's service URL.
	 *
	 * @param string $url The layer's service URL.
	 *
	 * @return bool Whether a new origin was remembered.
	 *
	 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/gis-integration/spec.md#requirement-the-map-can-show-layers-from-an-arcgis-service-req-crs-41
	 */
	public function remember(string $url): bool {
		$origin = self::originOf(url: $url);
		if ($origin === null) {
			return false;
		}

		$hosts = $this->hosts();
		if (in_array($origin, $hosts, true) === true || count($hosts) >= self::MAX_HOSTS) {
			return false;
		}

		$hosts[] = $origin;
		$this->appConfig->setValueString(AppIdentity::APP_ID, self::KEY, (string)json_encode($hosts));

		return true;
	}//end remember()

	/**
	 * The https origin of a URL, or null for anything that is not a plain https host.
	 *
	 * Only https is named: a map on an https page cannot draw plain http
	 * images anyway, and a wildcard, a path or credentials never reach the
	 * policy.
	 *
	 * @param string $url The URL.
	 *
	 * @return string|null The origin, such as `https://services.arcgis.com`.
	 *
	 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/gis-integration/spec.md#requirement-the-map-can-show-layers-from-an-arcgis-service-req-crs-41
	 */
	public static function originOf(string $url): ?string {
		$parts = parse_url(trim($url));
		if (is_array($parts) === false || strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
			return null;
		}

		if (isset($parts['user']) === true || isset($parts['pass']) === true) {
			return null;
		}

		$host = strtolower((string)($parts['host'] ?? ''));
		if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $host) !== 1) {
			return null;
		}

		$origin = 'https://' . $host;
		if (isset($parts['port']) === true && (int)$parts['port'] !== 443) {
			$origin .= ':' . (int)$parts['port'];
		}

		return $origin;
	}//end originOf()
}//end class
