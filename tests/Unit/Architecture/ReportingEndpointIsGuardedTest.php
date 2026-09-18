<?php

/**
 * Structural guard: a figure about every case is not for everyone.
 *
 * 🔑 THE LIST IS DERIVED FROM `appinfo/routes.php`, NOT WRITTEN HERE. That is
 * the whole point and it is the lesson two changes ago: a rate-limit test that
 * named the three endpoints its proposal named would have stayed green through
 * the two it had never heard of, and only the derived sweep found them. So this
 * reads the route table, picks the reporting-shaped URLs out of 517 routes, and
 * asks each one's controller whether it gates.
 *
 * WHAT COUNTS AS REPORTING, stated so it can be argued with. A reporting
 * endpoint answers an AGGREGATE over cases the caller was never granted: a
 * median dwell time, a quarterly compliance figure, an annual dwangsom
 * statement. OpenRegister's per-object refusal never gets a chance to speak
 * about those, because no object is being read. An endpoint that answers about
 * OBJECTS is not on this list and must not be: a group gate in front of one
 * would take a handler's own work away from them.
 *
 * The URL shapes below are the mechanical stand-in for that definition. They
 * will occasionally catch something that is not a report, which is why the
 * allowlist exists and why every entry on it carries the reason.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Architecture
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
 * @spec openspec/specs/security-hardening/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Every fleet-wide reporting endpoint gates, or says why it need not.
 *
 * @coversNothing
 */
class ReportingEndpointIsGuardedTest extends TestCase {

	/**
	 * The repository root.
	 */
	private const ROOT = __DIR__ . '/../../..';

	/**
	 * URL shapes that make a route a candidate.
	 */
	private const REPORTING_URLS = '#/reports?/|/dashboard|/doorlooptijd|/metrics|/statistics|/kpis?($|/)#i';

	/**
	 * Evidence that a controller decides more than "is there a session".
	 *
	 * Any one of these is enough. The sweep is not trying to judge whether the
	 * gate is the RIGHT one, which no grep can do: it is refusing the case
	 * where there is visibly none, which is the state five of these controllers
	 * were in.
	 */
	private const GATE_MARKERS = [
		'ALLOWED_GROUPS',
		'isInGroup',
		'isAdmin',
		'mayRead',
		'maySee',
		'Guard',
	];

	/**
	 * Reporting-shaped routes that legitimately answer any authenticated caller.
	 *
	 * Each reason is the controller's own words or a measured fact, not an
	 * opinion formed here.
	 *
	 * @var array<string, string>
	 */
	private const ALLOWLIST = [
		'iv3Taakveld#taakvelden' => 'The controller says it, and it is right: the IV3 taakveld list is a '
			. 'published CBS classification, not report data. It is the same list on the CBS website.',
		'dso#dashboard' => 'Answers a LIST OF CASES, not an aggregate. Every row is an object OpenRegister '
			. 'refuses to a caller who may not read it, so a group gate here would take a handler their own '
			. 'work away and change nothing about what leaks.',
		'kpi#index' => 'Owned by `widget-roles-declared` (dossiq#2939), which gates it per widget through '
			. 'DashboardWidgetScope rather than per endpoint. Gating it here as well would be a second '
			. 'evaluator of one question. Remove this entry when that change lands and the marker is there.',
		'metrics#index' => 'There is NO MetricsController in lib/Controller. Measured 2026-09-18: the route '
			. 'resolves to a class that does not exist, so it answers a 500 rather than a figure and '
			. 'discloses nothing. Reported rather than deleted here, because a route another app\'s AppHost '
			. 'may register is not this change\'s to remove.',
		'complaintAnalytics#kpi' => 'Already gated: the controller carries its own group check. The entry '
			. 'exists so the sweep records that it was looked at rather than skipped.',
	];

	/**
	 * Every reporting-shaped route in the table.
	 *
	 * @return array<int, array{name: string, url: string, controller: string}> The routes.
	 */
	private function reportingRoutes(): array {
		$routes = (string)file_get_contents(self::ROOT . '/appinfo/routes.php');

		$matched = [];
		preg_match_all(
			"#\\['name'\\s*=>\\s*'([^']+)'\\s*,\\s*'url'\\s*=>\\s*'([^']+)'#",
			$routes,
			$matched,
			PREG_SET_ORDER
		);

		$this->assertGreaterThan(
			300,
			count($matched),
			'the route table must parse, or this test sweeps an empty list and passes on nothing'
		);

		$found = [];
		foreach ($matched as [, $name, $url]) {
			if (preg_match(self::REPORTING_URLS, $url) !== 1) {
				continue;
			}

			$controller = ucfirst((string)strstr($name, '#', true)) . 'Controller';
			$found[] = ['name' => $name, 'url' => $url, 'controller' => $controller];
		}

		return $found;
	}//end reportingRoutes()

	/**
	 * The sweep finds the endpoints it is about.
	 *
	 * Without this the two tests below pass on an empty list the day somebody
	 * renames a route, which is the shape of a test that cannot fail.
	 *
	 * @return void
	 */
	public function testTheSweepFindsTheReportingEndpoints(): void {
		$names = array_column($this->reportingRoutes(), 'name');

		$this->assertGreaterThanOrEqual(8, count($names), 'the sweep found almost nothing');
		foreach (['deadlineReporting#annualStatement', 'doorlooptijd#metrics', 'processMining#report'] as $known) {
			$this->assertContains($known, $names, $known . ' must be in the sweep');
		}
	}//end testTheSweepFindsTheReportingEndpoints()

	/**
	 * Every reporting endpoint gates, or is allowlisted with a reason.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/security-hardening/spec.md
	 */
	public function testEveryReportingEndpointGatesOrSaysWhyNot(): void {
		$ungated = [];

		foreach ($this->reportingRoutes() as $route) {
			if (array_key_exists($route['name'], self::ALLOWLIST) === true) {
				continue;
			}

			$path = self::ROOT . '/lib/Controller/' . $route['controller'] . '.php';
			$source = (file_exists($path) === true ? (string)file_get_contents($path) : '');

			$gated = false;
			foreach (self::GATE_MARKERS as $marker) {
				if (str_contains($source, $marker) === true) {
					$gated = true;
					break;
				}
			}

			if ($gated === false) {
				$ungated[] = sprintf('%s (%s)', $route['name'], $route['url']);
			}
		}

		$this->assertSame(
			[],
			$ungated,
			"These endpoints answer a figure about every case to anyone with a session. Gate them on "
			. "ReportingAudience, or allowlist them with the reason they are for everyone:\n  "
			. implode("\n  ", $ungated)
		);
	}//end testEveryReportingEndpointGatesOrSaysWhyNot()

	/**
	 * Every allowlist entry names a route that exists and carries a reason.
	 *
	 * @return void
	 */
	public function testTheAllowlistIsAboutRoutesThatExistAndSaysWhy(): void {
		$names = array_column($this->reportingRoutes(), 'name');

		foreach (self::ALLOWLIST as $name => $reason) {
			$this->assertContains($name, $names, $name . ' is allowlisted and is not a reporting route');
			$this->assertGreaterThan(
				60,
				strlen(trim($reason)),
				$name . ' is allowlisted with a reason that says nothing'
			);
		}
	}//end testTheAllowlistIsAboutRoutesThatExistAndSaysWhy()
}//end class
