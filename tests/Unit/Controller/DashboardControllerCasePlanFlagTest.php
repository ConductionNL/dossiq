<?php

/**
 * The case-plan read preference, as the SPA page serves it.
 *
 * Two things are pinned. The default, because the bridge is only a bridge
 * while rows win, and a flag that defaulted to `no` would leave every drained
 * case reading an engine that no longer holds its plan. And the SPELLING,
 * because the flag has two halves in two languages: a key written one way in
 * PHP and another way in JavaScript is a flag that is on in one place and off
 * in the other, and `loadState` answers its own default without a word when
 * the key it is asked for was never provided.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
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
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\DashboardController;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @covers \OCA\Dossiq\Controller\DashboardController
 */
final class DashboardControllerCasePlanFlagTest extends TestCase {

	/**
	 * Build a controller whose app config answers one value for the flag.
	 *
	 * @param string $stored What `getValueString()` answers, defaults included.
	 *
	 * @return DashboardController The controller.
	 */
	private function controller(string $stored): DashboardController {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($stored);

		return new DashboardController(
			$this->createMock(IRequest::class),
			$this->createMock(IInitialState::class),
			$appConfig,
		);
	}//end controller()

	/**
	 * Ask the controller for its answer.
	 *
	 * @param string $stored The stored config value.
	 *
	 * @return boolean The answer.
	 */
	private function prefers(string $stored): bool {
		$method = new ReflectionMethod(DashboardController::class, 'prefersOpenRegisterCasePlan');
		$method->setAccessible(true);

		return (bool)$method->invoke($this->controller(stored: $stored));
	}//end prefers()

	/**
	 * An instance that has never set the flag prefers OpenRegister.
	 *
	 * 🔴 IT ASSERTS THE DEFAULT THE CONTROLLER ASKS FOR, not the value a mock
	 * hands back. A mock of `getValueString()` answers whatever it was told to
	 * regardless of the default argument, so a test that only stubs a return
	 * value cannot see the default change: flipping `'yes'` to `'no'` in the
	 * controller left the earlier version of this test green. The default IS
	 * the behaviour here, because an instance that has never set the flag is
	 * every instance.
	 *
	 * @return void
	 */
	public function testTheDefaultPrefersOpenRegister(): void {
		$asked = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use (&$asked): string {
				$asked = [$app, $key, $default];

				return $default;
			}
		);

		$controller = new DashboardController(
			$this->createMock(IRequest::class),
			$this->createMock(IInitialState::class),
			$appConfig,
		);

		$method = new ReflectionMethod(DashboardController::class, 'prefersOpenRegisterCasePlan');
		$method->setAccessible(true);

		$this->assertTrue((bool)$method->invoke($controller), 'An unset flag must prefer OpenRegister.');
		$this->assertSame(
			['dossiq', DashboardController::PREFER_OPENREGISTER_CASE_PLAN, 'yes'],
			$asked,
			'The controller must ask app config for its own key with `yes` as the default.',
		);
	}//end testTheDefaultPrefersOpenRegister()

	/**
	 * Every spelling of off turns it off, because an operator reaching for the
	 * R1 rollback under pressure writes whichever one comes to mind.
	 *
	 * @return void
	 */
	public function testEverySpellingOfOffTurnsItOff(): void {
		foreach (['no', 'NO', ' no ', 'false', '0', 'off'] as $value) {
			$this->assertFalse($this->prefers(stored: $value), sprintf("'%s' should turn the preference off.", $value));
		}
	}//end testEverySpellingOfOffTurnsItOff()

	/**
	 * Anything else leaves it on: the rollback is a deliberate act, and a typo
	 * must not quietly put a drained case back on the retiring engine.
	 *
	 * @return void
	 */
	public function testAnUnrecognisedValueLeavesItOn(): void {
		foreach (['yes', 'true', '1', 'nope', ''] as $value) {
			$this->assertTrue($this->prefers(stored: $value), sprintf("'%s' should leave the preference on.", $value));
		}
	}//end testAnUnrecognisedValueLeavesItOn()

	/**
	 * The PHP half and the JavaScript half name the same key.
	 *
	 * @return void
	 */
	public function testBothHalvesOfTheFlagSpellTheKeyTheSameWay(): void {
		$source = file_get_contents(__DIR__ . '/../../../src/services/casePlanSource.js');
		$this->assertIsString($source);

		$matched = preg_match("/PREFER_OPENREGISTER_KEY = '([^']+)'/", $source, $matches);
		$this->assertSame(1, $matched, 'casePlanSource.js no longer declares PREFER_OPENREGISTER_KEY.');
		$this->assertSame(
			DashboardController::PREFER_OPENREGISTER_CASE_PLAN,
			$matches[1],
			'The PHP and JavaScript halves of the case-plan flag name different keys.',
		);
	}//end testBothHalvesOfTheFlagSpellTheKeyTheSameWay()
}//end class
