<?php

/**
 * Procest DMN Decision Engine
 *
 * Pure evaluation of a decision-table definition against an inputs map.
 * No OpenRegister, HTTP, or database dependency — a deterministic function
 * of (decisionTable, inputs) -> outputs. Never silently defaults: every
 * ambiguous or invalid situation surfaces as a typed
 * {@see DecisionEvaluationException}.
 *
 * @category Service
 * @package  OCA\Procest\Service\Dmn
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
 * @link https://procest.nl
 *
 * @spec openspec/specs/dmn-decision-tables/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Dmn;

/**
 * Evaluates a decisionTable definition against a runtime inputs map.
 *
 * @spec openspec/specs/dmn-decision-tables/spec.md
 */
class DecisionEngine {

	/**
	 * Hit policies fully implemented by this engine.
	 *
	 * @var string[]
	 */
	private const IMPLEMENTED_HIT_POLICIES = ['UNIQUE', 'FIRST', 'COLLECT'];

	/**
	 * Constructor.
	 *
	 * The evaluator is a pure, stateless collaborator; the default keeps the
	 * engine directly constructible (`new DecisionEngine()`) while the
	 * Nextcloud container autowires the concrete class when resolved via DI.
	 *
	 * @param ExpressionEvaluator $evaluator The rule-cell expression evaluator.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ExpressionEvaluator $evaluator = new ExpressionEvaluator(),
	) {
	}//end __construct()

	/**
	 * Evaluate a decision table.
	 *
	 * @param array<string, mixed> $decisionTable The decision table definition
	 *                                            (`inputs`, `outputs`, `rules`, `hitPolicy`).
	 * @param array<string, mixed> $inputs Caller-supplied input values, keyed by input name.
	 *
	 * @return array{outputs: array<string, mixed>, matchedRuleIds: array<int, string>, hitPolicy: string}
	 *
	 * @throws DecisionEvaluationException `unknown_input`, `missing_input`, `type_mismatch`,
	 *                                     `invalid_expression`, `no_rule_matched`,
	 *                                     `hit_policy_violation`, `hit_policy_not_implemented`.
	 *
	 * @spec openspec/specs/dmn-decision-tables/spec.md
	 */
	public function evaluate(array $decisionTable, array $inputs): array {
		$declaredInputs = self::normaliseFields(fields: ($decisionTable['inputs'] ?? []));
		$declaredOutputs = self::normaliseFields(fields: ($decisionTable['outputs'] ?? []));
		$hitPolicy = strtoupper((string)($decisionTable['hitPolicy'] ?? 'UNIQUE'));

		$rules = [];
		if (is_array($decisionTable['rules'] ?? null) === true) {
			$rules = $decisionTable['rules'];
		}

		if (in_array($hitPolicy, self::IMPLEMENTED_HIT_POLICIES, true) === false) {
			throw new DecisionEvaluationException(errorCode: 'hit_policy_not_implemented', details: ['hitPolicy' => $hitPolicy]);
		}

		$coercedInputs = $this->resolveInputs(declaredInputs: $declaredInputs, inputs: $inputs);

		$matchedRules = [];
		foreach ($rules as $index => $rule) {
			if (is_array($rule) === false) {
				continue;
			}

			if ($this->ruleMatches(rule: $rule, declaredInputs: $declaredInputs, coercedInputs: $coercedInputs, ruleIndex: $index) === true) {
				$matchedRules[] = $rule;
			}
		}

		return $this->applyHitPolicy(
			hitPolicy: $hitPolicy,
			matchedRules: $matchedRules,
			declaredOutputs: $declaredOutputs,
		);
	}//end evaluate()

	/**
	 * Validate the caller's inputs against the declared inputs and coerce
	 * each to its declared type.
	 *
	 * @param array<int, array{name: string, type: string}> $declaredInputs Declared inputs.
	 * @param array<string, mixed> $inputs Caller-supplied values.
	 *
	 * @return array<string, mixed> Coerced values keyed by input name.
	 *
	 * @throws DecisionEvaluationException `unknown_input`, `missing_input`, `type_mismatch`.
	 */
	private function resolveInputs(array $declaredInputs, array $inputs): array {
		$declaredNames = array_map(static fn (array $input): string => $input['name'], $declaredInputs);

		foreach (array_keys($inputs) as $key) {
			if (in_array($key, $declaredNames, true) === false) {
				throw new DecisionEvaluationException(errorCode: 'unknown_input', details: ['key' => $key]);
			}
		}

		$coerced = [];
		foreach ($declaredInputs as $declared) {
			$name = $declared['name'];
			if (array_key_exists($name, $inputs) === false) {
				throw new DecisionEvaluationException(errorCode: 'missing_input', details: ['name' => $name]);
			}

			$coerced[$name] = $this->evaluator->coerce(value: $inputs[$name], type: $declared['type']);
		}

		return $coerced;
	}//end resolveInputs()

	/**
	 * Check whether every input entry on a rule matches the coerced inputs.
	 *
	 * @param array<string, mixed> $rule The rule row.
	 * @param array<int, array{name: string, type: string}> $declaredInputs Declared inputs, positionally aligned.
	 * @param array<string, mixed> $coercedInputs Coerced runtime values, keyed by name.
	 * @param int|string $ruleIndex Rule position (for error context).
	 *
	 * @return bool
	 *
	 * @throws DecisionEvaluationException `invalid_expression`/`type_mismatch` (re-thrown with rule context).
	 */
	private function ruleMatches(array $rule, array $declaredInputs, array $coercedInputs, int|string $ruleIndex): bool {
		$entries = [];
		if (is_array($rule['inputEntries'] ?? null) === true) {
			$entries = $rule['inputEntries'];
		}

		foreach ($declaredInputs as $position => $declared) {
			$expression = (string)($entries[$position] ?? '-');
			$value = $coercedInputs[$declared['name']];

			try {
				if ($this->evaluator->matches(expression: $expression, value: $value, type: $declared['type']) === false) {
					return false;
				}
			} catch (DecisionEvaluationException $e) {
				throw new DecisionEvaluationException(
					errorCode: $e->getErrorCode(),
					details: array_merge($e->getDetails(), ['ruleId' => ($rule['id'] ?? $ruleIndex), 'input' => $declared['name']]),
				);
			}
		}

		return true;
	}//end ruleMatches()

	/**
	 * Apply the hit policy to the set of matched rules and build the outputs.
	 *
	 * @param string $hitPolicy UNIQUE|FIRST|COLLECT.
	 * @param array<int, array<string, mixed>> $matchedRules Rules that matched, in declaration order.
	 * @param array<int, array{name: string, type: string}> $declaredOutputs Declared outputs, positionally aligned.
	 *
	 * @return array{outputs: array<string, mixed>, matchedRuleIds: array<int, string>, hitPolicy: string}
	 *
	 * @throws DecisionEvaluationException `no_rule_matched`, `hit_policy_violation`.
	 */
	private function applyHitPolicy(string $hitPolicy, array $matchedRules, array $declaredOutputs): array {
		$matchedIds = [];
		foreach ($matchedRules as $position => $rule) {
			$matchedIds[] = (string)($rule['id'] ?? $position);
		}

		if ($hitPolicy === 'COLLECT') {
			$outputs = [];
			foreach ($declaredOutputs as $position => $declared) {
				$outputs[$declared['name']] = array_map(
					static fn (array $rule): mixed => ($rule['outputEntries'][$position] ?? null),
					$matchedRules,
				);
			}

			return ['outputs' => $outputs, 'matchedRuleIds' => $matchedIds, 'hitPolicy' => $hitPolicy];
		}

		if (count($matchedRules) === 0) {
			throw new DecisionEvaluationException(errorCode: 'no_rule_matched');
		}

		if ($hitPolicy === 'UNIQUE' && count($matchedRules) > 1) {
			throw new DecisionEvaluationException(errorCode: 'hit_policy_violation', details: ['matchedRuleIds' => $matchedIds]);
		}

		// UNIQUE (exactly one) or FIRST (first in declaration order).
		$winner = $matchedRules[0];
		$outputs = [];
		foreach ($declaredOutputs as $position => $declared) {
			$outputs[$declared['name']] = ($winner['outputEntries'][$position] ?? null);
		}

		$winnerId = (string)($winner['id'] ?? 0);

		return ['outputs' => $outputs, 'matchedRuleIds' => [$winnerId], 'hitPolicy' => $hitPolicy];
	}//end applyHitPolicy()

	/**
	 * Normalise a decision table's `inputs`/`outputs` array into a clean
	 * positional list of `{name, type}`.
	 *
	 * @param array<int, mixed> $fields Raw `inputs`/`outputs` array.
	 *
	 * @return array<int, array{name: string, type: string}>
	 */
	private static function normaliseFields(array $fields): array {
		$result = [];
		foreach ($fields as $field) {
			if (is_array($field) === false) {
				continue;
			}

			$name = (string)($field['name'] ?? '');
			if ($name === '') {
				continue;
			}

			$type = (string)($field['type'] ?? 'string');
			if (in_array($type, ExpressionEvaluator::VALID_TYPES, true) === false) {
				$type = 'string';
			}

			$result[] = ['name' => $name, 'type' => $type];
		}

		return $result;
	}//end normaliseFields()
}//end class
