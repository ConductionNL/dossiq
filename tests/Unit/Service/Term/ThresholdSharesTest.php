<?php

/**
 * Dossiq ThresholdShares test.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Term
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Term;

use OCA\Dossiq\Service\Term\ThresholdShares;
use PHPUnit\Framework\TestCase;

/**
 * A quarter of the term means the same thing on every term and a different
 * date on each, which is the whole point of declaring a share.
 *
 * @spec openspec/changes/term-configuration-beyond-the-case-type/specs/termijnbewaking-schemas/spec.md
 */
class ThresholdSharesTest extends TestCase {
	/**
	 * Scenario: A quarter of the term means two different dates.
	 *
	 * @return void
	 */
	public function testAQuarterOfTwoTermsIsTwoDifferentOffsets(): void {
		$ladder = [['share' => 0.25, 'message' => 'kwart']];

		$sixWeeks = (new ThresholdShares())->rulesFor(ladder: $ladder, slaDays: 42);
		$twentySixWeeks = (new ThresholdShares())->rulesFor(ladder: $ladder, slaDays: 182);

		$this->assertNotSame($sixWeeks[0]['offset'], $twentySixWeeks[0]['offset']);

		// Both are a quarter of their own term: the engine counts BACK from
		// the end, so a quarter elapsed is three quarters still to run.
		$this->assertSame(31, $sixWeeks[0]['offset']);
		$this->assertSame(136, $twentySixWeeks[0]['offset']);
	}//end testAQuarterOfTwoTermsIsTwoDifferentOffsets()

	/**
	 * Scenario: A ladder may mix days and shares, and both become rungs on
	 * the one engine ladder rather than a second mechanism.
	 *
	 * @return void
	 */
	public function testALadderMixesDaysAndShares(): void {
		$rules = (new ThresholdShares())->rulesFor(
			ladder: [
				['share' => 0.5, 'message' => 'halverwege'],
				['days' => 2, 'message' => 'bijna-verlopen', 'priority' => 'high'],
			],
			slaDays: 42
		);

		$this->assertCount(2, $rules);
		$this->assertSame([21, 2], array_column($rules, 'offset'));
		$this->assertSame(['halverwege', 'bijna-verlopen'], array_column($rules, 'message'));
		$this->assertSame('high', $rules[1]['priority']);

		foreach ($rules as $rule) {
			$this->assertSame('preBreach', $rule['trigger']);
			$this->assertSame('calendarDays', $rule['offsetUnit']);
		}
	}//end testALadderMixesDaysAndShares()

	/**
	 * Scenario: An extension re-resolves the shares. The same declared rung
	 * answers a different offset once the term is longer, which is what makes
	 * re-arming the timer enough.
	 *
	 * @return void
	 */
	public function testAnExtendedTermMovesTheShareRung(): void {
		$ladder = [['share' => 0.5]];

		$before = (new ThresholdShares())->rulesFor(ladder: $ladder, slaDays: 42);
		$after = (new ThresholdShares())->rulesFor(ladder: $ladder, slaDays: 70);

		$this->assertSame(21, $before[0]['offset']);
		$this->assertSame(35, $after[0]['offset']);
	}//end testAnExtendedTermMovesTheShareRung()

	/**
	 * A rung nobody can place is dropped rather than guessed at.
	 *
	 * @return void
	 */
	public function testARungThatCannotBePlacedIsDropped(): void {
		$rules = (new ThresholdShares())->rulesFor(
			ladder: [
				['message' => 'niets'],
				['share' => 1.7],
				['days' => 90],
				['days' => -1],
				'not an array',
				['days' => 3],
			],
			slaDays: 42
		);

		$this->assertSame([3], array_column($rules, 'offset'));
	}//end testARungThatCannotBePlacedIsDropped()

	/**
	 * A term of no length has no rungs, rather than rungs at nought.
	 *
	 * @return void
	 */
	public function testATermOfNoLengthHasNoRungs(): void {
		$this->assertSame([], (new ThresholdShares())->rulesFor(ladder: [['share' => 0.5]], slaDays: 0));
	}//end testATermOfNoLengthHasNoRungs()

	/**
	 * A term that declares no ladder keeps the seeded one, which the caller
	 * sees as an empty rule list.
	 *
	 * @return void
	 */
	public function testNoDeclaredLadderIsNoRules(): void {
		$this->assertSame([], (new ThresholdShares())->rulesFor(ladder: [], slaDays: 42));
	}//end testNoDeclaredLadderIsNoRules()
}//end class
