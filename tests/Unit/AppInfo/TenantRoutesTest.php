<?php

/**
 * Ending a tenancy deletes nothing: no tenant route uses the DELETE verb.
 *
 * `tenantSaas#destroy` hard-deleted the tenant row with no lifecycle gate and
 * no cascade, orphaning every satellite row keyed by `tenantRef` and the audit
 * anchor of every tenant audit entry. Ending a tenancy is a status change.
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
 * @spec openspec/changes/tenant-isolation-names-the-control-that-runs/specs/tenant-isolation/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class TenantRoutesTest extends TestCase {
	/**
	 * No tenant route deletes, and the delete paths behind the old route are gone.
	 *
	 * @return void
	 */
	public function testNoRouteDeletesATenant(): void {
		$routes = require dirname(__DIR__, 3).'/appinfo/routes.php';
		self::assertIsArray($routes, 'appinfo/routes.php must return the route table.');

		$tenantRoutes = [];
		$deleting     = [];
		foreach (($routes['routes'] ?? []) as $route) {
			$name = (string) ($route['name'] ?? '');
			$url  = (string) ($route['url'] ?? '');
			if (str_starts_with(strtolower($name), 'tenant') === false && str_contains($url, '/tenants') === false) {
				continue;
			}

			$tenantRoutes[] = $name;
			if (strtoupper((string) ($route['verb'] ?? 'GET')) === 'DELETE') {
				$deleting[] = $name.' '.$url;
			}
		}

		self::assertNotSame([], $tenantRoutes, 'no tenant route was found, so the assertion below proves nothing');
		self::assertSame([], $deleting, 'a tenant route uses DELETE (REQ-TIS-003)');
		self::assertFileDoesNotExist(__DIR__.'/../../../lib/Controller/TenantSaasController.php', 'the tenant admin store retired with its delete');
	}//end testNoRouteDeletesATenant()
}//end class
