<?php

/**
 * RoutingRuleOrder Unit Tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Kcc
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Kcc;

use OCA\Dossiq\Service\Kcc\RoutingRuleOrder;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RoutingRuleOrder.
 *
 * @covers \OCA\Dossiq\Service\Kcc\RoutingRuleOrder
 */
class RoutingRuleOrderTest extends TestCase {

	/**
	 * Disabled rules are dropped and the rest sorted by ascending priority.
	 *
	 * @return void
	 */
	public function testEnabledRulesAreSortedByAscendingPriority(): void {
		$rules = [
			['name' => 'c', 'priority' => 30],
			['name' => 'off', 'priority' => 1, 'enabled' => false],
			['name' => 'a', 'priority' => 10, 'enabled' => true],
			['name' => 'b', 'priority' => '20'],
		];

		$this->assertSame(['a', 'b', 'c'], array_column(RoutingRuleOrder::enabledByPriority($rules), 'name'));
	}//end testEnabledRulesAreSortedByAscendingPriority()

	/**
	 * A rule without `enabled` counts as enabled; one without `priority` sorts as 0;
	 * only boolean true keeps a rule that does set `enabled`.
	 *
	 * @return void
	 */
	public function testMissingFieldsDefaultToEnabledAndPriorityZero(): void {
		$rules = [
			['name' => 'late', 'priority' => 5],
			['name' => 'nopriority'],
			['name' => 'stringy', 'priority' => 1, 'enabled' => 'yes'],
		];

		$result = RoutingRuleOrder::enabledByPriority($rules);

		$this->assertSame(['nopriority', 'late'], array_column($result, 'name'));
		$this->assertSame([0, 1], array_keys($result));
	}//end testMissingFieldsDefaultToEnabledAndPriorityZero()

	/**
	 * No rules give no rules.
	 *
	 * @return void
	 */
	public function testNoRulesGiveAnEmptyList(): void {
		$this->assertSame([], RoutingRuleOrder::enabledByPriority([]));
	}//end testNoRulesGiveAnEmptyList()

	/**
	 * An empty or blank customer reference is anonymous.
	 *
	 * @return void
	 */
	public function testABlankReferenceIsAnonymous(): void {
		$this->assertSame('anoniem', RoutingRuleOrder::customerType([]));
		$this->assertSame('anoniem', RoutingRuleOrder::customerType(['customerRef' => '   ']));
	}//end testABlankReferenceIsAnonymous()

	/**
	 * Exactly eight digits is a KvK number, so a company.
	 *
	 * @return void
	 */
	public function testEightDigitsIsACompany(): void {
		$this->assertSame('bedrijf', RoutingRuleOrder::customerType(['customerRef' => '12345678']));
		$this->assertSame('bedrijf', RoutingRuleOrder::customerType(['customerRef' => ' 12345678 ']));
	}//end testEightDigitsIsACompany()

	/**
	 * Any other reference, such as a nine-digit BSN, is a citizen.
	 *
	 * @return void
	 */
	public function testAnyOtherReferenceIsACitizen(): void {
		$this->assertSame('burger', RoutingRuleOrder::customerType(['customerRef' => '123456782']));
		$this->assertSame('burger', RoutingRuleOrder::customerType(['customerRef' => '1234567']));
		$this->assertSame('burger', RoutingRuleOrder::customerType(['customerRef' => 'K-12345678']));
	}//end testAnyOtherReferenceIsACitizen()
}//end class
