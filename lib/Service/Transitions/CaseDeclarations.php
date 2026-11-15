<?php

/**
 * What a case declares about its status and its moves
 *
 * The read side of the status-transition engine. An administrator declares on
 * the status what it waits on and how long it may last, on the transition what
 * must be closed first and who may take it, and on the status the work it
 * brings. The engine asks all of that here and nowhere else.
 *
 * Nothing here writes to the store: the one use of it is reading the row of a
 * status. `stamp()` answers a payload the engine has saved by
 * {@see CaseMoveLedger}, and `retime()` talks to the term engine.
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

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Status\StatusDeclarations;

/**
 * One reader over what a status, a transition and a checklist declare.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
class CaseDeclarations {
	/**
	 * Constructor.
	 *
	 * @param StatusDeclarations $statuses What a status declares about itself.
	 * @param TransitionDeclarations $moves What a transition declares about itself.
	 * @param OfferedTransitions $offered Which moves a case offers and which it withholds.
	 * @param StatusChecklist $checklist The checklist a status brings with it.
	 * @param CaseStatusStore $store Reads the row of a status.
	 */
	public function __construct(
		private readonly StatusDeclarations $statuses,
		private readonly TransitionDeclarations $moves,
		private readonly OfferedTransitions $offered,
		private readonly StatusChecklist $checklist,
		private readonly CaseStatusStore $store,
	) {
	}//end __construct()

	/**
	 * The name, the colour and the description of a status, as its row holds them.
	 *
	 * @param string $statusTypeId The status.
	 *
	 * @return array{statusName: string, statusColour: string, statusDescription: string}
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function statusFacts(string $statusTypeId): array {
		return [
			'statusName' => $this->store->lookupStatusName(statusTypeId: $statusTypeId),
			'statusColour' => $this->store->lookupStatusColour(statusTypeId: $statusTypeId),
			'statusDescription' => $this->store->lookupStatusDescription(statusTypeId: $statusTypeId),
		];
	}//end statusFacts()

	/**
	 * What the status the case is in declares: who it waits on, how long it has lasted, how it was derived.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed> `waitingOn`, `dwell` and `derivation`.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function panel(array $case): array {
		return $this->statuses->panelFor(case: $case);
	}//end panel()

	/**
	 * Which of a workflow's transitions this case offers this user, and which it withholds and why.
	 *
	 * @param array<int, mixed> $transitions The transitions of the workflow the case runs on.
	 * @param array<string, mixed> $case The case.
	 * @param string $currentId The status the case is in.
	 * @param string $userId The user asking.
	 *
	 * @return array{transitions: array<int, array<string, mixed>>, withheld: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function offered(array $transitions, array $case, string $currentId, string $userId): array {
		return $this->offered->from(transitions: $transitions, case: $case, currentId: $currentId, userId: $userId);
	}//end offered()

	/**
	 * Refuse a move its own declaration withholds: an open dependency first, then four eyes.
	 *
	 * @param array<string, mixed> $transition The transition.
	 * @param array<string, mixed> $case The case.
	 * @param string $caseId The case uuid.
	 * @param string $userId The user taking the move.
	 *
	 * @return void
	 *
	 * @throws RefusedException When something the move waits on is open, or four eyes refuses the user.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function assertOpen(array $transition, array $case, string $caseId, string $userId): void {
		$withheld = $this->moves->withheldReasons(transition: $transition, case: $case);
		if ($withheld !== []) {
			throw new RefusedException(
				rule: 'transition-dependency-open',
				sentence: 'This move is waiting on ' . $withheld[0] . '.',
				status: RefusedException::STATUS_REFUSED,
			);
		}

		$refusal = $this->moves->fourEyesRefusal(transition: $transition, caseId: $caseId, userId: $userId);
		if ($refusal !== null) {
			throw new RefusedException(
				rule: 'transition-four-eyes',
				sentence: $this->moves->refusalSentence(refusal: $refusal),
				status: RefusedException::STATUS_FORBIDDEN,
			);
		}
	}//end assertOpen()

	/**
	 * The case payload with the bookkeeping of the status it enters. Saved by the caller.
	 *
	 * @param array<string, mixed> $case The case payload about to be saved.
	 * @param string $toStatus The status being entered.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
	 */
	public function stamp(array $case, string $toStatus): array {
		return $this->statuses->applyStatusChange(case: $case, toStatus: $toStatus);
	}//end stamp()

	/**
	 * Stop the clock on the status the case left and start one on the status it entered.
	 *
	 * Called after the save, never before it.
	 *
	 * @param string $caseId The case uuid.
	 * @param string $toStatus The status entered.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
	 */
	public function retime(string $caseId, string $toStatus): void {
		$this->statuses->retime(caseId: $caseId, toStatus: $toStatus);
	}//end retime()

	/**
	 * The actions the entered status brings with it.
	 *
	 * @param string $statusTypeId The status entered.
	 * @param array<string, mixed> $case The saved case.
	 * @param string $actor The acting user.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function checklistActions(string $statusTypeId, array $case, string $actor): array {
		return $this->checklist->actionsFor(statusTypeId: $statusTypeId, case: $case, actor: $actor);
	}//end checklistActions()
}//end class
