<?php

/**
 * One chain term, split over its steps and recomputed when a step overruns.
 *
 * Pure arithmetic, so every case here is the whole mechanism: no store, no
 * calendar, no clock. The one thing the tests keep honest is that the chain's
 * end never moves, because moving it is the failure this design exists to stop.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\ChainTermSplitter;
use PHPUnit\Framework\TestCase;

/**
 * REQ-TERM-065: a chain term is declared once and split over its steps.
 *
 * @covers \OCA\Dossiq\Service\ChainTermSplitter
 */
class ChainTermSplitTest extends TestCase {
	/**
	 * The splitter under test.
	 *
	 * @var ChainTermSplitter
	 */
	private ChainTermSplitter $splitter;

	/**
	 * Build the splitter.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->splitter = new ChainTermSplitter();
	}//end setUp()

	/**
	 * One deadline over four steps: each carries its declared share.
	 *
	 * @return void
	 */
	public function testOneDeadlineOverFourSteps(): void {
		$parts = $this->splitter->split(termDays: 40, shares: [25.0, 25.0, 25.0, 25.0]);

		self::assertSame([10, 10, 10, 10], $parts);
		self::assertSame(40, array_sum($parts), 'The parts add up to the term, or the chain loses days per run.');
	}//end testOneDeadlineOverFourSteps()

	/**
	 * Uneven shares split in the declared proportion.
	 *
	 * @return void
	 */
	public function testUnevenSharesSplitInProportion(): void {
		$parts = $this->splitter->split(termDays: 40, shares: [50.0, 25.0, 25.0]);

		self::assertSame([20, 10, 10], $parts);
	}//end testUnevenSharesSplitInProportion()

	/**
	 * Shares that do not add up to a hundred are normalised, not trusted.
	 *
	 * A chain whose author wrote 1, 2, 1 gets a quarter, a half and a quarter.
	 *
	 * @return void
	 */
	public function testSharesAreNormalisedRatherThanTrusted(): void {
		$parts = $this->splitter->split(termDays: 40, shares: [1.0, 2.0, 1.0]);

		self::assertSame([10, 20, 10], $parts);
	}//end testSharesAreNormalisedRatherThanTrusted()

	/**
	 * A chain nobody has configured still splits evenly.
	 *
	 * @return void
	 */
	public function testAnUndeclaredChainSplitsEvenly(): void {
		self::assertSame([20, 20], $this->splitter->split(termDays: 40, shares: [0.0, 0.0]));
	}//end testAnUndeclaredChainSplitsEvenly()

	/**
	 * The rounding lands on the last step, so nothing is lost.
	 *
	 * @return void
	 */
	public function testTheRoundingIsAbsorbedByTheLastStep(): void {
		$parts = $this->splitter->split(termDays: 10, shares: [33.0, 33.0, 34.0]);

		self::assertSame(10, array_sum($parts));
	}//end testTheRoundingIsAbsorbedByTheLastStep()

	/**
	 * A step that overran shrinks the ones after it, and the chain end holds.
	 *
	 * @return void
	 */
	public function testAStepThatOverranShrinksTheOnesAfterIt(): void {
		$plan = $this->splitter->recompute(
			termDays: 40,
			shares: [25.0, 25.0, 25.0, 25.0],
			consumed: [15]
		);

		self::assertSame(25, $plan['remainingDays'], 'Fifteen of forty days are spent, so twenty-five are left.');
		self::assertSame([8, 8, 9], $plan['steps'], 'The three remaining steps divide what is left, not what they were promised.');
		self::assertSame(25, array_sum($plan['steps']), 'The chain end does not move.');
		self::assertFalse($plan['exhausted']);
	}//end testAStepThatOverranShrinksTheOnesAfterIt()

	/**
	 * A step that came in early leaves more for the ones after it.
	 *
	 * @return void
	 */
	public function testAStepThatCameInEarlyLeavesMore(): void {
		$plan = $this->splitter->recompute(
			termDays: 40,
			shares: [25.0, 25.0, 25.0, 25.0],
			consumed: [5]
		);

		self::assertSame(35, $plan['remainingDays']);
		self::assertSame(35, array_sum($plan['steps']));
	}//end testAStepThatCameInEarlyLeavesMore()

	/**
	 * An exhausted chain says zero rather than moving its end.
	 *
	 * @return void
	 */
	public function testAnExhaustedChainReportsZero(): void {
		$plan = $this->splitter->recompute(
			termDays: 40,
			shares: [25.0, 25.0, 25.0, 25.0],
			consumed: [20, 25]
		);

		self::assertSame(0, $plan['remainingDays']);
		self::assertTrue($plan['exhausted']);
		self::assertSame([0, 0], $plan['steps'], 'Every remaining step gets zero days, which is true and actionable.');
	}//end testAnExhaustedChainReportsZero()

	/**
	 * A chain with nothing left to run still reports what is left of the term.
	 *
	 * @return void
	 */
	public function testAFinishedChainReportsItsRemainder(): void {
		$plan = $this->splitter->recompute(termDays: 40, shares: [50.0, 50.0], consumed: [10, 10]);

		self::assertSame([], $plan['steps']);
		self::assertSame(20, $plan['remainingDays']);
		self::assertFalse($plan['exhausted']);
	}//end testAFinishedChainReportsItsRemainder()

	/**
	 * A chain with no steps splits into nothing rather than dividing by zero.
	 *
	 * @return void
	 */
	public function testAChainWithNoStepsIsEmpty(): void {
		self::assertSame([], $this->splitter->split(termDays: 40, shares: []));
	}//end testAChainWithNoStepsIsEmpty()
}//end class
