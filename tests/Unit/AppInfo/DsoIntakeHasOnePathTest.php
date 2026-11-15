<?php

/**
 * A DSO verzoek reaches dossiq on one path only.
 *
 * Integriq receives the STAM verzoek, maps its activities to case types and
 * writes `mappedCaseTypes` on its `dso_verzoek`. Dossiq's
 * `VergunningaanvraagCreatedListener` reads that record and `DsoCaseService`
 * makes the one case, deduplicated on `permitApplicationRef`.
 *
 * Beside it stood `POST /api/vth/dso/intake` (`DSOIntakeController` into
 * `DsoIntakeService`): a second public STAM receiver. Its payload carries
 * activity names and no activity reference, so it could not resolve a case
 * type, wrote a case without one (which OpenRegister refuses), let every
 * request through when no secret was set, and had no deduplication against
 * the listener. Nothing in dossiq or integriq calls it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\AppInfo
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link https://conduction.nl
 *
 * @spec openspec/specs/vth-dso-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;

/**
 * The listener path is the only one that turns a DSO verzoek into a case.
 */
class DsoIntakeHasOnePathTest extends TestCase {

	/**
	 * The app root.
	 *
	 * @return string
	 */
	private function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * No route receives a DSO verzoek in dossiq.
	 *
	 * @return void
	 */
	public function testNoRouteReceivesADsoVerzoek(): void {
		$routes = require $this->root() . '/appinfo/routes.php';
		self::assertIsArray($routes, 'appinfo/routes.php must return the route table.');

		$offenders = [];
		foreach (($routes['routes'] ?? []) as $route) {
			$name = (string)($route['name'] ?? '');
			$url = (string)($route['url'] ?? '');
			if (stripos($name, 'dsointake') !== false || str_contains($url, '/dso/intake') === true) {
				$offenders[] = $name . ' ' . $url;
			}
		}

		self::assertSame([], $offenders, 'a DSO verzoek reaches dossiq through integriq and the listener only');
	}//end testNoRouteReceivesADsoVerzoek()

	/**
	 * The second intake service and its controller are gone.
	 *
	 * @return void
	 */
	public function testTheSecondIntakePathIsGone(): void {
		self::assertFileDoesNotExist($this->root() . '/lib/Service/DsoIntakeService.php');
		self::assertFileDoesNotExist($this->root() . '/lib/Controller/DSOIntakeController.php');
	}//end testTheSecondIntakePathIsGone()
}//end class
