<?php

/**
 * RuleConditions unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Kcc
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Kcc;

use OCA\Dossiq\Service\Kcc\RuleConditions;
use PHPUnit\Framework\TestCase;

/**
 * A rule's gathered conditions: its equalities and its time window.
 *
 * @covers \OCA\Dossiq\Service\Kcc\RuleConditions
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class RuleConditionsTest extends TestCase {

	/**
	 * The same value twice holds; a second, different value for one column does not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testAContradictoryEqualityIsRefused(): void {
		$conditions = new RuleConditions();

		$this->assertTrue(condition: $conditions->requireEqual(column: 'channel', value: 'phone'));
		$this->assertTrue(condition: $conditions->requireEqual(column: 'channel', value: 'phone'));
		$this->assertFalse(condition: $conditions->requireEqual(column: 'channel', value: 'email'));
		$this->assertSame(expected: ['channel' => 'phone'], actual: $conditions->equalities);
	}//end testAContradictoryEqualityIsRefused()

	/**
	 * Every after raises the lower bound, every before lowers the upper, and the cell reads the window.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testTimeConditionsNarrowTheWindowAndWriteItsCell(): void {
		$conditions = new RuleConditions();
		$this->assertNull(actual: $conditions->timeCell());

		$this->assertTrue(condition: $conditions->narrowTime(value: 'after_08:30'));
		$this->assertSame(expected: '>= 510', actual: $conditions->timeCell());

		$this->assertTrue(condition: $conditions->narrowTime(value: 'after_09:00'));
		$this->assertTrue(condition: $conditions->narrowTime(value: 'after_08:00'));
		$this->assertTrue(condition: $conditions->narrowTime(value: 'before_17:00'));
		$this->assertTrue(condition: $conditions->narrowTime(value: 'before_18:00'));
		$this->assertSame(expected: '[540..1020)', actual: $conditions->timeCell());

		$this->assertFalse(condition: $conditions->narrowTime(value: 'around_12:00'));

		$upperOnly = new RuleConditions();
		$upperOnly->narrowTime(value: 'before_7:15');
		$this->assertSame(expected: '< 435', actual: $upperOnly->timeCell());
	}//end testTimeConditionsNarrowTheWindowAndWriteItsCell()
}//end class
