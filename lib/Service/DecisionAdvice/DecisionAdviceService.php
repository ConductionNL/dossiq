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
use OCA\Dossiq\Kernel\Contracts\DecisionAdviceServiceInterface;
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
class DecisionAdviceService implements DecisionAdviceServiceInterface {

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
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function recommend(string $caseId, array $inputs, string $userId): array {
		$reference = $this->tableOf(caseId: $caseId);
		$table = $this->table(reference: $reference);
		$values = (new AdviceTable(table: $table))->declaredValues(inputs: $inputs);
		$outputs = $this->evaluate(reference: $reference, values: $values);

		return $this->saveRecommendation(
			asked: ['caseId' => $caseId, 'reference' => $reference, 'table' => $table, 'userId' => $userId],
			evaluated: ['inputs' => $values, 'outputs' => $outputs]
		);
	}//end recommend()

	/**
	 * Store the advice the decision table recommends.
	 *
	 * @param array<string, mixed> $asked     The case, the decision table's reference and the table, and who asks.
	 * @param array<string, mixed> $evaluated The declared inputs and the table's outputs.
	 *
	 * @return array<string, mixed> The stored advice.
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function saveRecommendation(array $asked, array $evaluated): array {
		$outputs = $evaluated['outputs'];

		return $this->store->save(
			advice: [
				'case' => $asked['caseId'],
				'decisionTable' => $asked['reference'],
				'inputs' => $evaluated['inputs'],
				'outputs' => $outputs,
				'recommendation' => (new AdviceTable(table: $asked['table']))->recommendation(outputs: $outputs),
				'recommendedBy' => $asked['userId'],
				'recommendedAt' => $this->now(),
			]
		);
	}//end saveRecommendation()

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
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function form(string $caseId): array {
		$reference = $this->namedTableOf(caseId: $caseId);
		if ($reference === '') {
			return ['available' => false, 'decisionTable' => '', 'inputs' => [], 'outcomes' => []];
		}

		$table = new AdviceTable(table: $this->table(reference: $reference));
		$inputs = $table->offeredInputs();

		return [
			'available' => ($inputs !== []),
			'decisionTable' => $reference,
			'inputs' => $inputs,
			'outcomes' => $table->outcomes(),
		];
	}//end form()

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

		return $this->saveDecision(advice: $advice, decision: ['outcome' => $outcome, 'reason' => $reason, 'userId' => $userId]);
	}//end decide()

	/**
	 * Record the chosen outcome on the advice; a deviation from the recommendation needs a reason.
	 *
	 * @param array<string, mixed>                                $advice   The stored advice.
	 * @param array{outcome: string, reason: string, userId: string} $decision The chosen outcome (trimmed), why it
	 *                                                                     deviates, and who decides.
	 *
	 * @return array<string, mixed> The saved advice.
	 *
	 * @throws DecisionAdviceRefusal When a deviation carries no reason.
	 */
	private function saveDecision(array $advice, array $decision): array {
		$outcome = $decision['outcome'];
		$accepted = ($outcome === (string)($advice['recommendation'] ?? ''));
		$reason = trim($decision['reason']);
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
					'decidedBy' => $decision['userId'],
					'decidedAt' => $this->now(),
				]
			)
		);
	}//end saveDecision()

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
	 * Now, as stored.
	 *
	 * @return string The moment.
	 */
	private function now(): string {
		return $this->time->getDateTime()->format(DateTimeInterface::ATOM);
	}//end now()
}//end class
