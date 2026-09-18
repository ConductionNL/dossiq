<?php

/**
 * What a split moves, and what it leaves behind.
 *
 * 🔑 A SPLIT MOVES, A COPY DUPLICATES (D-1). `CaseCopyService` is right for
 * what it does: a follow-up case that starts from an earlier one. A split is a
 * different act. The material LEAVES the first case, and if it does not leave,
 * the split has produced two cases that both claim the same document and the
 * handler cleans up by hand or does not.
 *
 * 🔴 WHAT LEAVES STILL LEAVES A TRACE. Each moved item is repointed at the new
 * case AND recorded on the original as a reference naming where it went, so
 * the original file still reads as a whole and nobody opening it in a year
 * finds a gap with no explanation. A move with no trace is indistinguishable
 * from a deletion.
 *
 * 🔴 A PARTY RELEVANT TO BOTH HALVES IS ON BOTH (D-3). A party on a case is a
 * role, not a copy of a person, so a split divides roles: the ones chosen move
 * and the ones not chosen stay. A handler who needs the same counter-party on
 * both halves adds the role to the new case, which is an ordinary act, rather
 * than the split guessing for them.
 *
 * This class decides; the caller writes. Keeping the decision pure is what
 * lets every rule above be driven without a register.
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
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Cases;

/**
 * Turns a handler's selection into the writes a split performs.
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */
class CaseSplitPlan {
	/**
	 * The relation a split writes between the two cases.
	 *
	 * `vervolg` is the relation `CaseCopyService` already writes and
	 * `CaseRelationService` already stores. A second nature meaning "came out
	 * of" would be one more word for a thing the register can already say, and
	 * every reader of a case would have to learn both.
	 *
	 * @var string
	 */
	public const RELATION = 'vervolg';

	/**
	 * The plan for one split.
	 *
	 * @param string $sourceId The case being split.
	 * @param string $newId The case being split off.
	 * @param array<string, array<int, array<string, mixed>>> $chosen The items the handler picked, by part.
	 *
	 * @return array{moves: array<int, array<string, mixed>>, references: array<int, array<string, mixed>>, relation: array<string, string>}
	 *         What to repoint, what to record on the original, and the relation to write.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-split-divides-a-case-rather-than-duplicating-it-req-cm-45
	 */
	public function forSelection(string $sourceId, string $newId, array $chosen): array {
		$moves = [];
		$references = [];

		foreach (CaseSplitPolicy::PARTS as $part) {
			foreach (($chosen[$part] ?? []) as $item) {
				if (is_array($item) === false) {
					continue;
				}

				$id = trim((string)($item['id'] ?? ''));
				if ($id === '') {
					continue;
				}

				$moves[] = [
					'part' => $part,
					'id' => $id,
					// The item itself is repointed: after the split the new
					// case HOLDS it, rather than both cases claiming it.
					'changes' => ['case' => $newId],
				];

				$references[] = [
					'part' => $part,
					'id' => $id,
					'label' => trim((string)($item['title'] ?? ($item['name'] ?? $id))),
					'movedTo' => $newId,
				];
			}
		}

		return [
			'moves' => $moves,
			'references' => $references,
			'relation' => [
				'from' => $sourceId,
				'to' => $newId,
				'nature' => self::RELATION,
			],
		];
	}//end forSelection()

	/**
	 * The note recorded on the original case, naming what left it.
	 *
	 * One note rather than one per item: a case split into two halves gets a
	 * single line in its history saying what went where, and twenty lines
	 * would bury the act that produced them.
	 *
	 * @param array<int, array<string, mixed>> $references The reference entries.
	 * @param string $newNumber The number of the case the items went to.
	 *
	 * @return string The note, '' when nothing moved.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-split-divides-a-case-rather-than-duplicating-it-req-cm-45
	 */
	public function noteFor(array $references, string $newNumber): string {
		if ($references === []) {
			return '';
		}

		$byPart = [];
		foreach ($references as $reference) {
			$part = (string)($reference['part'] ?? '');
			$byPart[$part] = (($byPart[$part] ?? 0) + 1);
		}

		$parts = [];
		foreach ($byPart as $part => $count) {
			$parts[] = sprintf('%d %s', $count, $part);
		}

		return sprintf('Split off to %s: %s.', ($newNumber !== '' ? $newNumber : 'the new case'), implode(', ', $parts));
	}//end noteFor()
}//end class
