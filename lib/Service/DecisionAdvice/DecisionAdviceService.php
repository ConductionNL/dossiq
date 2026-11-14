<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Service
 * @package   OCA\Dossiq\Service\DecisionAdvice
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md#requirement-a-handler-asks-the-case-types-decision-table-for-advice-on-the-case-req-lhs-case-01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\DecisionAdvice;

use DateTimeInterface;
use OCA\Dossiq\Service\Deelzaak\CaseObjectReader;
use OCA\Dossiq\Service\Dmn\StoredDecisionTables;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use Throwable;

/**
 * Asks the decision table a case type names for advice on a case, and records
 * the handler's choice.
 *
 * Generic: the inputs it asks for are the ones the table declares, and the
 * recommendation is the table's first declared output. Which procedure uses it
 * is configuration on the case type (`adviceDecisionTable`), never code here.
 *
 * @spec openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md#requirement-a-handler-asks-the-case-types-decision-table-for-advice-on-the-case-req-lhs-case-01
 */
class DecisionAdviceService {

	/**
	 * The refusal of a deviation without a reason, as the handler reads it.
	 */
	public const REASON_REQUIRED = 'Geef een reden voor de afwijking';

	/**
	 * Constructor.
	 *
	 * @param CaseObjectReader $cases Reads the case and its case type.
	 * @param StoredDecisionTables $tables OpenRegister's stored decision tables.
	 * @param DecisionAdviceStore $store Reads and writes the advice in OpenRegister.
	 * @param ITimeFactory $time The clock.
	 */
	public function __construct(
		private readonly CaseObjectReader $cases,
		private readonly StoredDecisionTables $tables,
		private readonly DecisionAdviceStore $store,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * Ask the case type's decision table for advice and store it on the case.
	 *
	 * @param string $caseId The case.
	 * @param array<string, mixed> $inputs The values entered, by input name.
	 * @param string $userId Who asks.
	 *
	 * @return array<string, mixed> The stored advice.
	 *
	 * @throws DecisionAdviceRefusal When the case, its table or an input is missing, or the table gives no answer.
	 *
	 * @spec openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md#requirement-a-handler-asks-the-case-types-decision-table-for-advice-on-the-case-req-lhs-case-01
	 */
	public function recommend(string $caseId, array $inputs, string $userId): array {
		$reference = $this->tableOf(caseId: $caseId);
		$table = $this->table(reference: $reference);
		$values = $this->declaredInputs(table: $table, inputs: $inputs);
		$outputs = $this->evaluate(reference: $reference, values: $values);

		return $this->store->save(
			advice: [
				'case' => $caseId,
				'decisionTable' => $reference,
				'inputs' => $values,
				'outputs' => $outputs,
				'recommendation' => $this->recommendationOf(table: $table, outputs: $outputs),
				'recommendedBy' => $userId,
				'recommendedAt' => $this->now(),
			]
		);
	}//end recommend()

	/**
	 * Read stored advice.
	 *
	 * @param string $adviceId The advice.
	 *
	 * @return array<string, mixed> The advice.
	 *
	 * @throws DecisionAdviceRefusal When it cannot be found.
	 *
	 * @spec openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md#requirement-a-handler-asks-the-case-types-decision-table-for-advice-on-the-case-req-lhs-case-01
	 */
	public function find(string $adviceId): array {
		return $this->store->find(adviceId: $adviceId);
	}//end find()

	/**
	 * What the advice dialog asks on a case: the table the case type names, the
	 * inputs it declares with the values its rules use, and the outcomes a
	 * handler can choose. A case type that names no table answers unavailable,
	 * so the case page hides the action instead of offering one that refuses.
	 *
	 * @param string $caseId The case.
	 *
	 * @return array{available: bool, decisionTable: string, inputs: list<array{name: string, type: string, options: list<string>}>,
	 *     outcomes: list<string>}
	 *
	 * @throws DecisionAdviceRefusal When the case is unknown or the table cannot be read.
	 *
	 * @spec openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md
	 */
	public function form(string $caseId): array {
		$reference = $this->namedTableOf(caseId: $caseId);
		if ($reference === '') {
			return ['available' => false, 'decisionTable' => '', 'inputs' => [], 'outcomes' => []];
		}

		$table = $this->table(reference: $reference);
		$rules = $this->listOf(value: ($table['rules'] ?? null));
		$inputs = [];
		$index = 0;
		foreach ($this->listOf(value: ($table['inputs'] ?? null)) as $definition) {
			if (is_array($definition) === false) {
				continue;
			}

			$name = trim((string)($definition['name'] ?? ''));
			if ($name !== '') {
				$inputs[] = [
					'name' => $name,
					'type' => (string)($definition['type'] ?? 'string'),
					'options' => $this->entries(rules: $rules, key: 'inputEntries', index: $index),
				];
			}

			$index++;
		}

		return [
			'available' => ($inputs !== []),
			'decisionTable' => $reference,
			'inputs' => $inputs,
			'outcomes' => $this->entries(rules: $rules, key: 'outputEntries', index: 0),
		];
	}//end form()

	/**
	 * The distinct literal values one column of the rules uses, in rule order.
	 * A dash or an empty entry matches anything and is no option.
	 *
	 * @param array  $rules The table's rules.
	 * @param string $key   `inputEntries` or `outputEntries`.
	 * @param int    $index The column.
	 *
	 * @return list<string>
	 */
	private function entries(array $rules, string $key, int $index): array {
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
	 * A value as an array, or empty when it is none.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array
	 */
	private function listOf(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return $value;
	}//end listOf()

	/**
	 * Record the handler's choice: the recommendation accepted, or another
	 * outcome with a reason.
	 *
	 * @param array<string, mixed> $advice The stored advice, as {@see find()} read it.
	 * @param string $outcome The chosen outcome.
	 * @param string $reason Why it deviates from the recommendation.
	 * @param string $userId Who decides.
	 *
	 * @return array<string, mixed> The stored advice with the choice.
	 *
	 * @throws DecisionAdviceRefusal When the outcome is missing, a deviation has no reason, or the choice was already made.
	 *
	 * @spec openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md#requirement-a-handler-asks-the-case-types-decision-table-for-advice-on-the-case-req-lhs-case-01
	 */
	public function decide(array $advice, string $outcome, string $reason, string $userId): array {
		$outcome = trim($outcome);
		if ($outcome === '') {
			throw new DecisionAdviceRefusal('Kies een uitkomst', Http::STATUS_BAD_REQUEST);
		}

		if (trim((string)($advice['outcome'] ?? '')) !== '') {
			throw new DecisionAdviceRefusal('Over dit advies is al besloten', Http::STATUS_CONFLICT);
		}

		$accepted = ($outcome === (string)($advice['recommendation'] ?? ''));
		$reason = trim($reason);
		if ($accepted === false && $reason === '') {
			throw new DecisionAdviceRefusal(self::REASON_REQUIRED, Http::STATUS_BAD_REQUEST);
		}

		return $this->store->save(
			advice: array_merge(
				$advice,
				[
					'outcome' => $outcome,
					'accepted' => $accepted,
					'reason' => $reason,
					'decidedBy' => $userId,
					'decidedAt' => $this->now(),
				]
			)
		);
	}//end decide()

	/**
	 * The decision table the case's type names.
	 *
	 * @param string $caseId The case.
	 *
	 * @return string The table's id or key.
	 *
	 * @throws DecisionAdviceRefusal When the case is unknown, or its type names no table.
	 */
	private function tableOf(string $caseId): string {
		$reference = $this->namedTableOf(caseId: $caseId);
		if ($reference === '') {
			throw new DecisionAdviceRefusal('Het zaaktype van deze zaak noemt geen beslistabel voor advies', Http::STATUS_CONFLICT);
		}

		return $reference;
	}//end tableOf()

	/**
	 * The decision table the case's type names, empty when it names none.
	 *
	 * @param string $caseId The case.
	 *
	 * @return string
	 *
	 * @throws DecisionAdviceRefusal When the case is unknown.
	 */
	private function namedTableOf(string $caseId): string {
		$case = $this->cases->fetchCaseById(caseUuid: $caseId);
		if ($case === null) {
			throw new DecisionAdviceRefusal('Deze zaak bestaat niet', Http::STATUS_NOT_FOUND);
		}

		$caseType = $this->cases->loadCaseType(caseTypeId: $this->reference(value: ($case['caseType'] ?? '')));

		return trim((string)($caseType['adviceDecisionTable'] ?? ''));
	}//end namedTableOf()

	/**
	 * An id out of a relation that is a string or an expanded object.
	 *
	 * @param mixed $value The relation.
	 *
	 * @return string The id, or ''.
	 */
	private function reference(mixed $value): string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? ($value['uuid'] ?? ''));
		}

		if (is_string($value) === false) {
			return '';
		}

		return $value;
	}//end reference()

	/**
	 * The stored table.
	 *
	 * @param string $reference The table's id or key.
	 *
	 * @return array<string, mixed> The table.
	 *
	 * @throws DecisionAdviceRefusal When OpenRegister or the table is not there.
	 */
	private function table(string $reference): array {
		try {
			$table = $this->tables->find(reference: $reference);
		} catch (Throwable) {
			throw new DecisionAdviceRefusal('De beslistabel "' . $reference . '" is niet gevonden', Http::STATUS_CONFLICT);
		}

		if ($table === null) {
			throw new DecisionAdviceRefusal('OpenRegister is niet beschikbaar', Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return $table;
	}//end table()

	/**
	 * The entered value of every input the table declares, and only those.
	 *
	 * @param array<string, mixed> $table The table.
	 * @param array<string, mixed> $inputs The values entered.
	 *
	 * @return array<string, string> The values by input name.
	 *
	 * @throws DecisionAdviceRefusal When the table declares no input, or an input has no value.
	 */
	private function declaredInputs(array $table, array $inputs): array {
		$names = $this->names(definitions: ($table['inputs'] ?? []));
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
	}//end declaredInputs()

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
	 * What the table answers for the values.
	 *
	 * @param string $reference The table's id or key.
	 * @param array<string, string> $values The values by input name.
	 *
	 * @return array<string, mixed> The outputs.
	 *
	 * @throws DecisionAdviceRefusal When the table gives no answer.
	 */
	private function evaluate(string $reference, array $values): array {
		try {
			$answer = $this->tables->evaluate(reference: $reference, inputs: $values);
		} catch (Throwable $e) {
			throw new DecisionAdviceRefusal('De beslistabel geeft geen advies voor deze invoer: ' . $e->getMessage(), Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		if ($answer === null) {
			throw new DecisionAdviceRefusal('OpenRegister is niet beschikbaar', Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$outputs = ($answer['outputs'] ?? null);
		if (is_array($outputs) === false || $outputs === []) {
			throw new DecisionAdviceRefusal('De beslistabel geeft geen advies voor deze invoer', Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return $outputs;
	}//end evaluate()

	/**
	 * The recommendation: the value of the table's first declared output.
	 *
	 * @param array<string, mixed> $table The table.
	 * @param array<string, mixed> $outputs The outputs.
	 *
	 * @return string The recommendation.
	 *
	 * @throws DecisionAdviceRefusal When that output has no value.
	 */
	private function recommendationOf(array $table, array $outputs): string {
		$first = ($this->names(definitions: ($table['outputs'] ?? []))[0] ?? (string)array_key_first($outputs));
		$value = ($outputs[$first] ?? null);
		if (is_scalar($value) === false || trim((string)$value) === '') {
			throw new DecisionAdviceRefusal('De beslistabel geeft geen advies voor deze invoer', Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return (string)$value;
	}//end recommendationOf()

	/**
	 * Now, as stored.
	 *
	 * @return string The moment.
	 */
	private function now(): string {
		return $this->time->getDateTime()->format(DateTimeInterface::ATOM);
	}//end now()
}//end class
