<?php

/**
 * What a case type's published lifecycle can and cannot reach.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseType
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
 * @spec openspec/specs/case-type-publish-validation/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseType;

/**
 * Walks a case type's moves and reports what a case could never reach.
 *
 * 🔑 PUBLISHING IS THE ACT THAT MAKES A DECLARATION LIVE, so it is the moment
 * to refuse a declaration nothing can reach. Every finding NAMES the status or
 * the move at fault. A refusal that says only "this lifecycle is unreachable"
 * hands an administrator a graph to read by hand, and the two failures this
 * class exists for are exactly the ones that are invisible in that graph:
 *
 *  - A MOVE NOTHING CAN FIRE. Both readers of a transition compare its
 *    `fromStatus` to the status the case is in with a strict equality:
 *    `StatusTransitionService::assertTransitionAllowed()` refuses anything
 *    else, and `Transitions\OfferedTransitions::leadsFrom()` never offers it.
 *    So a `fromStatus` that is empty, `*`, or a status this type does not
 *    declare is a move that is authored, stored, exported and never offered.
 *    Three writers put `*` there on purpose as a wildcard
 *    (`Besluitvorming\WorkflowReferenceResolver`, `Vth\VthWorkflowGraphResolver`,
 *    `Repair\SeedBezwaarWorkflowDefinition`) and no reader honours it, which is
 *    a capability with no caller: it reads as configured and behaves as absent.
 *
 *  - A STATUS NOTHING LEADS TO. A status declared on the case type that no
 *    sound move targets is on the page, in the picker and in every export, and
 *    a case can never be in it.
 *
 * 🔑 IT IS PURE, AND THAT IS THE POINT. It takes the statuses and the moves and
 * answers. It reads no register and holds no collaborator, so the publish
 * service that owns the write decides WHEN to ask and this class only decides
 * WHAT is unreachable.
 *
 * 🔴 IT SAYS NOTHING WHEN THERE ARE NO MOVES. A case type that drives its
 * lifecycle from statuses alone carries no workflow template, and those are the
 * majority. Reporting "nothing leads to this status" for every status on every
 * one of them would make the fleet unpublishable on the day this shipped.
 *
 * @spec openspec/specs/case-type-publish-validation/spec.md
 */
final class CaseTypeReachability {

	/**
	 * The wildcard three writers store and no reader honours.
	 */
	public const DEAD_WILDCARD = '*';

	/**
	 * What stands between this lifecycle and a case that can run through it.
	 *
	 * @param array<string, array{title: string, final: bool}> $statuses The declared statuses, by id.
	 * @param string                                           $initial  The status a new case starts in.
	 * @param array<int, array<string, mixed>>                 $moves    The active template's transitions.
	 *
	 * @return array<int, string> The findings, empty when every declaration is reachable.
	 *
	 * @spec openspec/specs/case-type-publish-validation/spec.md
	 */
	public function findings(array $statuses, string $initial, array $moves): array {
		if ($statuses === [] || $moves === []) {
			return [];
		}

		$broken = $this->brokenMoves(statuses: $statuses, moves: $moves);
		$sound = $this->soundMoves(statuses: $statuses, moves: $moves);
		$reached = $this->reachedFrom(initial: $initial, statuses: $statuses, moves: $sound);

		return array_merge(
			array_column($broken, 'finding'),
			$this->orphanFindings(
				statuses: $statuses,
				initial: $initial,
				reached: $reached,
				blamed: array_column($broken, 'target')
			),
			$this->closureFindings(statuses: $statuses, initial: $initial, reached: $reached)
		);
	}//end findings()

	/**
	 * The moves that name a status this case type does not have.
	 *
	 * Each entry carries the sentence and the status the move was trying to
	 * lead to, so the orphan pass can stay quiet about a status whose only way
	 * in is a move already named here. One root cause, one finding: an
	 * administrator told twice about one broken move fixes it twice.
	 *
	 * @param array<string, array{title: string, final: bool}> $statuses The declared statuses, by id.
	 * @param array<int, array<string, mixed>>                 $moves    The active template's transitions.
	 *
	 * @return array<int, array{finding: string, target: string}> The broken moves.
	 */
	private function brokenMoves(array $statuses, array $moves): array {
		$broken = [];
		foreach ($moves as $move) {
			if (is_array($move) === false) {
				continue;
			}

			$from = (string)($move['fromStatus'] ?? '');
			$to = (string)($move['toStatus'] ?? '');
			$name = $this->moveName(move: $move);

			if ($from === '' || $from === self::DEAD_WILDCARD) {
				$broken[] = [
					'finding' => ('The move "' . $name . '" starts from no status, so nothing can ever offer it. Name the status it starts from.'),
					'target' => $to,
				];
				continue;
			}

			if (isset($statuses[$from]) === false) {
				$broken[] = [
					'finding' => ('The move "' . $name . '" starts from a status this case type does not have. Nothing can ever offer it.'),
					'target' => $to,
				];
				continue;
			}

			if ($to === '' || isset($statuses[$to]) === false) {
				$broken[] = [
					'finding' => ('The move "' . $name . '" leads to a status this case type does not have.'),
					'target' => '',
				];
			}
		}//end foreach

		return $broken;
	}//end brokenMoves()

	/**
	 * The moves both ends of which are statuses this case type declares.
	 *
	 * @param array<string, array{title: string, final: bool}> $statuses The declared statuses, by id.
	 * @param array<int, array<string, mixed>>                 $moves    The active template's transitions.
	 *
	 * @return array<int, array{from: string, to: string}> The sound moves.
	 */
	private function soundMoves(array $statuses, array $moves): array {
		$sound = [];
		foreach ($moves as $move) {
			if (is_array($move) === false) {
				continue;
			}

			$from = (string)($move['fromStatus'] ?? '');
			$to = (string)($move['toStatus'] ?? '');

			if (isset($statuses[$from]) === true && isset($statuses[$to]) === true) {
				$sound[] = [
					'from' => $from,
					'to' => $to,
				];
			}
		}

		return $sound;
	}//end soundMoves()

	/**
	 * Every status a case can get to, starting where a new case starts.
	 *
	 * @param string                                           $initial  The status a new case starts in.
	 * @param array<string, array{title: string, final: bool}> $statuses The declared statuses, by id.
	 * @param array<int, array{from: string, to: string}>      $moves    The sound moves.
	 *
	 * @return array<string, true> The reachable status ids, the initial one included.
	 */
	private function reachedFrom(string $initial, array $statuses, array $moves): array {
		if ($initial === '' || isset($statuses[$initial]) === false) {
			return [];
		}

		$reached = [$initial => true];
		$frontier = [$initial];

		while ($frontier !== []) {
			$here = array_pop($frontier);
			foreach ($moves as $move) {
				if ($move['from'] !== $here || isset($reached[$move['to']]) === true) {
					continue;
				}

				$reached[$move['to']] = true;
				$frontier[] = $move['to'];
			}
		}

		return $reached;
	}//end reachedFrom()

	/**
	 * The statuses a case could never be in.
	 *
	 * @param array<string, array{title: string, final: bool}> $statuses The declared statuses, by id.
	 * @param string                                           $initial  The status a new case starts in.
	 * @param array<string, true>                              $reached  The reachable status ids.
	 * @param array<int, string>                               $blamed   Targets of moves already reported.
	 *
	 * @return array<int, string> The findings, one per orphaned status.
	 */
	private function orphanFindings(array $statuses, string $initial, array $reached, array $blamed): array {
		if ($reached === []) {
			// The initial status is not one of this type's own. `validate()`
			// already refuses that in its own words, and repeating it here
			// once per status would bury it.
			return [];
		}

		$findings = [];
		foreach ($statuses as $id => $status) {
			if ($id === $initial || isset($reached[$id]) === true || in_array($id, $blamed, true) === true) {
				continue;
			}

			$findings[] = (
				'No move leads to the status "' . $status['title']
				. '", so a case can never be in it. Give it a move in, or take it off this case type.'
			);
		}

		return $findings;
	}//end orphanFindings()

	/**
	 * The finding a lifecycle with no way to close produces.
	 *
	 * A case type whose final status exists but cannot be walked to is the
	 * failure that shows up last and hurts most: cases open, they run, and the
	 * desk discovers months in that none of them can be closed.
	 *
	 * @param array<string, array{title: string, final: bool}> $statuses The declared statuses, by id.
	 * @param string                                           $initial  The status a new case starts in.
	 * @param array<string, true>                              $reached  The reachable status ids.
	 *
	 * @return array<int, string> The finding, or an empty array.
	 */
	private function closureFindings(array $statuses, string $initial, array $reached): array {
		if ($reached === []) {
			return [];
		}

		foreach (array_keys($reached) as $id) {
			if (($statuses[$id]['final'] ?? false) === true) {
				return [];
			}
		}

		$start = ($statuses[$initial]['title'] ?? $initial);

		return [
			('No sequence of moves leads from "' . $start . '" to a status that closes a case. A case of this type could never be finished.'),
		];
	}//end closureFindings()

	/**
	 * What to call a move in a sentence a person reads.
	 *
	 * The label is what the author wrote and what the case page shows. An id
	 * is the fallback rather than the first choice, because a UUID in a
	 * refusal tells the reader only that something is wrong somewhere.
	 *
	 * @param array<string, mixed> $move One transition off the template.
	 *
	 * @return string The label, the id, or a placeholder.
	 */
	private function moveName(array $move): string {
		foreach (['label', 'name', 'title', 'id'] as $field) {
			$value = trim((string)($move[$field] ?? ''));
			if ($value !== '') {
				return $value;
			}
		}

		return 'an unnamed move';
	}//end moveName()
}//end class
