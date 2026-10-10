<?php

/**
 * dossiq routes no tenant API of its own (REQ-TAO-005).
 *
 * Ruben decided on 2026-10-08 (Q5) that `TenantController`'s endpoints move to
 * OpenRegister's organisation endpoints: `current` to `organisation#getActive`,
 * `memberships` to `organisation#index`, `provision` to `organisation#activate`,
 * and `usage` to `organisation#usage` with `organisation#show`.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\AppInfo
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
 * @spec openspec/specs/tenant-organisation-boundary/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class TenantRoutesAreGoneTest extends TestCase {
	/**
	 * No route names the tenant controller, and no route is under /api/tenants.
	 *
	 * @return void
	 */
	public function testNoRouteNamesTheTenantController(): void {
		$routes = require dirname(__DIR__, 3).'/appinfo/routes.php';
		self::assertIsArray($routes, 'appinfo/routes.php must return the route table.');

		$all       = $routes['routes'] ?? [];
		$offending = [];
		foreach ($all as $route) {
			$name = (string) ($route['name'] ?? '');
			$url  = (string) ($route['url'] ?? '');
			if (str_starts_with($name, 'tenant#') === true || str_starts_with($url, '/api/tenants') === true) {
				$offending[] = $name.' '.$url;
			}
		}

		self::assertNotSame([], $all, 'an empty route table would make this vacuous');
		self::assertSame([], $offending, 'a route still names the tenant controller (REQ-TAO-005)');
	}//end testNoRouteNamesTheTenantController()
}//end class
