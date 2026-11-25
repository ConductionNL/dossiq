<?php

/**
 * A stored decision table, read for advice on a case.
 *
 * What the advice reads off the decision table a case type names, without a
 * store: the inputs it declares with the values its rules use, the outcomes a
 * handler can choose, the entered value of every declared input, and the
 * recommendation in what the table answered.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Dossiq\Service\DecisionAdvice
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\DecisionAdvice;

use OCA\Dossiq\Service\Support\ReadsLooseValues;
use OCP\AppFramework\Http;

/**
 * Reads one stored decision table: its inputs, its outcomes and its answer.
 *
 * @spec openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class AdviceTable {

	use ReadsLooseValues {
		looseList as private listOf;
	}

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $table The stored decision table.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly array $table,
	) {
	}//end __construct()

	/**
	 * The inputs the table declares, each with its type and the values its rules use.
	 *
	 * @return list<array{name: string, type: string, options: list<string>}>
	 *
	 * @spec openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function offeredInputs(): array {
		$rules = $this->listOf(value: ($this->table['rules'] ?? null));
		$inputs = [];
		$index = 0;
		foreach ($this->listOf(value: ($this->table['inputs'] ?? null)) as $definition) {
			if (is_array($definition) === false) {
				continue;
			}

			$name = trim((string)($definition['name'] ?? ''));
			if ($name !== '') {
				$inputs[] = [
					'name' => $name,
					'type' => (string)($definition['type'] ?? 'string'),
					'options' => $this->entries(rules: $rules, column: ['key' => 'inputEntries', 'index' => $index]),
				];
			}

			$index++;
		}

		return $inputs;
	}//end offeredInputs()

	/**
	 * The outcomes a handler can choose: the values the rules give the first output.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function outcomes(): array {
		return $this->entries(
			rules: $this->listOf(value: ($this->table['rules'] ?? null)),
			column: ['key' => 'outputEntries', 'index' => 0]
		);
	}//end outcomes()

	/**
	 * The distinct literal values one column of the rules uses, in rule order.
	 * A dash or an empty entry matches anything and is no option.
	 *
	 * @param array                          $rules  The table's rules.
	 * @param array{key: string, index: int} $column `inputEntries` or `outputEntries`, and the column.
	 *
	 * @return list<string>
	 */
	private function entries(array $rules, array $column): array {
		['key' => $key, 'index' => $index] = $column;
		$values = [];
		foreach ($rules as $rule) {
			$entry = null;
			if (is_array($rule) === true) {
				$entry = ($this->listOf(value: ($rule[$key] ?? null))[$index] ?? null);
			}

			if (is_scalar($entry) === false) {
				continue;
			}

			$value = trim((string)$entry);
			if (strlen($value) >= 2 && $value[0] === '"' && substr($value, -1) === '"') {
				$value = substr($value, 1, -1);
			}

			if ($value === '' || $value === '-' || in_array($value, $values, true) === true) {
				continue;
			}

			$values[] = $value;
		}

		return $values;
	}//end entries()

	/**
	 * The entered value of every input the table declares, and only those.
	 *
	 * @param array<string, mixed> $inputs The values entered.
	 *
	 * @return array<string, string> The values by input name.
	 *
	 * @throws DecisionAdviceRefusal When the table declares no input, or an input has no value.
	 *
	 * @spec openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function declaredValues(array $inputs): array {
		$names = $this->names(definitions: ($this->table['inputs'] ?? []));
		if ($names === []) {
			throw new DecisionAdviceRefusal('De beslistabel declareert geen invoer', Http::STATUS_CONFLICT);
		}

		$values = [];
		$missing = [];
		foreach ($names as $name) {
			$value = ($inputs[$name] ?? null);
			if (is_scalar($value) === false || trim((string)$value) === '') {
				$missing[] = $name;
				continue;
			}

			$values[$name] = trim((string)$value);
		}

		if ($missing !== []) {
			throw new DecisionAdviceRefusal('Vul in: ' . implode(', ', $missing), Http::STATUS_BAD_REQUEST);
		}

		return $values;
	}//end declaredValues()

	/**
	 * The names of a table's input or output definitions, in declared order.
	 *
	 * @param mixed $definitions The definitions.
	 *
	 * @return array<int, string> The names.
	 */
	private function names(mixed $definitions): array {
		if (is_array($definitions) === false) {
			return [];
		}

		$names = [];
		foreach ($definitions as $definition) {
			if (is_array($definition) === false) {
				continue;
			}

			$name = trim((string)($definition['name'] ?? ''));
			if ($name !== '') {
				$names[] = $name;
			}
		}

		return $names;
	}//end names()

	/**
	 * The recommendation: the value of the table's first declared output.
	 *
	 * @param array<string, mixed> $outputs The outputs.
	 *
	 * @return string The recommendation.
	 *
	 * @throws DecisionAdviceRefusal When that output has no value.
	 *
	 * @spec openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function recommendation(array $outputs): string {
		$first = ($this->names(definitions: ($this->table['outputs'] ?? []))[0] ?? (string)array_key_first($outputs));
		$value = ($outputs[$first] ?? null);
		if (is_scalar($value) === false || trim((string)$value) === '') {
			throw new DecisionAdviceRefusal('De beslistabel geeft geen advies voor deze invoer', Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return (string)$value;
	}//end recommendation()
}//end class
