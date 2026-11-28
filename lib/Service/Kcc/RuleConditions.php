<?php

/**
 * The conditions of one KCC routing rule, gathered for its decision-table row
 *
 * The equality columns with their values, the keyword and regex columns,
 * and the time-of-day window narrowed by every time condition of the rule:
 * what RoutingTableEvaluator collects from a rule before it writes the
 * rule's cells.
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
 * @link https://conduction.nl
 *
 * @spec openspec/specs/kcc-routing/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Kcc;

/**
 * The gathered conditions of one rule; it never leaves the evaluator that built it.
 *
 * @spec openspec/specs/kcc-routing/spec.md
 */
final class RuleConditions {

	/**
	 * The value per equality column.
	 *
	 * @var array<string, string>
	 */
	public array $equalities = [];

	/**
	 * The keyword and regex columns, by name, with what they test.
	 *
	 * @var array<string, array<string, string>>
	 */
	public array $booleans = [];

	/**
	 * The earliest minute of the day the rule matches, or null.
	 *
	 * @var integer|null
	 */
	public ?int $lowerBound = null;

	/**
	 * The latest minute of the day the rule matches, or null.
	 *
	 * @var integer|null
	 */
	public ?int $upperBound = null;

	/**
	 * Record an equality constraint, refusing a contradictory second value.
	 *
	 * Two conditions demanding two DIFFERENT values of the same field can
	 * never both hold — the legacy engine evaluated them as `false`, and one
	 * table cell can carry only one literal, so the rule compiles to nothing.
	 *
	 * @param string $column The column name.
	 * @param string $value  The demanded value.
	 *
	 * @return bool False when the constraint is contradictory.
	 *
	 * @spec openspec/specs/kcc-routing/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function requireEqual(string $column, string $value): bool {
		if (array_key_exists($column, $this->equalities) === true) {
			return ($this->equalities[$column] === $value);
		}

		$this->equalities[$column] = $value;

		return true;
	}//end requireEqual()

	/**
	 * Fold one `after_HH:MM` / `before_HH:MM` condition into the rule's window.
	 *
	 * Several time conditions intersect, like the legacy conjunction did:
	 * every `after` raises the lower bound, every `before` lowers the upper.
	 *
	 * @param string $value The condition value.
	 *
	 * @return bool False when the value is not a time-of-day expression.
	 *
	 * @spec openspec/specs/kcc-routing/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function narrowTime(string $value): bool {
		if (preg_match('/^(after|before)_(\d{1,2}):(\d{2})$/', $value, $matches) !== 1) {
			// The legacy engine's timeOfDayMatches() answers false for a
			// malformed window, so the rule can never match.
			return false;
		}

		$boundary = ((int)$matches[2] * 60) + (int)$matches[3];
		if ($matches[1] === 'after') {
			$this->lowerBound = max($boundary, ($this->lowerBound ?? $boundary));

			return true;
		}

		$this->upperBound = min($boundary, ($this->upperBound ?? $boundary));

		return true;
	}//end narrowTime()

	/**
	 * The unary-test cell for the rule's time window.
	 *
	 * @return string|null The cell, or null when the rule carries no window.
	 *
	 * @spec openspec/specs/kcc-routing/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function timeCell(): ?string {
		if ($this->lowerBound !== null && $this->upperBound !== null) {
			// An impossible window ([1020..540)) simply never matches — the
			// same answer the legacy conjunction gave it.
			return '[' . $this->lowerBound . '..' . $this->upperBound . ')';
		}

		if ($this->lowerBound !== null) {
			return '>= ' . $this->lowerBound;
		}

		if ($this->upperBound !== null) {
			return '< ' . $this->upperBound;
		}

		return null;
	}//end timeCell()
}//end class
