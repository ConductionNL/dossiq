<?php

/**
 * Dossiq CMMN Sentry Evaluator.
 *
 * Pure evaluation of a single CMMN sentry (`{onPart?, ifPart?}`) or an array
 * of sentries (OR across the array) against a plain evaluation context — no
 * I/O, no OpenRegister access. `CaseModelEngine` builds the context from the
 * live case-plan state and hands it here.
 *
 * Semantics (`openspec/changes/cmmn-adaptive-case/design.md` §4):
 *  - a sentry fires when its `onPart` (if present) has occurred AND its
 *    `ifPart` (if present) evaluates true — AND within one sentry;
 *  - an item's entry/exit is satisfied when ANY sentry in its criteria array
 *    fires — OR across the array.
 *
 * `onPart.planItem` events are checked against *current* plan-item state
 * because `complete`/`terminate`/`disable` are terminal, monotonic states —
 * once reached they never revert, so "has the event occurred" and "is the
 * item currently in that state" are equivalent and need no separate event
 * log. `onPart.caseFileItem` events are NOT monotonic (a value can be set
 * repeatedly), so they are checked against the `touchedKeys`/`changedKeys`
 * sets for the single signal call currently being evaluated.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Cmmn
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-003
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Cmmn;

/**
 * Pure sentry-firing logic.
 *
 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-003
 */
final class SentryEvaluator {
	/**
	 * Whether ANY sentry in the given array fires against the context (OR
	 * across the array). An empty array is treated by the caller as
	 * trivially satisfied — this method returns false for an empty array so
	 * callers make that "empty = trivial" decision explicitly.
	 *
	 * @param array<int, array<string, mixed>> $sentries The criteria array.
	 * @param array<string, mixed> $context Evaluation context, see {@see fires()}.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-003
	 */
	public function anyFires(array $sentries, array $context): bool {
		foreach ($sentries as $sentry) {
			if (is_array($sentry) === true && $this->fires(sentry: $sentry, context: $context) === true) {
				return true;
			}
		}

		return false;
	}//end anyFires()

	/**
	 * Whether a single sentry fires against the context.
	 *
	 * The `$context` array carries: `planItemStates` (array<string,string> —
	 * current state per plan-item id), `caseFile` (array<string,mixed> —
	 * current case-file data snapshot), `touchedKeys` (array<int,string> —
	 * case-file item ids touched in this signal call), and `changedKeys`
	 * (array<int,string> — the subset of touchedKeys whose value changed).
	 *
	 * @param array<string, mixed> $sentry `{id?, onPart?, ifPart?}`.
	 * @param array<string, mixed> $context Evaluation context (see above).
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-003
	 */
	public function fires(array $sentry, array $context): bool {
		$onPart = $sentry['onPart'] ?? null;
		$ifPart = $sentry['ifPart'] ?? null;

		if (is_array($onPart) === true && $onPart !== [] && $this->onPartSatisfied(onPart: $onPart, context: $context) === false) {
			return false;
		}

		if (is_array($ifPart) === true && $ifPart !== [] && $this->ifPartSatisfied(ifPart: $ifPart, context: $context) === false) {
			return false;
		}

		return true;
	}//end fires()

	/**
	 * Evaluate an `onPart`.
	 *
	 * @param array<string, mixed> $onPart The onPart definition.
	 * @param array<string, mixed> $context Evaluation context.
	 *
	 * @return bool
	 */
	private function onPartSatisfied(array $onPart, array $context): bool {
		$planItemId = $onPart['planItem'] ?? null;
		if (is_string($planItemId) === true && $planItemId !== '') {
			$targetState = match ($onPart['standardEvent'] ?? '') {
				'complete' => PlanItemTransitions::STATE_COMPLETED,
				'terminate' => PlanItemTransitions::STATE_TERMINATED,
				'disable' => PlanItemTransitions::STATE_DISABLED,
				default => null,
			};

			if ($targetState === null) {
				return false;
			}

			$states = $context['planItemStates'] ?? [];
			return ($states[$planItemId] ?? null) === $targetState;
		}

		$caseFileItemId = $onPart['caseFileItem'] ?? null;
		if (is_string($caseFileItemId) === true && $caseFileItemId !== '') {
			$event = $onPart['caseFileEvent'] ?? 'set';
			if ($event === 'changed') {
				return in_array($caseFileItemId, ($context['changedKeys'] ?? []), true);
			}

			return in_array($caseFileItemId, ($context['touchedKeys'] ?? []), true);
		}

		// Malformed onPart (neither shape present) never fires.
		return false;
	}//end onPartSatisfied()

	/**
	 * Evaluate an `ifPart` condition against the case-file snapshot.
	 *
	 * @param array<string, mixed> $ifPart `{field, operator, value}`.
	 * @param array<string, mixed> $context Evaluation context.
	 *
	 * @return bool
	 */
	private function ifPartSatisfied(array $ifPart, array $context): bool {
		$field = (string)($ifPart['field'] ?? '');
		if ($field === '') {
			// A malformed condition (no field) can never be satisfied — fail
			// closed rather than treat it as vacuously true.
			return false;
		}

		$operator = (string)($ifPart['operator'] ?? 'eq');
		$expected = ($ifPart['value'] ?? null);
		$actual = (($context['caseFile'] ?? [])[$field] ?? null);

		return $this->compare(operator: $operator, actual: $actual, expected: $expected);
	}//end ifPartSatisfied()

	/**
	 * Compare an actual case-file value against an expected value per operator.
	 *
	 * @param string $operator One of eq|neq|gt|gte|lt|lte|in|notIn|truthy|falsy.
	 * @param mixed $actual Current case-file value.
	 * @param mixed $expected Sentry-configured comparison value.
	 *
	 * @return bool
	 */
	private function compare(string $operator, mixed $actual, mixed $expected): bool {
		if (in_array($operator, ['gt', 'gte', 'lt', 'lte'], true) === true) {
			return $this->compareNumeric(operator: $operator, actual: $actual, expected: $expected);
		}

		// The eq/neq operators use loose comparison deliberately: case-file
		// values may be bool/string/int depending on the caseFileItem's
		// declared type, and a sentry author should not have to match PHP's
		// strict type rules.
		return match ($operator) {
			'eq' => $actual == $expected,
			'neq' => $actual != $expected,
			'in' => is_array($expected) === true && in_array($actual, $expected, true),
			'notIn' => is_array($expected) === true && in_array($actual, $expected, true) === false,
			'truthy' => (bool)$actual === true,
			'falsy' => (bool)$actual === false,
			default => false,
		};
	}//end compare()

	/**
	 * Compare two values with a numeric operator, requiring both sides to be
	 * numeric (a non-numeric operand always fails the comparison).
	 *
	 * @param string $operator One of gt|gte|lt|lte.
	 * @param mixed $actual Current case-file value.
	 * @param mixed $expected Sentry-configured comparison value.
	 *
	 * @return bool
	 */
	private function compareNumeric(string $operator, mixed $actual, mixed $expected): bool {
		if (is_numeric($actual) === false || is_numeric($expected) === false) {
			return false;
		}

		return match ($operator) {
			'gt' => $actual > $expected,
			'gte' => $actual >= $expected,
			'lt' => $actual < $expected,
			'lte' => $actual <= $expected,
			default => false,
		};
	}//end compareNumeric()
}//end class
