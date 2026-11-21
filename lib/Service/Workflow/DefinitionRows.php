<?php

/**
 * The rows a workflow definition read answers, sorted, read for their ids or encoded for a write.
 *
 * Split out of {@see \OCA\Dossiq\Service\Workflow\WorkflowDefinitionRepository},
 * which keeps every read and write. This class reads and writes nothing: it
 * coerces each row the way the repository does, drops what is no row, sorts
 * versions newest first, lists the ids rows carry, and turns array-valued
 * properties the schema stores as JSON text back into text.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Workflow
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
 * @spec openspec/specs/workflow-definition-model/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Workflow;

use OCA\Dossiq\Service\Support\SearchesObjects;

/**
 * Sorts, reads ids from and encodes workflow definition rows.
 *
 * @spec openspec/specs/workflow-definition-model/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class DefinitionRows {

	use SearchesObjects;

	/**
	 * Normalise the rows and sort them by version, newest first.
	 *
	 * @param array<int, mixed> $results The raw rows.
	 *
	 * @return array<int, array<string, mixed>> The versions, newest first.
	 *
	 * @spec openspec/specs/workflow-definition-model/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function newestFirst(array $results): array {
		$rows = $this->normalizedRows(results: $results);

		usort(
			$rows,
			static function (array $a, array $b): int {
				return (int)($b['version'] ?? 0) <=> (int)($a['version'] ?? 0);
			},
		);

		return $rows;
	}//end newestFirst()

	/**
	 * The ids of the rows that carry one.
	 *
	 * @param array<int, mixed> $rows The rows.
	 *
	 * @return array<int, string> The ids.
	 *
	 * @spec openspec/specs/workflow-definition-model/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function idsOf(array $rows): array {
		$ids = [];
		foreach ($rows as $row) {
			$normalized = $this->objectToArrayOrNull(value: $row);
			if ($normalized === null) {
				continue;
			}

			$id = (string)($normalized['id'] ?? '');
			if ($id !== '') {
				$ids[] = $id;
			}
		}

		return $ids;
	}//end idsOf()

	/**
	 * Encode the array-valued JSON-string properties back to strings.
	 *
	 * @param array<string, mixed> $merged         The merged object.
	 * @param array<int, string>   $jsonProperties Properties the schema stores as a JSON string.
	 *
	 * @return array<string, mixed> The object ready to store.
	 *
	 * @spec openspec/specs/workflow-definition-model/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function withJsonEncoded(array $merged, array $jsonProperties): array {
		foreach ($jsonProperties as $property) {
			if (is_array(($merged[$property] ?? null)) === true) {
				$merged[$property] = json_encode($merged[$property]);
			}
		}

		return $merged;
	}//end withJsonEncoded()

	/**
	 * Normalise the rows, dropping those that are not a definition.
	 *
	 * @param array<int, mixed> $results The raw rows.
	 *
	 * @return array<int, array<string, mixed>> The normalised rows.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function normalizedRows(array $results): array {
		$rows = [];
		foreach ($results as $row) {
			$normalized = $this->objectToArrayOrNull(value: $row);
			if ($normalized !== null) {
				$rows[] = $normalized;
			}
		}

		return $rows;
	}//end normalizedRows()
}//end class
