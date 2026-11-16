<?php

/**
 * Dossiq LHS matrix cells.
 *
 * The decision rules the cells of an LHS matrix become: one rule per cell,
 * keyed on its severity, behaviour and actor type, or none at all when a cell
 * sits off its axes.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Vth
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Vth;

/**
 * Matrix cells to decision rules; reads no store.
 *
 * @spec openspec/specs/lhs-decision-table/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class LhsMatrixCells {

	/**
	 * One rule per cell, or null when a cell is off its axes.
	 *
	 * @param array<int, mixed>                $cells The matrix cells.
	 * @param array<string, array<int, mixed>> $axes  The allowed values per axis.
	 *
	 * @return array<int, array<string, mixed>>|null The rules.
	 *
	 * @spec openspec/specs/lhs-decision-table/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function rules(array $cells, array $axes): ?array {
		$rules = [];
		foreach ($cells as $cell) {
			if ($this->isOnAxes(cell: $cell, axes: $axes) === false) {
				return null;
			}

			$rules[] = $this->ruleFor(cell: $cell);
		}

		return $rules;
	}//end rules()

	/**
	 * Whether every coordinate of a cell is a value its axis allows.
	 *
	 * @param mixed                            $cell The cell.
	 * @param array<string, array<int, mixed>> $axes The allowed values per axis.
	 *
	 * @return bool True when the cell is on its axes.
	 */
	private function isOnAxes(mixed $cell, array $axes): bool {
		foreach ($axes as $key => $allowed) {
			if (in_array(($cell[$key] ?? null), $allowed, true) === false) {
				return false;
			}
		}

		return true;
	}//end isOnAxes()

	/**
	 * The decision rule one cell becomes.
	 *
	 * @param array<string, mixed> $cell The cell, on its axes.
	 *
	 * @return array<string, mixed> The rule.
	 */
	private function ruleFor(array $cell): array {
		return [
			'id' => sprintf('%s:%s:%s', $cell['severity'], $cell['behaviour'], $cell['actorType']),
			'annotation' => (string)($cell['note'] ?? ''),
			'inputEntries' => [
				(string)$cell['severity'],
				(string)$cell['behaviour'],
				(string)$cell['actorType'],
			],
			'outputEntries' => [(string)($cell['intervention'] ?? '')],
		];
	}//end ruleFor()
}//end class
