<?php

/**
 * Dossiq KCC routing on OpenRegister's shared decision-table evaluator.
 *
 * THE LINE DRAWN. Generic rule evaluation — cell matching, hit policies,
 * typed input coercion — is OpenRegister's job (`lib/Service/Dmn`, shipped by
 * openregister#3329); KCC domain semantics stay here. Concretely, dossiq
 * keeps: the condition dialect (keyword, regex, channel, customer_type,
 * time_of_day, day_of_week), the haystack built from subject + summary, the
 * KvK-number customer-type derivation, and the routing result vocabulary.
 * What leaves is the second rule-matching engine dossiq had grown inside
 * {@see RoutingEngine::evaluate()}.
 *
 * HOW THE MAPPING WORKS. The routing rules compile into one inline DMN table
 * with hit policy FIRST — enabled rules sorted ascending by priority, first
 * full match wins, exactly the legacy contract. Equality-shaped conditions
 * (channel, customer_type, day_of_week) become string columns with
 * quoted-literal cells; time_of_day windows become one `minutesOfDay` number
 * column with comparison or range cells; keyword and regex conditions become
 * boolean columns whose values dossiq derives from the contact moment — the
 * humaniq#289 `derive` pattern, because substring and regex matching are the
 * KCC dialect's own and deliberately not part of the shared unary-test
 * grammar. A rule whose conditions cannot be expressed (two different
 * channels, a malformed time window, an unknown type, no conditions at all)
 * could never match under the legacy engine either, and is left out of the
 * compiled table.
 *
 * 🔴 IT FAILS CLOSED. Without OpenRegister's evaluator this class refuses
 * loudly instead of falling back to a private matcher — a second engine kept
 * "just in case" is how the fleet got here. The legacy
 * {@see RoutingEngine::evaluate()} remains only as the parity oracle during
 * the staged retirement (see the change's tasks), never as a runtime
 * fallback.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Kcc
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
 * @spec openspec/specs/kcc-routing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Kcc;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Dmn\DecisionEvaluationException;
use OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator;
use RuntimeException;

/**
 * Compiles KCC routing rules into a shared-evaluator decision table and runs it.
 *
 * @spec openspec/specs/kcc-routing/spec.md
 */
class RoutingTableEvaluator {

	/**
	 * The equality-shaped condition types and the column each compiles to.
	 *
	 * @var array<string, string>
	 */
	private const EQUALITY_COLUMNS = [
		'channel' => 'channel',
		'customer_type' => 'customerType',
		'day_of_week' => 'dayOfWeek',
	];

	/**
	 * Constructor.
	 *
	 * @param DecisionTableEvaluator|null $engine The shared evaluator. Injectable
	 *                                            for tests; when null, the pure
	 *                                            engine is constructed directly —
	 *                                            openregister#3329 keeps it
	 *                                            dependency-free for exactly this
	 *                                            non-flow consumer seam.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ?DecisionTableEvaluator $engine = null,
	) {
	}//end __construct()

	/**
	 * Evaluate the routing rules for a contact moment through the shared engine.
	 *
	 * Same contract as the legacy {@see RoutingEngine::evaluate()}: the first
	 * enabled rule (ascending priority) whose conditions all match wins, and
	 * null means no rule matched.
	 *
	 * @param array<int, array<string, mixed>> $rules Routing rules.
	 * @param array<string, mixed> $contactMoment The contact moment.
	 * @param \DateTimeImmutable|null $now Reference time (for time-of-day rules).
	 *
	 * @return array<string, mixed>|null The routing result, or null when unmatched.
	 *
	 * @throws RuntimeException When OpenRegister's evaluator is unavailable.
	 * @throws DecisionEvaluationException When the compiled table cannot be
	 *                                     evaluated — a compiler defect, which
	 *                                     must surface loudly rather than
	 *                                     read as "route to nobody".
	 *
	 * @spec openspec/specs/kcc-routing/spec.md
	 */
	public function route(array $rules, array $contactMoment, ?DateTimeImmutable $now = null): ?array {
		$now = ($now ?? new DateTimeImmutable());

		$compiled = $this->compile(rules: $rules);
		if ($compiled['rules'] === []) {
			// Nothing expressible could ever match — the legacy engine's null.
			return null;
		}

		$table = $this->decisionTable(compiled: $compiled);
		$inputs = $this->deriveInputs(columns: $compiled['columns'], contactMoment: $contactMoment, now: $now);

		try {
			$result = $this->engine()->evaluate(decisionTable: $table, inputs: $inputs);
		} catch (DecisionEvaluationException $e) {
			if ($e->getErrorCode() === 'no_rule_matched') {
				return null;
			}

			throw $e;
		}

		return [
			'rule' => ($result['outputs']['rule'] ?? ''),
			'assignedDomain' => ($result['outputs']['assignedDomain'] ?? ''),
			'assignedTeam' => ($result['outputs']['assignedTeam'] ?? ''),
			'escalationTeam' => ($result['outputs']['escalationTeam'] ?? ''),
		];
	}//end route()

	/**
	 * The inline FIRST-hit decision table of the compiled rules.
	 *
	 * @param array{columns: array<int, array<string, mixed>>, rules: array<int, array<string, mixed>>} $compiled The compiled rules.
	 *
	 * @return array<string, mixed>
	 */
	private function decisionTable(array $compiled): array {
		return [
			'hitPolicy' => 'FIRST',
			'inputs' => array_map(
				static fn (array $column): array => ['name' => $column['name'], 'type' => $column['type']],
				$compiled['columns']
			),
			'outputs' => [
				['name' => 'rule', 'type' => 'string'],
				['name' => 'assignedDomain', 'type' => 'string'],
				['name' => 'assignedTeam', 'type' => 'string'],
				['name' => 'escalationTeam', 'type' => 'string'],
			],
			'rules' => $compiled['rules'],
		];
	}//end decisionTable()

	/**
	 * The shared evaluator, failing closed when OpenRegister is absent.
	 *
	 * @return DecisionTableEvaluator The engine.
	 *
	 * @throws RuntimeException When the evaluator class does not exist.
	 */
	private function engine(): DecisionTableEvaluator {
		if ($this->engine !== null) {
			return $this->engine;
		}

		if (class_exists(DecisionTableEvaluator::class) === false) {
			throw new RuntimeException(
				'OpenRegister\'s decision-table evaluator is not available; KCC routing is refused rather than guessed.'
			);
		}

		return new DecisionTableEvaluator();
	}//end engine()

	/**
	 * Compile the routing rules into positional columns and table rows.
	 *
	 * Enabled rules sort ascending by priority (PHP's stable sort keeps
	 * declaration order for ties, like the legacy engine), and each rule's
	 * conditions become cells against a shared column list. A rule that
	 * cannot be expressed is one the legacy engine could never match either,
	 * so it compiles to nothing rather than to a wrong answer.
	 *
	 * @param array<int, array<string, mixed>> $rules Routing rules.
	 *
	 * @return array{columns: array<int, array<string, mixed>>, rules: array<int, array<string, mixed>>}
	 */
	private function compile(array $rules): array {
		$columns = [];
		$rows = $this->ruleRows(enabled: RoutingRuleOrder::enabledByPriority(rules: $rules), columns: $columns);

		return ['columns' => $columns, 'rules' => $this->alignedRules(rows: $rows, names: array_column($columns, 'name'))];
	}//end compile()

	/**
	 * One row per rule that can match, its cells keyed by column name.
	 *
	 * @param array<int, array<string, mixed>> $enabled The enabled rules, by priority.
	 * @param array<int, array<string, mixed>> $columns The shared column list, grown as needed.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function ruleRows(array $enabled, array &$columns): array {
		$rows = [];
		foreach ($enabled as $index => $rule) {
			$cells = $this->compileRule(rule: $rule, columns: $columns);
			if ($cells === null) {
				continue;
			}

			$rows[] = [
				'id' => 'r' . $index,
				'cells' => $cells,
				'outputEntries' => [
					($rule['name'] ?? ''),
					($rule['assignedDomain'] ?? ''),
					($rule['assignedTeam'] ?? ''),
					($rule['escalationTeam'] ?? ''),
				],
			];
		}

		return $rows;
	}//end ruleRows()

	/**
	 * The table rules, each row's cells in column order.
	 *
	 * Cells were collected per column NAME while columns were still being
	 * discovered; align them positionally now that the list is closed.
	 *
	 * @param array<int, array<string, mixed>> $rows  The name-keyed rows.
	 * @param array<int, string>               $names The column names, in order.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function alignedRules(array $rows, array $names): array {
		$tableRules = [];
		foreach ($rows as $row) {
			$entries = [];
			foreach ($names as $name) {
				$entries[] = ($row['cells'][$name] ?? '-');
			}

			$tableRules[] = [
				'id' => $row['id'],
				'inputEntries' => $entries,
				'outputEntries' => $row['outputEntries'],
			];
		}

		return $tableRules;
	}//end alignedRules()

	/**
	 * Compile one rule's conditions into name-keyed cells.
	 *
	 * @param array<string, mixed> $rule The routing rule.
	 * @param array<int, array<string, mixed>> $columns The shared column list, grown as needed.
	 *
	 * @return array<string, string>|null The cells, or null when the rule can never match.
	 */
	private function compileRule(array $rule, array &$columns): ?array {
		$conditions = ($rule['matchConditions'] ?? []);
		if (is_array($conditions) === false || $conditions === []) {
			// The legacy engine refuses a rule with no conditions.
			return null;
		}

		$gathered = $this->gatheredConditions(conditions: $conditions);
		if ($gathered === null) {
			return null;
		}

		return $this->cellsOf(gathered: $gathered, columns: $columns);
	}//end compileRule()

	/**
	 * Every condition of a rule gathered, or null when one cannot be expressed.
	 *
	 * @param array<int, mixed> $conditions The rule's conditions.
	 *
	 * @return RuleConditions|null
	 */
	private function gatheredConditions(array $conditions): ?RuleConditions {
		$gathered = new RuleConditions();
		foreach ($conditions as $condition) {
			if (is_array($condition) === false) {
				return null;
			}

			if ($this->compileCondition(condition: $condition, gathered: $gathered) === false) {
				return null;
			}
		}

		return $gathered;
	}//end gatheredConditions()

	/**
	 * The rule's cells by column name, adding the columns the table does not have yet.
	 *
	 * @param RuleConditions                   $gathered The rule's gathered conditions.
	 * @param array<int, array<string, mixed>> $columns  The shared column list, grown as needed.
	 *
	 * @return array<string, string>
	 */
	private function cellsOf(RuleConditions $gathered, array &$columns): array {
		$cells = [];
		foreach ($gathered->equalities as $column => $value) {
			$this->ensureColumn(columns: $columns, name: $column, type: 'string', kind: $column);
			$cells[$column] = $this->quote(value: $value);
		}

		foreach ($gathered->booleans as $name => $spec) {
			$this->ensureColumn(columns: $columns, name: $name, type: 'boolean', kind: $spec['kind'], payload: $spec);
			$cells[$name] = 'true';
		}

		$timeCell = $this->timeCell(lowerBound: $gathered->lowerBound, upperBound: $gathered->upperBound);
		if ($timeCell !== null) {
			$this->ensureColumn(columns: $columns, name: 'minutesOfDay', type: 'number', kind: 'minutesOfDay');
			$cells['minutesOfDay'] = $timeCell;
		}

		return $cells;
	}//end cellsOf()

	/**
	 * Compile one condition into the rule's accumulators.
	 *
	 * Equality types record a literal cell; keyword and regex declare a
	 * boolean derive column; time_of_day folds into the rule's window. False
	 * means the condition — and with it the whole rule, since a rule is a
	 * conjunction — can never match: a contradictory equality, a malformed
	 * time value, or a condition type the legacy dialect never knew.
	 *
	 * @param array<string, mixed> $condition The condition.
	 * @param RuleConditions $gathered The rule's equality cells, boolean derive columns and time window so far.
	 *
	 * @return bool False when the rule can never match.
	 */
	private function compileCondition(array $condition, RuleConditions $gathered): bool {
		$type = (string)($condition['type'] ?? '');
		$value = (string)($condition['value'] ?? '');

		if (array_key_exists($type, self::EQUALITY_COLUMNS) === true) {
			$literal = $value;
			if ($type === 'day_of_week') {
				// The legacy comparison lower-cases both sides.
				$literal = strtolower($value);
			}

			return $this->recordEquality(equalities: $gathered->equalities, column: self::EQUALITY_COLUMNS[$type], value: $literal);
		}

		if ($type === 'keyword') {
			$gathered->booleans['keyword:' . strtolower($value)] = ['kind' => 'keyword', 'needle' => strtolower($value)];

			return true;
		}

		if ($type === 'regex') {
			$gathered->booleans['regex:' . $value] = ['kind' => 'regex', 'pattern' => $value];

			return true;
		}

		if ($type === 'time_of_day') {
			return $this->recordTimeWindow(value: $value, lowerBound: $gathered->lowerBound, upperBound: $gathered->upperBound);
		}

		// The legacy engine's default arm: an unknown type never matches.
		return false;
	}//end compileCondition()

	/**
	 * Record an equality constraint, refusing a contradictory second value.
	 *
	 * Two conditions demanding two DIFFERENT values of the same field can
	 * never both hold — the legacy engine evaluated them as `false`, and one
	 * table cell can carry only one literal, so the rule compiles to nothing.
	 *
	 * @param array<string, string> $equalities The rule's equality cells so far.
	 * @param string $column The column name.
	 * @param string $value The demanded value.
	 *
	 * @return bool False when the constraint is contradictory.
	 */
	private function recordEquality(array &$equalities, string $column, string $value): bool {
		if (array_key_exists($column, $equalities) === true) {
			return ($equalities[$column] === $value);
		}

		$equalities[$column] = $value;

		return true;
	}//end recordEquality()

	/**
	 * Fold one `after_HH:MM` / `before_HH:MM` condition into the rule's window.
	 *
	 * Several time conditions intersect, like the legacy conjunction did:
	 * every `after` raises the lower bound, every `before` lowers the upper.
	 *
	 * @param string $value The condition value.
	 * @param int|null $lowerBound The inclusive lower bound, in minutes.
	 * @param int|null $upperBound The exclusive upper bound, in minutes.
	 *
	 * @return bool False when the value is not a time-of-day expression.
	 */
	private function recordTimeWindow(string $value, ?int &$lowerBound, ?int &$upperBound): bool {
		if (preg_match('/^(after|before)_(\d{1,2}):(\d{2})$/', $value, $matches) !== 1) {
			// The legacy engine's timeOfDayMatches() answers false for a
			// malformed window, so the rule can never match.
			return false;
		}

		$boundary = ((int)$matches[2] * 60) + (int)$matches[3];
		if ($matches[1] === 'after') {
			$lowerBound = max($boundary, ($lowerBound ?? $boundary));

			return true;
		}

		$upperBound = min($boundary, ($upperBound ?? $boundary));

		return true;
	}//end recordTimeWindow()

	/**
	 * The unary-test cell for a compiled time window.
	 *
	 * @param int|null $lowerBound The inclusive lower bound, in minutes.
	 * @param int|null $upperBound The exclusive upper bound, in minutes.
	 *
	 * @return string|null The cell, or null when the rule carries no window.
	 */
	private function timeCell(?int $lowerBound, ?int $upperBound): ?string {
		if ($lowerBound !== null && $upperBound !== null) {
			// An impossible window ([1020..540)) simply never matches — the
			// same answer the legacy conjunction gave it.
			return '[' . $lowerBound . '..' . $upperBound . ')';
		}

		if ($lowerBound !== null) {
			return '>= ' . $lowerBound;
		}

		if ($upperBound !== null) {
			return '< ' . $upperBound;
		}

		return null;
	}//end timeCell()

	/**
	 * Declare a column once, keeping its derivation spec.
	 *
	 * @param array<int, array<string, mixed>> $columns The column list.
	 * @param string $name The column name.
	 * @param string $type The column's DMN type.
	 * @param string $kind The derivation kind.
	 * @param array<string, mixed> $payload Extra derivation data (needle/pattern).
	 *
	 * @return void
	 */
	private function ensureColumn(array &$columns, string $name, string $type, string $kind, array $payload = []): void {
		foreach ($columns as $column) {
			if ($column['name'] === $name) {
				return;
			}
		}

		$columns[] = ['name' => $name, 'type' => $type, 'kind' => $kind, 'payload' => $payload];
	}//end ensureColumn()

	/**
	 * Derive the runtime value of every declared column from the contact moment.
	 *
	 * This is the KCC dialect: the haystack, the KvK derivation and the
	 * keyword/regex predicates live HERE, and the shared engine only ever
	 * sees their typed results — the humaniq#289 `derive` seam.
	 *
	 * @param array<int, array<string, mixed>> $columns The compiled columns.
	 * @param array<string, mixed> $contactMoment The contact moment.
	 * @param \DateTimeImmutable $now Reference time.
	 *
	 * @return array<string, mixed> Values keyed by column name.
	 */
	private function deriveInputs(array $columns, array $contactMoment, DateTimeImmutable $now): array {
		$haystack = strtolower(
			trim(
				((string)($contactMoment['subject'] ?? '')) . ' ' . ((string)($contactMoment['summary'] ?? ''))
			)
		);

		$inputs = [];
		foreach ($columns as $column) {
			$inputs[$column['name']] = match ($column['kind']) {
				'channel' => (string)($contactMoment['channel'] ?? ''),
				'customerType' => RoutingRuleOrder::customerType(contactMoment: $contactMoment),
				'dayOfWeek' => strtolower($now->format('l')),
				'minutesOfDay' => (((int)$now->format('G')) * 60) + ((int)$now->format('i')),
				'keyword' => str_contains($haystack, (string)$column['payload']['needle']),
				'regex' => $this->regexMatches(pattern: (string)$column['payload']['pattern'], haystack: $haystack),
				default => throw new RuntimeException('Unknown routing column kind: ' . (string)$column['kind']),
			};
		}

		return $inputs;
	}//end deriveInputs()

	/**
	 * The regex predicate, verbatim from the legacy engine.
	 *
	 * Anchor-free, case-insensitive; delimiters are added here so rule
	 * authors never inject raw delimiters.
	 *
	 * @param string $pattern The raw rule pattern.
	 * @param string $haystack Lower-cased subject + summary.
	 *
	 * @return bool True when the pattern matches.
	 */
	private function regexMatches(string $pattern, string $haystack): bool {
		$delimited = '/' . str_replace('/', '\/', $pattern) . '/i';

		return (preg_match($delimited, $haystack) === 1);
	}//end regexMatches()

	/**
	 * Quote a literal for a rule cell, escaping embedded quotes.
	 *
	 * A quoted literal survives values the bare grammar would misread — a
	 * channel named `-` would otherwise be a wildcard, and one starting with
	 * `<` an operator.
	 *
	 * @param string $value The literal.
	 *
	 * @return string The quoted cell.
	 */
	private function quote(string $value): string {
		return '"' . str_replace('"', '\\"', $value) . '"';
	}//end quote()
}//end class
