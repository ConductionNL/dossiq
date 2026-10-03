<?php

/**
 * The one door the engine knocks on for what a TRANSITION declares.
 *
 * `StatusTransitionService` already carries twelve collaborators. The three
 * things this change adds to a transition, what must be settled first, what it
 * explains, and who may not make it, would have been a thirteenth, a
 * fourteenth and a fifteenth. They arrive on the same transition definition and
 * they are read at the same two moments, when the engine lists what a handler
 * may do and when a handler takes one, so they are one seam rather than three.
 * `StatusDeclarations` is the same shape on the status side.
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

/**
 * What a transition declares: its preconditions, its explanation, its four eyes.
 *
 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
 */
class TransitionDeclarations {

	/**
	 * Constructor.
	 *
	 * @param TransitionPreconditions $preconditions What must be settled first.
	 * @param FourEyesRule            $fourEyes      Who may not make this move.
	 * @param CaseResultWriter        $resultWriter  Which statuses close a case.
	 * @param CaseStatusStore         $store         The case's own status record chain.
	 */
	public function __construct(
		private readonly TransitionPreconditions $preconditions,
		private readonly FourEyesRule $fourEyes,
		private readonly CaseResultWriter $resultWriter,
		private readonly CaseStatusStore $store,
	) {
	}//end __construct()

	/**
	 * Why this transition is withheld, or an empty list when it is available.
	 *
	 * 🔑 EVERY CLOSING STATUS, NOT THE ONE THE CASE TYPE CALLS CLOSED. Whether
	 * the destination closes the case is asked of `statusType.isFinal` at the
	 * moment the question is put, so a case type that grows a fourth way out
	 * next year is covered the day it grows it. A declaration that listed the
	 * closing statuses by id would have to be kept complete by hand, and the
	 * door somebody forgot is the one the case leaves by.
	 *
	 * @param array<string, mixed> $transition The transition definition.
	 * @param array<string, mixed> $case       The case.
	 *
	 * @return array<int, string> The reasons, in declaration order.
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
	 */
	public function withheldReasons(array $transition, array $case): array {
		return $this->preconditions->withheldReasons(
			transition: $transition,
			case: $case,
			toIsClosing: $this->resultWriter->isFinalStatus(
				statusTypeId: (string)($transition['toStatus'] ?? ''),
			),
		);
	}//end withheldReasons()

	/**
	 * The sentence an administrator wrote for the handler taking this move.
	 *
	 * A description on a status is read when the case is already there. The
	 * sentence a handler needs is at the moment of choosing, which is the
	 * transition. `explanation` is the property that carries it; `description`
	 * is its older spelling and keeps working, because a template that already
	 * wrote one should not go quiet.
	 *
	 * An empty explanation stays empty rather than becoming a space: the
	 * caller renders nothing for it.
	 *
	 * @param array<string, mixed> $transition The transition definition.
	 *
	 * @return string The explanation, or the empty string.
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
	 */
	public function explanationOf(array $transition): string {
		$explanation = trim((string)($transition['explanation'] ?? ''));
		if ($explanation !== '') {
			return $explanation;
		}

		return trim((string)($transition['description'] ?? ''));
	}//end explanationOf()

	/**
	 * Whether this person performed the earlier act this transition is closed to.
	 *
	 * @param array<string, mixed> $transition The transition definition.
	 * @param string               $caseId     The case.
	 * @param string               $userId     The acting user.
	 *
	 * @return array{act: string, actor: string, at: string, askInstead: string}|null
	 *         The refusal, or null when the transition is open to this person.
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
	 */
	public function fourEyesRefusal(array $transition, string $caseId, string $userId): ?array {
		if ($this->fourEyes->declaredFor(transition: $transition) === null) {
			return null;
		}

		return $this->fourEyes->refusalFor(
			transition: $transition,
			records: ($this->store->findStatusRecords(caseId: $caseId) ?? []),
			userId: $userId,
		);
	}//end fourEyesRefusal()

	/**
	 * The sentence a refused handler reads.
	 *
	 * "You may not do this" sends them to a colleague to ask why. Naming the
	 * act and its date sends them to the right colleague, and where the case
	 * type says who may be asked instead, it says that too.
	 *
	 * @param array{act: string, actor: string, at: string, askInstead: string} $refusal The refusal.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
	 */
	public function refusalSentence(array $refusal): string {
		$date = substr((string)($refusal['at'] ?? ''), 0, 10);
		$sentence = 'You performed ' . $refusal['act'];
		if ($date !== '') {
			$sentence .= ' on ' . $date;
		}

		$sentence .= ', so somebody else takes this step.';

		$askInstead = trim((string)($refusal['askInstead'] ?? ''));
		if ($askInstead !== '') {
			$sentence .= ' Ask ' . $askInstead . '.';
		}

		return $sentence;
	}//end refusalSentence()
}//end class
