<?php

/**
 * Dossiq formula tokens: splits a scoring formula's expression into tokens.
 *
 * Numbers, named values and the operators and punctuation of the closed
 * scoring grammar; anything else refuses the expression.
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
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Scoring;

use InvalidArgumentException;

/**
 * The tokeniser of the scoring grammar.
 *
 * @spec openspec/specs/dossiq-procurement-evaluation-award/spec.md#requirement-req-eva-003-scoring-formulas-shall-be-data-not-code
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class FormulaTokens {

	/**
	 * Split an expression into tokens: `num`, `name` and `op`.
	 *
	 * @param string $expression The expression.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 *
	 * @throws InvalidArgumentException On a character outside the grammar.
	 *
	 * @spec openspec/specs/dossiq-procurement-evaluation-award/spec.md#requirement-req-eva-003-scoring-formulas-shall-be-data-not-code
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function split(string $expression): array {
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
				continue;
			}

			if (($part[2] ?? '') !== '') {
				$tokens[] = ['name', $part[2]];
				continue;
			}

			$tokens[] = ['op', $part[3]];
		}

		if (trim(substr($expression, $consumed)) !== '' || $tokens === []) {
			throw new InvalidArgumentException('scoring_formula_malformed');
		}

		return $tokens;
	}//end split()
}//end class
