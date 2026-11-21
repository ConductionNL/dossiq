<?php

/**
 * A store answer read as the occurrences it holds.
 *
 * Split out of {@see \OCA\Dossiq\Service\Flow\PlannedSeriesLedger}, which keeps
 * the read itself. This class reads no store: it takes what `findAll()`
 * answered, paginated or not, with array or entity rows, and keeps the id and
 * title of every row that has an id.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Flow
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
 * @spec openspec/specs/workflow-definition-engine/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Flow;

/**
 * Reads a `findAll()` answer as `{id, title}` pairs.
 *
 * @spec openspec/specs/workflow-definition-engine/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class OccurrenceRows {

	/**
	 * The occurrences in a store answer.
	 *
	 * @param mixed $answer What `findAll()` answered: a list, or a page with `results`.
	 *
	 * @return array<int, array{id: string, title: string}> The occurrences.
	 *
	 * @spec openspec/specs/workflow-definition-engine/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function occurrences(mixed $answer): array {
		return $this->shape(rows: $this->resultsOf(rows: $answer));
	}//end occurrences()

	/**
	 * The rows of a findAll() answer, paginated or not.
	 *
	 * @param mixed $rows The answer.
	 *
	 * @return array<int, mixed> The rows.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function resultsOf(mixed $rows): array {
		if (is_array($rows) === true && isset($rows['results']) === true) {
			return (array)$rows['results'];
		}

		return (array)$rows;
	}//end resultsOf()

	/**
	 * The rows as `{id, title}` pairs, dropping anything with no id.
	 *
	 * @param array<int, mixed> $rows The raw rows.
	 *
	 * @return array<int, array{id: string, title: string}> The occurrences.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function shape(array $rows): array {
		$occurrences = [];
		foreach ($rows as $row) {
			$case = $this->asArray(row: $row);
			$id = trim((string)($case['id'] ?? ''));
			if ($id === '') {
				continue;
			}

			$occurrences[] = ['id' => $id, 'title' => (string)($case['title'] ?? '')];
		}

		return $occurrences;
	}//end shape()

	/**
	 * One `findAll()` row as an array, entity or not.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed> The payload.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function asArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$serialized = $row->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		return (array)$row;
	}//end asArray()
}//end class
