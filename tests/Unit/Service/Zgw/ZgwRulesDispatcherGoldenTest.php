<?php

/**
 * Characterisation tests for ZgwRulesDispatcher::dispatch: which rules method
 * each API, resource and action reaches, with which arguments, and the
 * pass-through for everything else.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Service\Zgw\ZgwRulesDispatcher;
use OCA\Dossiq\Service\Zgw\ZgwZrcZaakinformatieobjectRules;
use OCA\Dossiq\Service\Zgw\ZgwZtcResultaattypeRules;
use OCA\Dossiq\Service\ZgwBrcRulesService;
use OCA\Dossiq\Service\ZgwDrcRulesService;
use OCA\Dossiq\Service\ZgwZrcRulesService;
use OCA\Dossiq\Service\ZgwZtcRulesService;
use PHPUnit\Framework\TestCase;

class ZgwRulesDispatcherGoldenTest extends TestCase {

	/**
	 * The rules methods reached, with their arguments.
	 *
	 * @var array<int, array>
	 */
	private array $calls = [];

	/**
	 * A mock of a rules class that records every listed method it is asked.
	 *
	 * @param string        $class   The class.
	 * @param array<string> $methods The methods.
	 *
	 * @return object The mock.
	 */
	private function recording(string $class, array $methods): object {
		$mock = $this->getMockBuilder($class)->disableOriginalConstructor()->onlyMethods($methods)->getMock();
		foreach ($methods as $method) {
			$mock->method($method)->willReturnCallback(
				function (...$args) use ($method): array {
					$this->calls[] = [$method, $args];

					return ['from' => $method];
				}
			);
		}

		return $mock;
	}//end recording()

	private function dispatcher(): ZgwRulesDispatcher {
		return new ZgwRulesDispatcher(
			$this->recording(ZgwZrcRulesService::class, ['rulesZakenCreate', 'rulesZakenUpdate', 'rulesZakenPatch', 'rulesStatussenCreate', 'rulesResultatenCreate', 'rulesRollenCreate', 'rulesZaakeigenschappenCreate']),
			$this->recording(ZgwZtcRulesService::class, ['rulesZaaktypenCreate', 'rulesBesluittypenCreate', 'rulesZaaktypeinformatieobjecttypenCreate']),
			$this->recording(ZgwDrcRulesService::class, ['rulesEnkelvoudiginformatieobjectenCreate', 'rulesEnkelvoudiginformatieobjectenUpdate', 'rulesEnkelvoudiginformatieobjectenPatch', 'rulesEnkelvoudiginformatieobjectenDestroy', 'rulesObjectinformatieobjectenCreate']),
			$this->recording(ZgwBrcRulesService::class, ['rulesBesluitenCreate', 'rulesBesluitenUpdate', 'rulesBesluitenPatch', 'rulesBesluitinformatieobjectenCreate']),
			$this->recording(ZgwZrcZaakinformatieobjectRules::class, ['rulesZaakinformatieobjectenCreate', 'rulesZaakinformatieobjectenUpdate', 'rulesZaakinformatieobjectenPatch']),
			$this->recording(ZgwZtcResultaattypeRules::class, ['rulesResultaattypenCreate']),
		);
	}//end dispatcher()

	public function testEachRouteReachesItsRules(): void {
		$body = ['b' => 1];
		$existing = ['e' => 2];
		$routes = [
			['zaken', 'zaken', 'create', 'rulesZakenCreate', [$body]],
			['zaken', 'zaken', 'update', 'rulesZakenUpdate', [$body, $existing]],
			['zaken', 'zaken', 'patch', 'rulesZakenPatch', [$body, $existing]],
			['zaken', 'statussen', 'create', 'rulesStatussenCreate', [$body]],
			['zaken', 'resultaten', 'create', 'rulesResultatenCreate', [$body]],
			['zaken', 'rollen', 'create', 'rulesRollenCreate', [$body]],
			['zaken', 'zaakeigenschappen', 'create', 'rulesZaakeigenschappenCreate', [$body]],
			['zaken', 'zaakinformatieobjecten', 'create', 'rulesZaakinformatieobjectenCreate', [$body]],
			['zaken', 'zaakinformatieobjecten', 'update', 'rulesZaakinformatieobjectenUpdate', [$body, $existing]],
			['zaken', 'zaakinformatieobjecten', 'patch', 'rulesZaakinformatieobjectenPatch', [$body, $existing]],
			['catalogi', 'zaaktypen', 'create', 'rulesZaaktypenCreate', [$body]],
			['catalogi', 'besluittypen', 'create', 'rulesBesluittypenCreate', [$body]],
			['catalogi', 'zaaktype-informatieobjecttypen', 'create', 'rulesZaaktypeinformatieobjecttypenCreate', [$body]],
			['catalogi', 'resultaattypen', 'create', 'rulesResultaattypenCreate', [$body]],
			['documenten', 'enkelvoudiginformatieobjecten', 'create', 'rulesEnkelvoudiginformatieobjectenCreate', [$body]],
			['documenten', 'enkelvoudiginformatieobjecten', 'update', 'rulesEnkelvoudiginformatieobjectenUpdate', [$body, $existing]],
			['documenten', 'enkelvoudiginformatieobjecten', 'patch', 'rulesEnkelvoudiginformatieobjectenPatch', [$body, $existing]],
			['documenten', 'enkelvoudiginformatieobjecten', 'destroy', 'rulesEnkelvoudiginformatieobjectenDestroy', [$body, $existing]],
			['documenten', 'objectinformatieobjecten', 'create', 'rulesObjectinformatieobjectenCreate', [$body]],
			['besluiten', 'besluiten', 'create', 'rulesBesluitenCreate', [$body]],
			['besluiten', 'besluiten', 'update', 'rulesBesluitenUpdate', [$body, $existing]],
			['besluiten', 'besluiten', 'patch', 'rulesBesluitenPatch', [$body, $existing]],
			['besluiten', 'besluitinformatieobjecten', 'create', 'rulesBesluitinformatieobjectenCreate', [$body]],
		];
		foreach ($routes as [$api, $resource, $action, $method, $args]) {
			$this->calls = [];
			$answer = $this->dispatcher()->dispatch($api, $resource, $action, $body, $existing);
			self::assertSame(['from' => $method], $answer, $api . ' ' . $resource . ' ' . $action);
			self::assertSame([[$method, $args]], $this->calls);
		}
	}//end testEachRouteReachesItsRules()

	public function testEverythingElsePassesThrough(): void {
		$body = ['b' => 1];
		$passes = [
			['zaken', 'zaken', 'destroy'],
			['zaken', 'statussen', 'update'],
			['zaken', 'zaakinformatieobjecten', 'destroy'],
			['catalogi', 'zaaktypen', 'update'],
			['catalogi', 'roltypen', 'create'],
			['documenten', 'objectinformatieobjecten', 'destroy'],
			['besluiten', 'besluiten', 'destroy'],
			['besluiten', 'besluitinformatieobjecten', 'update'],
			['autorisaties', 'applicaties', 'create'],
			['', 'zaken', 'create'],
		];
		foreach ($passes as [$api, $resource, $action]) {
			$this->calls = [];
			self::assertSame(
				['valid' => true, 'status' => 200, 'detail' => '', 'enrichedBody' => $body],
				$this->dispatcher()->dispatch($api, $resource, $action, $body, null)
			);
			self::assertSame([], $this->calls, $api . ' ' . $resource . ' ' . $action);
		}
	}//end testEverythingElsePassesThrough()
}//end class
