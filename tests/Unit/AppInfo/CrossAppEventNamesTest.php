<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\AppInfo
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use OCA\Dossiq\AppInfo\Registrar\WorkflowListenerRegistrar;
use OCA\Dossiq\Service\ContractDecisionDelegationService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * A cross-app event FQN must name EVERY namespace the other app has used.
 *
 * This is the check that was missing. The decision app renamed its namespace
 * from `OCA\Decidesk` to `OCA\Decidiq` with no compatibility alias, and dossiq
 * named only the old spelling in two places. The consequences were different
 * and both bad:
 *
 *  - the listener registration is guarded by `class_exists`, so it went false
 *    and simply stopped registering. Every concluded decision quietly stopped
 *    materialising a ZGW Besluit. A guard that goes false looks exactly like
 *    the optional app not being installed, so nothing looked wrong.
 *  - the dispatch side fails CLOSED, so it threw "decidesk is not installed"
 *    on an instance where it was installed — blocking every contract decision
 *    behind a message pointing at the wrong problem.
 *
 * An app cannot move another app's class name; it can only follow it. So the
 * property worth asserting is that these constants LIST the spellings rather
 * than pin one.
 */
class CrossAppEventNamesTest extends TestCase {
	/**
	 * Read a private class constant.
	 *
	 * @param string $class The class.
	 * @param string $name The constant name.
	 *
	 * @return array<int, string> The value.
	 */
	private function constant(string $class, string $name): array {
		$value = (new ReflectionClass($class))->getConstant($name);
		$this->assertIsArray($value, $name . ' must be a LIST of spellings, not a single string.');

		return $value;
	}

	/**
	 * Both decision-event constants name the current namespace and the old one.
	 *
	 * @param string $class The class holding the constant.
	 * @param string $name The constant name.
	 *
	 * @return void
	 *
	 * @dataProvider crossAppEventConstants
	 */
	public function testEachCrossAppEventListsEveryKnownNamespace(string $class, string $name): void {
		$spellings = $this->constant(class: $class, name: $name);

		$this->assertGreaterThanOrEqual(
			2,
			count($spellings),
			'Pinning ONE spelling is what broke this: the other app renamed and this side stopped resolving.'
		);

		$haystack = implode(' ', $spellings);
		$this->assertStringContainsString(
			'Decidiq',
			$haystack,
			'The CURRENT namespace must be listed, or the integration is dead on a renamed instance.'
		);
		$this->assertStringContainsString(
			'Decidesk',
			$haystack,
			'The OLD namespace must stay listed until no supported install ships it — otherwise the '
			. 'integration breaks in the other direction during a staggered upgrade.'
		);
	}

	/**
	 * The newest spelling is tried FIRST.
	 *
	 * Both resolve by "first one that exists", so ordering decides which is used
	 * on an instance carrying both — and that should be the current one.
	 *
	 * @param string $class The class holding the constant.
	 * @param string $name The constant name.
	 *
	 * @return void
	 *
	 * @dataProvider crossAppEventConstants
	 */
	public function testTheCurrentNamespaceIsPreferred(string $class, string $name): void {
		$spellings = $this->constant(class: $class, name: $name);

		$this->assertStringContainsString('Decidiq', $spellings[0]);
	}

	/**
	 * The constants that carry a cross-app event name.
	 *
	 * @return array<int, array<int, string>> The cases.
	 */
	public static function crossAppEventConstants(): array {
		return [
			[WorkflowListenerRegistrar::class, 'DECISION_CONCLUDED_EVENTS'],
			[ContractDecisionDelegationService::class, 'DECISION_REQUESTED_EVENTS'],
		];
	}
}
