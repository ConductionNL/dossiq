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
 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

use OCA\Dossiq\Service\Status\StatusDeclarations;

/**
 * Builds the offered and withheld lists for one case.
 *
 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
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
	 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
	 */
	public function from(array $transitions, array $case, string $currentId, string $userId): array {
		$caseTypeId = (string)($case['caseType'] ?? '');
		$offered = [];
		$withheld = [];

		foreach ($transitions as $transition) {
			if ($this->leadsFrom(transition: $transition, currentId: $currentId, caseTypeId: $caseTypeId) === false) {
				continue;
			}

			$eval = $this->guardRegistry->evaluateAll(
				guards: $this->guardsFor(transition: $transition),
				case: $case,
				userId: $userId,
			);

			// Drop transitions whose role guard hides them silently.
			if ($this->specReader->isRoleHidden(evalResults: $eval) === true) {
				continue;
			}

			// WITHHOLDING BEATS REFUSING. A transition that is offered and
			// then refused teaches the handler that the list is unreliable; a
			// transition that is not offered, with the reason readable in its
			// place, teaches them what to do next. The information is the same
			// and only one of the two is usable.
			$reasons = $this->moves->withheldReasons(transition: $transition, case: $case);
			if ($reasons !== []) {
				$withheld[] = [
					'id' => (string)($transition['id'] ?? ''),
					'label' => (string)($transition['label'] ?? ''),
					'toStatus' => (string)($transition['toStatus'] ?? ''),
					'reasons' => $reasons,
				];
				continue;
			}

			$offered[] = $this->offeredEntry(transition: $transition, eval: $eval);
		}//end foreach

		return ['transitions' => $offered, 'withheld' => $withheld];
	}//end from()

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
	 * @param mixed  $transition The transition definition, in whatever shape it arrived.
	 * @param string $currentId  The status the case is in.
	 * @param string $caseTypeId The case type, for the derived lookup.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
	 */
	private function leadsFrom(mixed $transition, string $currentId, string $caseTypeId): bool {
		if (is_array($transition) === false) {
			return false;
		}

		if ((string)($transition['fromStatus'] ?? '') !== $currentId) {
			return false;
		}

		return $this->statuses->isDerivedStatus(
			caseTypeId: $caseTypeId,
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
		$guards = $this->specReader->extractGuards(transition: $transition);
		$guards[] = ['type' => GuardRegistry::STATUS_CHECKLIST];

		return $guards;
	}//end guardsFor()

	/**
	 * One offered move, as the case page reads it.
	 *
	 * @param array<string, mixed>             $transition The transition definition.
	 * @param array<int, array<string, mixed>> $eval       The guard evaluation snapshots.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
	 */
	private function offeredEntry(array $transition, array $eval): array {
		$failed = array_values(
			array_filter($eval, static fn (array $guard): bool => $guard['passed'] === false)
		);

		return [
			'id' => (string)($transition['id'] ?? ''),
			'label' => (string)($transition['label'] ?? ''),
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
			'toStatus' => (string)($transition['toStatus'] ?? ''),
			'guardsPassed' => count($failed) === 0,
			'failedGuards' => $failed,
		];
	}//end offeredEntry()
}//end class
