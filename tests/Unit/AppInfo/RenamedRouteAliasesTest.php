<?php

/**
 * Renamed route alias tests.
 *
 * Every procedure-named URL the UI called moved to a generic address. The old
 * address stays as an alias, so a bookmarked call, an external report reader
 * or a payment provider configured with it keeps working. Each alias routes to
 * its own `<method>LegacyPath()` delegate, because AppHost Routes::standard()
 * rejects a duplicate route name and ignores `postfix`.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ui-facing-names-are-generic/specs/ui-facing-names/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @coversNothing
 */
class RenamedRouteAliasesTest extends TestCase {

	/**
	 * Each renamed route: [controller#method, verb, new URL, old URL, call arguments].
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: array<string, mixed>}>
	 */
	public static function renamedRoutes(): array {
		$id = ['id' => 'abc-123'];
		return [
			'checklist index'        => ['checklistTemplate#index', 'GET', '/api/checklists', '/api/vth/checklists', []],
			'checklist create'       => ['checklistTemplate#create', 'POST', '/api/checklists', '/api/vth/checklists', []],
			'checklist update'       => ['checklistTemplate#update', 'PUT', '/api/checklists/{id}', '/api/vth/checklists/{id}', $id],
			'checklist destroy'      => ['checklistTemplate#destroy', 'DELETE', '/api/checklists/{id}', '/api/vth/checklists/{id}', $id],
			'checklist result submit' => ['taskChecklist#submit', 'POST', '/api/cases/{id}/checklist-results', '/api/vth/cases/{id}/inspection-result', $id],
			'checklist result runs'  => ['taskChecklist#runs', 'GET', '/api/cases/{id}/checklist-results', '/api/vth/cases/{id}/inspection-results', $id],
			'deadline kpi'           => ['deadlineReporting#dashboard', 'GET', '/api/deadlines/dashboard/kpi', '/api/termijn/dashboard/kpi', []],
			'deadline quarterly'     => ['deadlineReporting#quarterlyReport', 'GET', '/api/deadlines/reports/quarterly', '/api/termijn/reports/kwartaal', ['period' => '2026-Q3', 'department' => 'vth']],
			'deadline annual'        => ['deadlineReporting#annualStatement', 'GET', '/api/deadlines/reports/annual', '/api/termijn/reports/jaarrekening', ['year' => 2026]],
			'deadline first response' => ['deadlineReporting#firstResponseReport', 'GET', '/api/deadlines/reports/first-response', '/api/termijn/reports/eerste-reactie', ['caseType' => 'ct', 'organisation' => 'org']],
			'deadline workload age'  => ['caseTerms#workloadAge', 'GET', '/api/deadlines/reports/open-workload-age', '/api/termijn/reports/open-workload-age', []],
			'deadline definitions'   => ['termijnDefinitie#index', 'GET', '/api/deadlines/definitions', '/api/termijn/definities', []],
			'deadline calendar'      => ['termijnDefinitie#calendar', 'GET', '/api/deadlines/calendar', '/api/termijn/calendar', []],
			'deadline definition create' => ['termijnDefinitie#create', 'POST', '/api/deadlines/definitions', '/api/termijn/definities', []],
			'deadline definition update' => ['termijnDefinitie#update', 'PATCH', '/api/deadlines/definitions/{id}', '/api/termijn/definities/{id}', $id],
			'deadline instance create' => ['termijn#create', 'POST', '/api/deadlines/instances', '/api/termijn/instances', []],
			'deadline instance show' => ['termijn#show', 'GET', '/api/deadlines/instances/{id}', '/api/termijn/instances/{id}', $id],
			'deadline pause'         => ['termijn#pauze', 'POST', '/api/deadlines/instances/{id}/pause', '/api/termijn/instances/{id}/pauze', $id],
			'deadline resume'        => ['termijn#hervat', 'POST', '/api/deadlines/instances/{id}/resume', '/api/termijn/instances/{id}/hervat', $id],
			'deadline extend'        => ['termijn#verleng', 'POST', '/api/deadlines/instances/{id}/extend', '/api/termijn/instances/{id}/verleng', $id],
			'deadline complete'      => ['termijn#voltooi', 'POST', '/api/deadlines/instances/{id}/complete', '/api/termijn/instances/{id}/voltooi', $id],
			'payment callback'       => ['payoutCallback#callback', 'POST', '/api/payments/callback', '/api/dossiq/openconnector/dwangsom-payment-callback', []],
			'accruing amount show'   => ['accruingAmount#show', 'GET', '/api/accruing-amounts/{id}', '/api/termijn/dwangsom/{id}', $id],
			'accruing amount settle' => ['accruingAmount#settle', 'POST', '/api/accruing-amounts/{id}/settle', '/api/termijn/dwangsom/{id}/beschikking', $id],
			'accruing amount objection' => ['accruingAmount#objection', 'POST', '/api/accruing-amounts/{id}/objection', '/api/termijn/dwangsom/{id}/bezwaar', $id],
			'accruing amount reconsider' => ['accruingAmount#reconsider', 'POST', '/api/accruing-amounts/{id}/objection/reconsider', '/api/termijn/dwangsom/{id}/bezwaar/heroverweging', $id],
			'accruing amount payout' => ['payout#prepare', 'POST', '/api/accruing-amounts/{id}/payout', '/api/termijn/dwangsom/{id}/uitbetaling', $id],
			'notice of default register' => ['accrualNotice#register', 'POST', '/api/notices-of-default', '/api/termijn/ingebrekestellingen', []],
			'notice of default show' => ['accrualNotice#show', 'GET', '/api/notices-of-default/{id}', '/api/termijn/ingebrekestellingen/{id}', $id],
			'document review throughput' => ['wooReport#throughput', 'GET', '/api/document-review/reports/throughput', '/api/woo/reports/throughput', []],
		];
	}//end renamedRoutes()

	/**
	 * The route table, keyed by `VERB url`.
	 *
	 * @return array<string, string> Route name by verb and URL.
	 */
	private function routesByAddress(): array {
		$routes = require dirname(__DIR__, 3) . '/appinfo/routes.php';
		$byAddress = [];
		foreach (($routes['routes'] ?? []) as $route) {
			$byAddress[($route['verb'] ?? 'GET') . ' ' . $route['url']] = (string)$route['name'];
		}

		self::assertNotSame([], $byAddress, 'An empty route table would make every assertion below vacuous.');
		return $byAddress;
	}//end routesByAddress()

	/**
	 * The controller class a route name resolves to.
	 *
	 * @param string $routeName The `controller#method` route name.
	 *
	 * @return array{0: class-string, 1: string} The class and the method.
	 */
	private function target(string $routeName): array {
		[$controller, $method] = explode('#', $routeName);
		return ['OCA\\Dossiq\\Controller\\' . ucfirst($controller) . 'Controller', $method];
	}//end target()

	/**
	 * The new URL routes to the method, the old URL to its delegate.
	 *
	 * @param string $route  The new route name.
	 * @param string $verb   The verb.
	 * @param string $newUrl The generic URL.
	 * @param string $oldUrl The procedure-named URL.
	 *
	 * @return void
	 */
	#[DataProvider('renamedRoutes')]
	public function testBothUrlsAreRouted(string $route, string $verb, string $newUrl, string $oldUrl): void {
		$routes = $this->routesByAddress();

		self::assertSame($route, ($routes[$verb . ' ' . $newUrl] ?? null), $verb . ' ' . $newUrl . ' must route to ' . $route);
		self::assertSame($route . 'LegacyPath', ($routes[$verb . ' ' . $oldUrl] ?? null), $verb . ' ' . $oldUrl . ' must stay routed');
	}//end testBothUrlsAreRouted()

	/**
	 * The delegate carries the same auth posture as the method it calls, so
	 * the old URL is neither looser nor stricter than the new one.
	 *
	 * @param string $route The new route name.
	 *
	 * @return void
	 */
	#[DataProvider('renamedRoutes')]
	public function testTheAliasHasTheSameAuthPosture(string $route): void {
		[$class, $method] = $this->target(routeName: $route);
		$original = new ReflectionMethod($class, $method);
		$alias = new ReflectionMethod($class, $method . 'LegacyPath');

		$attributes = static fn (ReflectionMethod $m): array => array_map(
			static fn ($a): string => $a->getName() . json_encode($a->getArguments()),
			$m->getAttributes()
		);
		$annotations = static function (ReflectionMethod $m): array {
			preg_match_all('/@(NoAdminRequired|NoCSRFRequired|PublicPage|AuthorizedAdminSetting)\b/', (string)$m->getDocComment(), $found);
			return $found[1];
		};

		self::assertSame($attributes($original), $attributes($alias));
		self::assertSame($annotations($original), $annotations($alias));
		self::assertEquals($original->getReturnType(), $alias->getReturnType());
	}//end testTheAliasHasTheSameAuthPosture()

	/**
	 * Calling the delegate calls the method with the same arguments and
	 * returns what it returns.
	 *
	 * @param string               $route  The new route name.
	 * @param string               $verb   The verb.
	 * @param string               $newUrl The generic URL.
	 * @param string               $oldUrl The procedure-named URL.
	 * @param array<string, mixed> $args   The arguments the router binds.
	 *
	 * @return void
	 */
	#[DataProvider('renamedRoutes')]
	public function testTheAliasCallsTheSameMethod(string $route, string $verb, string $newUrl, string $oldUrl, array $args): void {
		[$class, $method] = $this->target(routeName: $route);
		$returnType = (string)(new ReflectionMethod($class, $method))->getReturnType();
		$responseClass = explode('|', $returnType)[0];
		$response = $this->createMock($responseClass);

		$controller = $this->getMockBuilder($class)
			->disableOriginalConstructor()
			->onlyMethods([$method])
			->getMock();
		$controller->expects($this->once())
			->method($method)
			->with(...array_values($args))
			->willReturn($response);

		self::assertSame($response, $controller->{$method . 'LegacyPath'}(...$args));
	}//end testTheAliasCallsTheSameMethod()
}//end class
