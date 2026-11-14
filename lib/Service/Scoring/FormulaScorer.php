<?php

/**
 * Dossiq formula scorer: applies a stored scoring formula to a bid's values.
 *
 * Scoring formulas are data (REQ-EVA-003): a `scoringFormula` record carries an
 * arithmetic expression over named values, and this class evaluates it. It
 * knows no formula by name, so a new formula is a new record, never new code.
 *
 * OpenRegister's stored decision tables (decision 186) were checked first and
 * cannot hold these formulas: a rule cell is a unary test on one input
 * (comparison, range, set membership) and an output cell is a literal, so a
 * score computed from the bid's price and the lowest price has nowhere to be
 * written. See tasks.md 2.1.
 *
 * The grammar is closed and bounded: numbers, named values, `+ - * /`, unary
 * minus, parentheses, and the functions `min`, `max`, `abs` and `round`. It is
 * parsed by recursive descent; there is no eval() and no dynamic call.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Scoring
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#2-1
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Scoring;

use InvalidArgumentException;

/**
 * Evaluates a scoring formula's expression against named values.
 *
 * @spec openspec/specs/dossiq-procurement-evaluation-award/spec.md#requirement-req-eva-003-scoring-formulas-shall-be-data-not-code
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The total is the sum of small private steps; each method stays under the method thresholds.
 */
class FormulaScorer {

	/**
	 * The longest expression accepted.
	 */
	public const MAX_LENGTH = 500;

	/**
	 * The deepest nesting of parentheses and calls accepted.
	 */
	private const MAX_DEPTH = 32;

	/**
	 * The functions an expression may call, with their least and most argument counts.
	 */
	private const FUNCTIONS = [
		'min' => [1, 32],
		'max' => [1, 32],
		'abs' => [1, 1],
		'round' => [1, 2],
	];

	/**
	 * The tokens of the expression being evaluated.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $tokens = [];

	/**
	 * The position of the next token.
	 *
	 * @var int
	 */
	private int $position = 0;

	/**
	 * The values the expression may name.
	 *
	 * @var array<string, float>
	 */
	private array $values = [];

	/**
	 * The current nesting depth.
	 *
	 * @var int
	 */
	private int $depth = 0;

	/**
	 * The score a formula record gives for these values.
	 *
	 * @param array<string, mixed> $formula The `scoringFormula` record; its `expression` is evaluated.
	 * @param array<string, mixed> $values  The named values, e.g. `price`, `lowestPrice`, `maxPoints`.
	 *
	 * @return float
	 *
	 * @throws InvalidArgumentException When the formula has no expression, the expression is malformed,
	 *                                  names a value that is not given, or divides by zero.
	 *
	 * @spec openspec/specs/dossiq-procurement-evaluation-award/spec.md#requirement-req-eva-003-scoring-formulas-shall-be-data-not-code
	 */
	public function score(array $formula, array $values): float {
		$expression = ($formula['expression'] ?? null);
		if (is_string($expression) === false || trim($expression) === '') {
			throw new InvalidArgumentException('scoring_formula_without_expression');
		}

		return $this->evaluate(expression: $expression, values: $values);
	}//end score()

	/**
	 * Evaluate an expression against named values.
	 *
	 * @param string               $expression The expression.
	 * @param array<string, mixed> $values     The named values; only numbers are usable.
	 *
	 * @return float
	 *
	 * @throws InvalidArgumentException On a malformed expression, a missing value or a division by zero.
	 *
	 * @spec openspec/specs/dossiq-procurement-evaluation-award/spec.md#requirement-req-eva-003-scoring-formulas-shall-be-data-not-code
	 */
	public function evaluate(string $expression, array $values): float {
		if (strlen($expression) > self::MAX_LENGTH) {
			throw new InvalidArgumentException('scoring_formula_too_long');
		}

		$this->tokens = self::tokenise(expression: $expression);
		$this->position = 0;
		$this->depth = 0;
		$this->values = [];
		foreach ($values as $name => $value) {
			if (is_string($name) === true && (is_int($value) === true || is_float($value) === true)) {
				$this->values[$name] = (float)$value;
			}
		}

		$result = $this->sum();
		if ($this->position !== count($this->tokens)) {
			throw new InvalidArgumentException('scoring_formula_malformed');
		}

		if (is_finite($result) === false) {
			throw new InvalidArgumentException('scoring_formula_not_finite');
		}

		return $result;
	}//end evaluate()

	/**
	 * Split an expression into tokens: `num`, `name` and `op`.
	 *
	 * @param string $expression The expression.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 *
	 * @throws InvalidArgumentException On a character outside the grammar.
	 */
	private static function tokenise(string $expression): array {
		$matched = preg_match_all('/\s*(?:(\d+(?:\.\d+)?)|([A-Za-z_][A-Za-z0-9_]*)|([-+*\/(),]))/A', $expression, $parts, PREG_SET_ORDER);
		$consumed = 0;
		$tokens = [];
		if ($matched === false || $matched === 0) {
			$parts = [];
		}

		foreach ($parts as $part) {
			$consumed += strlen($part[0]);
			if (($part[1] ?? '') !== '') {
				$tokens[] = ['num', $part[1]];
			} elseif (($part[2] ?? '') !== '') {
				$tokens[] = ['name', $part[2]];
			} else {
				$tokens[] = ['op', $part[3]];
			}
		}

		if (trim(substr($expression, $consumed)) !== '' || $tokens === []) {
			throw new InvalidArgumentException('scoring_formula_malformed');
		}

		return $tokens;
	}//end tokenise()

	/**
	 * A sum: terms joined by `+` and `-`.
	 *
	 * @return float
	 */
	private function sum(): float {
		$value = $this->product();
		while ($this->peekOp(op: '+') === true || $this->peekOp(op: '-') === true) {
			$op = $this->tokens[$this->position++][1];
			$right = $this->product();
			if ($op === '+') {
				$value += $right;
				continue;
			}

			$value -= $right;
		}

		return $value;
	}//end sum()

	/**
	 * A product: factors joined by `*` and `/`.
	 *
	 * @return float
	 *
	 * @throws InvalidArgumentException On a division by zero.
	 */
	private function product(): float {
		$value = $this->factor();
		while ($this->peekOp(op: '*') === true || $this->peekOp(op: '/') === true) {
			$op = $this->tokens[$this->position++][1];
			$right = $this->factor();
			if ($op === '/' && $right === 0.0) {
				throw new InvalidArgumentException('scoring_formula_division_by_zero');
			}

			if ($op === '*') {
				$value *= $right;
				continue;
			}

			$value /= $right;
		}

		return $value;
	}//end product()

	/**
	 * A factor: a number, a named value, a call, a parenthesised sum, or a negated factor.
	 *
	 * @return float
	 *
	 * @throws InvalidArgumentException On a malformed factor or a missing value.
	 */
	private function factor(): float {
		$token = $this->nextToken();
		if ($token[0] === 'num') {
			return (float)$token[1];
		}

		if ($token === ['op', '-']) {
			return -$this->nested(step: fn (): float => $this->factor());
		}

		if ($token === ['op', '(']) {
			return $this->parenthesised();
		}

		return $this->nameFactor(token: $token);
	}//end factor()

	/**
	 * Take the next token.
	 *
	 * @return array{0: string, 1: mixed} The token.
	 *
	 * @throws InvalidArgumentException When the formula ends here.
	 */
	private function nextToken(): array {
		$token = ($this->tokens[$this->position] ?? null);
		if ($token === null) {
			throw new InvalidArgumentException('scoring_formula_malformed');
		}

		$this->position++;
		return $token;
	}//end nextToken()

	/**
	 * A parenthesised sum, after its opening parenthesis.
	 *
	 * @return float
	 *
	 * @throws InvalidArgumentException On a malformed sum or a missing closing parenthesis.
	 */
	private function parenthesised(): float {
		$value = $this->nested(step: fn (): float => $this->sum());
		$this->expectOp(op: ')');
		return $value;
	}//end parenthesised()

	/**
	 * A factor that starts with a name: a call or a named value.
	 *
	 * @param array{0: string, 1: mixed} $token The token taken.
	 *
	 * @return float
	 *
	 * @throws InvalidArgumentException On a malformed factor or a missing value.
	 */
	private function nameFactor(array $token): float {
		if ($token[0] === 'name' && $this->peekOp(op: '(') === true) {
			return $this->call(name: $token[1]);
		}

		if ($token[0] === 'name') {
			if (array_key_exists($token[1], $this->values) === false) {
				throw new InvalidArgumentException('scoring_formula_missing_value: ' . $token[1]);
			}

			return $this->values[$token[1]];
		}

		throw new InvalidArgumentException('scoring_formula_malformed');
	}//end nameFactor()

	/**
	 * A call of one of the allowed functions.
	 *
	 * @param string $name The function name.
	 *
	 * @return float
	 *
	 * @throws InvalidArgumentException On an unknown function or a wrong number of arguments.
	 */
	private function call(string $name): float {
		if (isset(self::FUNCTIONS[$name]) === false) {
			throw new InvalidArgumentException('scoring_formula_unknown_function: ' . $name);
		}

		$this->expectOp(op: '(');
		$arguments = [$this->nested(step: fn (): float => $this->sum())];
		while ($this->peekOp(op: ',') === true) {
			$this->position++;
			$arguments[] = $this->nested(step: fn (): float => $this->sum());
		}

		$this->expectOp(op: ')');
		[$least, $most] = self::FUNCTIONS[$name];
		if (count($arguments) < $least || count($arguments) > $most) {
			throw new InvalidArgumentException('scoring_formula_wrong_arguments: ' . $name);
		}

		return match ($name) {
			'min' => min($arguments),
			'max' => max($arguments),
			'abs' => abs($arguments[0]),
			'round' => round($arguments[0], (int)($arguments[1] ?? 0)),
		};
	}//end call()

	/**
	 * Run a parse step one level deeper, refusing past the maximum depth.
	 *
	 * @param callable():float $step The step.
	 *
	 * @return float
	 *
	 * @throws InvalidArgumentException When nested too deeply.
	 */
	private function nested(callable $step): float {
		if (++$this->depth > self::MAX_DEPTH) {
			throw new InvalidArgumentException('scoring_formula_too_deep');
		}

		$value = $step();
		$this->depth--;

		return $value;
	}//end nested()

	/**
	 * Whether the next token is that operator.
	 *
	 * @param string $op The operator.
	 *
	 * @return bool
	 */
	private function peekOp(string $op): bool {
		return ($this->tokens[$this->position] ?? null) === ['op', $op];
	}//end peekOp()

	/**
	 * Consume that operator, or refuse.
	 *
	 * @param string $op The operator.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the next token is another.
	 */
	private function expectOp(string $op): void {
		if ($this->peekOp(op: $op) === false) {
			throw new InvalidArgumentException('scoring_formula_malformed');
		}

		$this->position++;
	}//end expectOp()
}//end class
