<?php

/**
 * Which moves a case offers, and which it withholds and why.
 *
 * Split out of {@see \OCA\Dossiq\Service\StatusTransitionService} because the
 * per-transition decision grew a fifth question and pushed that class over
 * PHPMD's complexity ceiling. The split is not an arithmetic dodge: the
 * questions asked here, is this move from the right status, is its destination
 * derived rather than picked, does a role guard hide it, is a dependency of it
 * unsettled, and did its guards pass, are one decision about one transition,
 * and the engine's own job is what happens when a handler TAKES one.
 *
 * Nothing here writes. It reads the template, the case and the declarations,
 * and answers with the two lists the case page renders.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
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
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

use OCA\Dossiq\Service\Status\StatusDeclarations;

/**
 * Builds the offered and withheld lists for one case.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
class OfferedTransitions {

	/**
	 * Constructor.
	 *
	 * @param GuardRegistry          $guardRegistry The guards a transition is subject to.
	 * @param TransitionSpecReader   $specReader    The template dialects a transition may be spelled in.
	 * @param StatusDeclarations     $statuses      What the destination status declares.
	 * @param TransitionDeclarations $moves         What the transition itself declares.
	 */
	public function __construct(
		private readonly GuardRegistry $guardRegistry,
		private readonly TransitionSpecReader $specReader,
		private readonly StatusDeclarations $statuses,
		private readonly TransitionDeclarations $moves,
	) {
	}//end __construct()

	/**
	 * The moves out of this case's current status, sorted into two lists.
	 *
	 * @param array<int, array<string, mixed>> $transitions The active template's transitions.
	 * @param array<string, mixed>             $case        The case.
	 * @param string                           $currentId   The status the case is in.
	 * @param string                           $userId      The acting user.
	 *
	 * @return array{transitions: array<int, array<string, mixed>>, withheld: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function from(array $transitions, array $case, string $currentId, string $userId): array {
		return $this->sorted(
			transitions: $transitions,
			asked: ['case' => $case, 'caseTypeId' => (string)($case['caseType'] ?? ''), 'currentId' => $currentId, 'userId' => $userId]
		);
	}//end from()

	/**
	 * The transitions leading from the current status, sorted into offered and withheld.
	 *
	 * @param array<int, array<string, mixed>> $transitions The case type's transitions.
	 * @param array<string, mixed>             $asked       The `case`, its `caseTypeId`, the `currentId` status and the `userId` asking.
	 *
	 * @return array{transitions: array<int, mixed>, withheld: array<int, mixed>} The two lists.
	 */
	private function sorted(array $transitions, array $asked): array {
		$lists = ['transitions' => [], 'withheld' => []];

		foreach ($transitions as $transition) {
			if ($this->leadsFrom(transition: $transition, asked: $asked) === false) {
				continue;
			}

			$sorted = $this->sortTransition(transition: $transition, asked: $asked);
			if ($sorted !== null) {
				$lists[$sorted[0]][] = $sorted[1];
			}
		}

		return $lists;
	}//end sorted()

	/**
	 * Which list one transition out of the current status belongs on, with its entry; null when it is hidden.
	 *
	 * @param array<string, mixed> $transition The transition.
	 * @param array<string, mixed> $asked      The case (`case`) and the acting user (`userId`).
	 *
	 * @return array{0: string, 1: array<string, mixed>}|null `transitions` or `withheld`, and the entry.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function sortTransition(array $transition, array $asked): ?array {
		$caseRow = $asked['case'];
		$eval = $this->guardRegistry->evaluateAll(
			guards: $this->guardsFor(transition: $transition),
			case: $caseRow,
			userId: $asked['userId'],
		);

		// Drop transitions whose role guard hides them silently.
		if ($this->specReader->isRoleHidden(evalResults: $eval) === true) {
			return null;
		}

		// WITHHOLDING BEATS REFUSING. A transition that is offered and
		// then refused teaches the handler that the list is unreliable; a
		// transition that is not offered, with the reason readable in its
		// place, teaches them what to do next. The information is the same
		// and only one of the two is usable.
		$reasons = $this->moves->withheldReasons(transition: $transition, case: $caseRow);
		if ($reasons !== []) {
			return ['withheld', $this->withheldEntry(transition: $transition, reasons: $reasons)];
		}

		return ['transitions', $this->offeredEntry(transition: $transition, evaluated: ['eval' => $eval, 'case' => $caseRow])];
	}//end sortTransition()

	/**
	 * A withheld transition with the reasons it is withheld.
	 *
	 * @param array<string, mixed> $transition The transition.
	 * @param array<int, string>   $reasons    Why it is withheld.
	 *
	 * @return array<string, mixed> The entry.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function withheldEntry(array $transition, array $reasons): array {
		return [
			'id' => (string)($transition['id'] ?? ''),
			'label' => (string)($transition['label'] ?? ''),
			'toStatus' => (string)($transition['toStatus'] ?? ''),
			'reasons' => $reasons,
		];
	}//end withheldEntry()

	/**
	 * Whether this transition is a move a handler could pick from here at all.
	 *
	 * Two questions with one answer, because both mean "not a move on this
	 * page": it starts somewhere else, or its destination is a status the case
	 * type DERIVES. A derived status is not picked. If it could be both, the
	 * two disagree within a week and nobody knows which one is the record: a
	 * handler sets Complete on a file that is not, the derivation never fires
	 * because the case is already there, and the missing document is never
	 * named.
	 *
	 * @param mixed                $transition The transition definition, in whatever shape it arrived.
	 * @param array<string, mixed> $asked      The status the case is in (`currentId`) and the case type
	 *                                         (`caseTypeId`), for the derived lookup.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function leadsFrom(mixed $transition, array $asked): bool {
		if (is_array($transition) === false) {
			return false;
		}

		if ((string)($transition['fromStatus'] ?? '') !== $asked['currentId']) {
			return false;
		}

		return $this->statuses->isDerivedStatus(
			caseTypeId: $asked['caseTypeId'],
			statusTypeId: (string)($transition['toStatus'] ?? ''),
		) === false;
	}//end leadsFrom()

	/**
	 * The guards a transition is subject to: its own, plus the implicit one.
	 *
	 * The status checklist is appended to EVERY transition rather than left to
	 * the template, because the list it enforces is authored on the status. A
	 * guard a template has to remember is a guard the next case type forgets,
	 * and a required item that only holds one road out of a phase holds
	 * nothing at all.
	 *
	 * It goes LAST, so a role guard still hides a transition before the
	 * checklist has anything to say about it.
	 *
	 * @param array<string, mixed> $transition The transition definition.
	 *
	 * @return array<int, array<string, mixed>> The guards to evaluate.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function guardsFor(array $transition): array {
		// The implicit guards (the status checklist, the status capacity and
		// the walked approval) come from the one list the offer and the move
		// both read.
		return $this->specReader->guardsWithImplicit(
			transition: $transition,
			approvalsWired: $this->guardRegistry->knows(type: GuardRegistry::APPROVAL_GATE),
			entryGuardWired: $this->guardRegistry->knows(type: GuardRegistry::STATUS_ENTRY_REQUIREMENTS),
		);
	}//end guardsFor()

	/**
	 * One offered move, as the case page reads it.
	 *
	 * @param array<string, mixed> $transition The transition definition.
	 * @param array<string, mixed> $evaluated  The guard evaluation snapshots (`eval`) and the case (`case`), for the
	 *                                         results a closing move offers.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function offeredEntry(array $transition, array $evaluated): array {
		$case = $evaluated['case'];
		$failed = array_values(
			array_filter($evaluated['eval'], static fn (array $guard): bool => $guard['passed'] === false)
		);
		$toStatus = (string)($transition['toStatus'] ?? '');
		$toStatusName = $this->statuses->nameOf(statusTypeId: $toStatus);
		$label = (string)($transition['label'] ?? '');
		// A label that is only the destination's name, in whatever language it
		// was written, reads as that name in the reader's language.
		if ($toStatusName !== '' && $this->statuses->isNameOf(statusTypeId: $toStatus, text: $label) === true) {
			$label = $toStatusName;
		}

		return [
			'id' => (string)($transition['id'] ?? ''),
			'label' => $label,
			// Additive, and empty for every template shipped today: the
			// documented StatusTransition shape carries no `description`. It is
			// published so a template that does write one reaches
			// CaseActionProvider, which has no other sight of the transition
			// definition.
			'description' => (string)($transition['description'] ?? ''),
			// The sentence an administrator wrote for the moment of choosing,
			// which is the only moment guidance is worth anything. Empty
			// renders nothing rather than a blank line.
			'explanation' => $this->moves->explanationOf(transition: $transition),
			'toStatus' => $toStatus,
			// The destination's name, so a menu can say where the move goes
			// ("Naar Besluit") when the move's own label is only that name.
			'toStatusName' => $toStatusName,
			'guardsPassed' => count($failed) === 0,
			'failedGuards' => $failed,
			// Whether the move closes the case and the results it may close
			// with, so the form asks for one before the post instead of the
			// engine refusing a close nobody was asked a result for.
			...$this->moves->closingChoice(transition: $transition, case: $case),
		];
	}//end offeredEntry()
}//end class
