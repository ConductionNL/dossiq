<?php

/**
 * Unit tests for the Woo recall estimator.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Woo
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

namespace OCA\Dossiq\Tests\Unit\Woo;

use InvalidArgumentException;
use OCA\Dossiq\Woo\RecallEstimator;
use PHPUnit\Framework\TestCase;

/**
 * The elusion estimate and its exact (Clopper-Pearson) bound, pinned to the proposal's hand-computed fixture.
 *
 * @covers \OCA\Dossiq\Woo\RecallEstimator
 *
 * @spec openspec/changes/woo-review-recall-and-stopping/proposal.md
 */
class RecallEstimatorTest extends TestCase {

	/**
	 * Found 400, null set 1600, sample 200 with 2 in scope: estimate 0.9615, lower bound 0.8892.
	 *
	 * @return void
	 */
	public function testTheFixtureWithTwoInScope(): void {
		$estimate = (new RecallEstimator())->estimate(found: 400, nullSetSize: 1600, k: 2, n: 200, confidence: 0.95);

		$this->assertSame(0.9615, round($estimate['estimate'], 4));
		$this->assertSame(0.8892, round($estimate['lowerBound'], 4));
		$this->assertSame(0.01, $estimate['elusion']);
		$this->assertSame(0.0311, round($estimate['elusionUpper'], 4));
		$this->assertSame(['found' => 400, 'nullSetSize' => 1600, 'k' => 2, 'n' => 200, 'confidence' => 0.95], array_intersect_key($estimate, array_flip(['found', 'nullSetSize', 'k', 'n', 'confidence'])));
	}//end testTheFixtureWithTwoInScope()

	/**
	 * With 10 in scope: estimate 0.8333, lower bound 0.7500.
	 *
	 * @return void
	 */
	public function testTheFixtureWithTenInScope(): void {
		$estimate = (new RecallEstimator())->estimate(found: 400, nullSetSize: 1600, k: 10, n: 200, confidence: 0.95);

		$this->assertSame(0.8333, round($estimate['estimate'], 4));
		$this->assertSame(0.75, round($estimate['lowerBound'], 4));
	}//end testTheFixtureWithTenInScope()

	/**
	 * None in scope still leaves a positive upper bound: a clean sample is not proof of nothing missed.
	 *
	 * @return void
	 */
	public function testZeroInScopeHasAPositiveUpperBound(): void {
		$estimator = new RecallEstimator();
		$upper = $estimator->upperBound(k: 0, n: 200, confidence: 0.95);

		$this->assertGreaterThan(0.0, $upper);
		// 1 - 0.05^(1/200), the closed form for k = 0.
		$this->assertEqualsWithDelta(1 - (0.05 ** (1 / 200)), $upper, 1e-9);
		$this->assertSame(1.0, $estimator->estimate(found: 400, nullSetSize: 1600, k: 0, n: 200, confidence: 0.95)['estimate']);
		$this->assertLessThan(1.0, $estimator->estimate(found: 400, nullSetSize: 1600, k: 0, n: 200, confidence: 0.95)['lowerBound']);
		$this->assertSame(1.0, $estimator->upperBound(k: 200, n: 200, confidence: 0.95));
	}//end testZeroInScopeHasAPositiveUpperBound()

	/**
	 * A higher confidence asks a wider bound, and a large sample does not overflow.
	 *
	 * @return void
	 */
	public function testTheBoundRisesWithConfidence(): void {
		$estimator = new RecallEstimator();
		$this->assertGreaterThan(
			$estimator->upperBound(k: 2, n: 200, confidence: 0.90),
			$estimator->upperBound(k: 2, n: 200, confidence: 0.99)
		);

		$large = $estimator->upperBound(k: 50, n: 5000, confidence: 0.95);
		$this->assertGreaterThan(0.01, $large);
		$this->assertLessThan(0.0135, $large);
	}//end testTheBoundRisesWithConfidence()

	/**
	 * Impossible inputs are refused, not estimated.
	 *
	 * @return void
	 */
	public function testImpossibleInputsAreRefused(): void {
		$estimator = new RecallEstimator();
		foreach ([[0, 0, 0.95], [3, 2, 0.95], [-1, 10, 0.95], [1, 10, 1.0], [1, 10, 0.0]] as [$k, $n, $confidence]) {
			try {
				$estimator->upperBound(k: $k, n: $n, confidence: $confidence);
				$this->fail('Refusal expected for k '.$k.' n '.$n.' c '.$confidence);
			} catch (InvalidArgumentException) {
				$this->addToAssertionCount(1);
			}
		}

		$this->expectException(InvalidArgumentException::class);
		$estimator->estimate(found: 0, nullSetSize: 0, k: 0, n: 10, confidence: 0.95);
	}//end testImpossibleInputsAreRefused()
}//end class
