<?php

/**
 * Dossiq case merge relinker.
 *
 * The dossiq-owned rows that hang off a case, moved to the survivor when a
 * merge lands and put back when the platform reverses it. Which kinds move is
 * the schema's declaration, not this class's opinion; what this class knows is
 * how to walk them and how to write down what it moved, because a reversal can
 * only undo what was recorded.
 *
 * Split out of {@see \OCA\Dossiq\Service\CaseMergeService}, which was over its
 * complexity ceiling. A row that cannot be read, has no uuid or refuses the
 * write is skipped exactly as before: a partial move is better than a merge
 * that stops halfway and leaves neither case whole.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Cases
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
 * @spec openspec/changes/case-merge/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Cases;

/**
 * Moving the rows that hang off a merged case, and moving them back.
 *
 * @spec openspec/changes/case-merge/specs/case-management/spec.md
 */
class CaseMergeRelink {

	/**
	 * Constructor.
	 *
	 * @param CaseMergeRule  $rule  Which kinds of row relink, as the schema declares it.
	 * @param CaseMergeStore $store Where a merge reads and writes.
	 */
	public function __construct(
		private readonly CaseMergeRule $rule,
		private readonly CaseMergeStore $store,
	) {
	}//end __construct()

	/**
	 * Move every declared kind of row from the merged case to the survivor.
	 *
	 * @param string $fromId The merged case.
	 * @param string $toId   The survivor.
	 *
	 * @return array<int, array{schema: string, id: string}> The moves, for a reversal.
	 */
	public function move(string $fromId, string $toId): array {
		$moves = [];

		foreach ($this->rule->relinkDeclarations() as $declaration) {
			$slug = (string)($declaration['schema'] ?? '');
			$field = (string)($declaration['field'] ?? '');
			$schemaId = $this->store->schemaId(slug: $slug);
			if ($slug === '' || $field === '' || $schemaId === '') {
				continue;
			}

			foreach ($this->store->rowsFor(schemaId: $schemaId, filters: [$field => $fromId]) as $row) {
				$id = (string)($row['id'] ?? '');
				if ($id === '') {
					continue;
				}

				if ($this->store->patchRow(schemaId: $schemaId, id: $id, changes: [$field => $toId]) === false) {
					continue;
				}

				$moves[] = [
					'schema' => $slug,
					'id' => $id,
				];
			}
		}

		return $moves;
	}//end move()

	/**
	 * Put the recorded moves back on the case they came from.
	 *
	 * @param array<int, mixed> $moves The moves written at merge time.
	 * @param string            $toId  The case they belong to again.
	 *
	 * @return void
	 */
	public function moveBack(array $moves, string $toId): void {
		$fields = [];
		foreach ($this->rule->relinkDeclarations() as $declaration) {
			$fields[(string)($declaration['schema'] ?? '')] = (string)($declaration['field'] ?? '');
		}

		foreach ($moves as $move) {
			if (is_array($move) === false) {
				continue;
			}

			$slug = (string)($move['schema'] ?? '');
			$id = (string)($move['id'] ?? '');
			$field = (string)($fields[$slug] ?? '');
			$schemaId = $this->store->schemaId(slug: $slug);
			if ($id === '' || $field === '' || $schemaId === '') {
				continue;
			}

			$this->store->patchRow(schemaId: $schemaId, id: $id, changes: [$field => $toId]);
		}
	}//end moveBack()

}//end class
