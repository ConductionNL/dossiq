<?php

/**
 * Procest map Content-Security-Policy registrar (boot-time).
 *
 * Allowlists the map hosts procest's location widget needs. Split out of
 * Application so the tile-host list sits in one small, obviously-reviewable
 * class next to the reason it exists.
 *
 * @category AppInfo
 * @package  OCA\Procest\AppInfo\Registrar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\AppInfo\Registrar;

use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\Security\IContentSecurityPolicyManager;

/**
 * Allowlists the base-map tile hosts and the geocoder.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */
class MapCspRegistrar {
	/**
	 * Allowlist the map hosts: base-map tiles (img-src) and the address-search
	 * geocoder (connect-src).
	 *
	 * Leaflet loads tiles as plain `<img>` elements, so Nextcloud's default
	 * Content-Security-Policy (`img-src 'self' data: blob:`) blocks every
	 * third-party tile server. The tile hosts here mirror `mapConfig.basemaps` in
	 * `src/manifest.json` and the base maps offered by the location widget — keep
	 * them in step, or a base map the user can pick from the switcher will
	 * silently render blank (CSP blocks the request outright, so nothing even
	 * shows up in the network log — look in the console).
	 *
	 * Procest declares these itself rather than relying on another app: the OSM
	 * host happened to be allowed only because the (optional) Nextcloud `maps` app
	 * pushes a default policy, so the map broke on any instance without it.
	 *
	 * NC merges policies additively via `addDefaultPolicy()` and never narrows, so
	 * this is idempotent and cannot loosen anything another app already set.
	 *
	 * @param mixed $server Server container (passed in from boot()).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	public function register($server): void {
		try {
			$cspManager = $server->get(IContentSecurityPolicyManager::class);
			$policy = new ContentSecurityPolicy();
			// Base-map tiles.
			$policy->addAllowedImageDomain('https://*.tile.openstreetmap.org');
			$policy->addAllowedImageDomain('https://*.tile.openstreetmap.fr');
			$policy->addAllowedImageDomain('https://*.tile.opentopomap.org');
			// Address search (forward geocoding) in the location widget.
			$policy->addAllowedConnectDomain('https://nominatim.openstreetmap.org');
			$cspManager->addDefaultPolicy($policy);
		} catch (\Throwable $e) {
			// CSP manager unavailable. Degrade to "no base map" rather than
			// failing the boot — every other page keeps working.
			unset($e);
		}
	}//end register()
}//end class
