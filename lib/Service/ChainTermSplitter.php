<?php

/**
 * Dossiq ChainTermSplitter.
 *
 * One deadline given to a whole chain, split over its steps.
 *
 * OpenCase is the only system in the corpus that derives a step deadline from a
 * case deadline. This follows it: the chain carries the term, the steps carry
 * shares of it, and the remaining shares are recomputed when a step ends early
 * or late.
 *
 * The chain's own end never moves. A chain whose steps have eaten it reports
 * zero days for the steps that are left, which is a true and actionable
 * statement, rather than silently moving the promise to a later date. Moving it
 * would make every step look achievable and the chain miss its end anyway.
 *
 * No dates, no calendar, no store: this is arithmetic over day counts, so it can
 * be read and tested without a running register. The calendar walk happens once,
 * where the split is turned into an end date.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 *
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * Splitting a chain term over its steps, and recomputing what is left (REQ-TERM-065).
 *
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
 */
class ChainTermSplitter {
	/**
	 * Split a chain term over its steps by the share each declares.
	 *
	 * Shares are percentages and need not add up to a hundred: they are
	 * normalised over the steps that actually declare one, so a chain whose
	 * author wrote 1, 2, 1 gets a quarter, a half and a quarter. A step that
	 * declares no share gets an equal part of what the declared ones leave, so
	 * a chain nobody has configured still splits evenly rather than giving the
	 * first step everything.
	 *
	 * The last step absorbs the rounding, so the parts add up to the term
	 * exactly. A chain whose parts add up to one day less than its term is a
	 * chain that quietly loses a day per run.
	 *
	 * @param int $termDays The whole chain term, in days.
	 * @param array<int, float> $shares One share per step, in declaration order.
	 *
	 * @return array<int, int> One day count per step, summing to $termDays.
	 *
	 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
	 */
	public function split(int $termDays, array $shares): array {
		$steps = count($shares);
		if ($steps === 0) {
			return [];
		}

		$term = max(0, $termDays);
		$weights = $this->weights(shares: $shares);
		$total = array_sum($weights);
		if ($total <= 0.0) {
			return array_fill(0, $steps, 0);
		}

		$parts = [];
		$assigned = 0;
		foreach ($weights as $index => $weight) {
			if ($index === ($steps - 1)) {
				$parts[] = max(0, ($term - $assigned));
				continue;
			}

			$part = (int)floor((($weight / $total) * $term));
			$parts[] = $part;
			$assigned += $part;
		}//end foreach

		return $parts;
	}//end split()

	/**
	 * Recompute the steps that are still to come.
	 *
	 * What the finished steps actually used is spent, whether they came in
	 * early or late. What is left of the chain term is split over the steps
	 * that remain, by the shares they declared. The chain end does not move, so
	 * a chain already overspent gives every remaining step zero.
	 *
	 * @param int $termDays The whole chain term, in days.
	 * @param array<int, float> $shares One share per step, in declaration order.
	 * @param array<int, int> $consumed Days actually used, one per FINISHED step,
	 *        in the same order. Its length says how far the chain has got.
	 *
	 * @return array{remainingDays: int, steps: array<int, int>, exhausted: bool}
	 *         What is left, the day count for each step still to come, and
	 *         whether the chain has nothing left to give.
	 *
	 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
	 */
	public function recompute(int $termDays, array $shares, array $consumed): array {
		$spent = 0;
		foreach ($consumed as $days) {
			$spent += max(0, (int)$days);
		}//end foreach

		$remaining = max(0, (max(0, $termDays) - $spent));
		$done = count($consumed);
		$ahead = array_slice(array_values($shares), $done);

		if (count($ahead) === 0) {
			return ['remainingDays' => $remaining, 'steps' => [], 'exhausted' => ($remaining === 0)];
		}

		return [
			'remainingDays' => $remaining,
			'steps' => $this->split(termDays: $remaining, shares: $ahead),
			'exhausted' => ($remaining === 0),
		];
	}//end recompute()

	/**
	 * The weight of every step, with undeclared steps sharing what is left.
	 *
	 * @param array<int, float> $shares The declared shares, in order.
	 *
	 * @return array<int, float> One positive weight per step.
	 */
	private function weights(array $shares): array {
		$values = array_map(static fn (mixed $share): float => max(0.0, (float)$share), array_values($shares));
		$declared = array_filter($values, static fn (float $share): bool => $share > 0.0);

		if (count($declared) === 0) {
			return array_fill(0, count($values), 1.0);
		}

		if (count($declared) === count($values)) {
			return $values;
		}

		// Steps that declare nothing divide whatever the declared ones leave
		// under a hundred, and get an equal sliver when they leave nothing.
		$claimed = array_sum($declared);
		$left = max(0.0, (100.0 - $claimed));
		$undeclared = (count($values) - count($declared));
		$each = 0.01;
		if ($left > 0.0) {
			$each = ($left / $undeclared);
		}

		$weights = [];
		foreach ($values as $share) {
			if ($share > 0.0) {
				$weights[] = $share;
				continue;
			}

			$weights[] = $each;
		}//end foreach

		return $weights;
	}//end weights()
}//end class
