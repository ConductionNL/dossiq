<?php

/**
 * Dossiq Woo review: the recall estimate of an elusion sample.
 *
 * @category Woo
 * @package  OCA\Dossiq\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-review-recall-and-stopping/proposal.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

use InvalidArgumentException;

/**
 * Estimates how complete a Woo review is from an elusion sample of the documents set aside.
 *
 * The method is the one the proposal names, so no building agent chooses
 * another. `found` documents are in scope; the null set (out of scope or
 * unmarked) holds N; a sample of n from it holds k judged in scope. The
 * elusion rate is k / n, its one-sided upper bound at confidence c is the
 * exact Clopper-Pearson bound (the p where P(X <= k | n, p) = 1 - c), and
 * recall is found / (found + rate x N), with its lower bound from the upper
 * elusion bound. The bound is a bisection over the binomial CDF, computed in
 * log space so a sample of thousands does not overflow. No statistics
 * library: none is in composer.lock.
 *
 * @spec openspec/changes/woo-review-recall-and-stopping/proposal.md
 */
class RecallEstimator {

	/**
	 * Bisection steps; 100 halvings is far below float resolution.
	 */
	private const STEPS = 100;

	/**
	 * The one-sided exact upper bound of a binomial proportion.
	 *
	 * @param int $k The successes in the sample.
	 * @param int $n The sample size.
	 * @param float $confidence The confidence, strictly between 0 and 1.
	 *
	 * @return float The upper bound of p.
	 *
	 * @throws InvalidArgumentException When the inputs describe no sample.
	 *
	 * @spec openspec/changes/woo-review-recall-and-stopping/proposal.md
	 */
	public function upperBound(int $k, int $n, float $confidence): float {
		if ($n < 1 || $k < 0 || $k > $n || $confidence <= 0.0 || $confidence >= 1.0) {
			throw new InvalidArgumentException('A bound needs 0 <= k <= n, n >= 1 and a confidence between 0 and 1.');
		}

		if ($k === $n) {
			return 1.0;
		}

		$alpha = 1.0 - $confidence;
		$low = 0.0;
		$high = 1.0;
		for ($step = 0; $step < self::STEPS; $step++) {
			$mid = (($low + $high) / 2);
			// The CDF falls as p rises: above alpha means the bound lies higher.
			if ($this->binomialCdf(k: $k, n: $n, prob: $mid) > $alpha) {
				$low = $mid;
				continue;
			}

			$high = $mid;
		}

		return $low;
	}//end upperBound()

	/**
	 * The recall estimate and its lower bound.
	 *
	 * @param int $found Documents marked in scope.
	 * @param int $nullSetSize Documents out of scope or unmarked (N).
	 * @param int $k Sampled documents judged in scope.
	 * @param int $n The sample size.
	 * @param float $confidence The confidence of the bound.
	 *
	 * @return array<string, int|float> The inputs, `elusion`, `elusionUpper`, `estimate` and `lowerBound`.
	 *
	 * @throws InvalidArgumentException When the inputs describe no estimate.
	 *
	 * @spec openspec/changes/woo-review-recall-and-stopping/proposal.md
	 */
	public function estimate(int $found, int $nullSetSize, int $k, int $n, float $confidence): array {
		if ($found < 0 || $nullSetSize < 0 || ($found === 0 && $nullSetSize === 0)) {
			throw new InvalidArgumentException('An estimate needs documents: found and the null set cannot both be empty.');
		}

		$upper = $this->upperBound(k: $k, n: $n, confidence: $confidence);
		$elusion = ($k / $n);

		return [
			'found' => $found,
			'nullSetSize' => $nullSetSize,
			'k' => $k,
			'n' => $n,
			'confidence' => $confidence,
			'elusion' => $elusion,
			'elusionUpper' => $upper,
			'estimate' => self::recall(found: $found, missed: ($elusion * $nullSetSize)),
			'lowerBound' => self::recall(found: $found, missed: ($upper * $nullSetSize)),
		];
	}//end estimate()

	/**
	 * Recall from what was found and what is estimated missed. Nothing found and nothing missed reads as 1.
	 *
	 * @param int $found The documents found.
	 * @param float $missed The documents estimated missed.
	 *
	 * @return float The recall.
	 */
	private static function recall(int $found, float $missed): float {
		$total = ($found + $missed);
		if ($total <= 0.0) {
			return 1.0;
		}

		return ($found / $total);
	}//end recall()

	/**
	 * P(X <= k) for X ~ Binomial(n, p), summed in log space.
	 *
	 * @param int $k The successes.
	 * @param int $n The trials.
	 * @param float $prob The probability.
	 *
	 * @return float The cumulative probability.
	 */
	private function binomialCdf(int $k, int $n, float $prob): float {
		if ($prob <= 0.0) {
			return 1.0;
		}

		if ($prob >= 1.0) {
			return 0.0;
		}

		$logP = log($prob);
		$logQ = log1p(-$prob);
		$logChoose = 0.0;
		$sum = 0.0;
		for ($i = 0; $i <= $k; $i++) {
			if ($i > 0) {
				$logChoose += (log($n - $i + 1) - log($i));
			}

			$sum += exp($logChoose + ($i * $logP) + (($n - $i) * $logQ));
		}

		return min(1.0, $sum);
	}//end binomialCdf()
}//end class
