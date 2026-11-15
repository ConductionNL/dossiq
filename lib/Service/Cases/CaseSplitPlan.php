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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Cases;

/**
 * Turns a handler's selection into the writes a split performs.
 *
 * @spec openspec/specs/case-management/spec.md
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
	 * @return array{
	 *     moves: array<int, array<string, mixed>>,
	 *     references: array<int, array<string, mixed>>,
	 *     refused: array<int, array<string, mixed>>,
	 *     relation: array<string, string>
	 * } What to repoint, what to record on the original, what was refused as
	 *   not this case's, and the relation to write.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-a-split-divides-a-case-rather-than-duplicating-it-req-cm-50
	 */
	public function forSelection(string $sourceId, string $newId, array $chosen): array {
		$cases = new SplitCases(sourceId: $sourceId, newId: $newId);
		[$moves, $references, $refused] = $this->partition(plans: $this->itemPlans(cases: $cases, chosen: $chosen));

		return [
			'moves' => $moves,
			'references' => $references,
			'refused' => $refused,
			'relation' => [
				'from' => $sourceId,
				'to' => $newId,
				'nature' => self::RELATION,
			],
		];
	}//end forSelection()

	/**
	 * The plan of every chosen item, part by part.
	 *
	 * @param SplitCases                                      $cases  The case being split and the case being split off.
	 * @param array<string, array<int, array<string, mixed>>> $chosen The items the handler picked, by part.
	 *
	 * @return array<int, array<string, mixed>> The item plans.
	 */
	private function itemPlans(SplitCases $cases, array $chosen): array {
		$plans = [];
		foreach (CaseSplitPolicy::PARTS as $part) {
			foreach (($chosen[$part] ?? []) as $item) {
				$plans[] = $this->itemPlan(part: $part, item: $item, cases: $cases);
			}
		}

		return $plans;
	}//end itemPlans()

	/**
	 * Split the item plans into moves, references and refusals.
	 *
	 * @param array<int, array<string, mixed>> $plans The item plans.
	 *
	 * @return array{0: list<mixed>, 1: list<mixed>, 2: list<mixed>} The moves, the references and the refusals.
	 */
	private function partition(array $plans): array {
		$moves = [];
		$references = [];
		$refused = [];
		foreach ($plans as $plan) {
			if ($plan['refused'] !== null) {
				$refused[] = $plan['refused'];
			}

			if ($plan['move'] !== null) {
				$moves[] = $plan['move'];
				$references[] = $plan['reference'];
			}
		}

		return [$moves, $references, $refused];
	}//end partition()

	/**
	 * What one chosen item becomes: a move with its reference, a refusal, or nothing.
	 *
	 * @param string     $part  The part the item was chosen from.
	 * @param mixed      $item  The chosen item.
	 * @param SplitCases $cases The case being split and the case being split off.
	 *
	 * @return array{
	 *     refused: array<string, string>|null,
	 *     move: array<string, mixed>|null,
	 *     reference: array<string, string>|null
	 * } The item's plan; all null for an item that is skipped.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-a-split-divides-a-case-rather-than-duplicating-it-req-cm-50
	 */
	private function itemPlan(string $part, mixed $item, SplitCases $cases): array {
		if (is_array($item) === false) {
			return ['refused' => null, 'move' => null, 'reference' => null];
		}

		$id = trim((string)($item['id'] ?? ''));
		if ($id === '') {
			return ['refused' => null, 'move' => null, 'reference' => null];
		}

		// 🔴 A ROW THAT IS NOT ON THIS CASE IS NOT THIS SPLIT'S TO
		// MOVE. The selection arrives from a client, and a plan that
		// repointed any id handed to it would let a handler move a
		// document off somebody else's case by editing one field in
		// the request — a write to a record they may never have been
		// allowed to read. A row whose own `case` names a different
		// case is refused by name rather than skipped in silence: a
		// selection that half happened with no word about the rest is
		// the state nobody can reconstruct afterwards.
		$owner = trim((string)($item['case'] ?? ''));
		if ($owner !== '' && $owner !== $cases->sourceId) {
			return ['refused' => ['part' => $part, 'id' => $id, 'case' => $owner], 'move' => null, 'reference' => null];
		}

		return $this->movePlan(part: $part, item: $item, newId: $cases->newId);
	}//end itemPlan()

	/**
	 * The plan that moves an item of this case to the new one.
	 *
	 * @param string               $part  The part the item was chosen from.
	 * @param array<string, mixed> $item  The chosen item, with its id.
	 * @param string               $newId The case being split off.
	 *
	 * @return array<string, mixed>
	 */
	private function movePlan(string $part, array $item, string $newId): array {
		$id = trim((string)($item['id'] ?? ''));
		return [
			'refused' => null,
			// The item itself is repointed: after the split the new
			// case HOLDS it, rather than both cases claiming it.
			'move' => ['part' => $part, 'id' => $id, 'changes' => ['case' => $newId]],
			'reference' => [
				'part' => $part,
				'id' => $id,
				'label' => trim((string)($item['title'] ?? ($item['name'] ?? $id))),
				'movedTo' => $newId,
			],
		];
	}//end movePlan()

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
	 * @spec openspec/specs/case-management/spec.md#requirement-a-split-divides-a-case-rather-than-duplicating-it-req-cm-50
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

		$named = 'the new case';
		if ($newNumber !== '') {
			$named = $newNumber;
		}

		return sprintf('Split off to %s: %s.', $named, implode(', ', $parts));
	}//end noteFor()
}//end class
