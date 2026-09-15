<?php

/**
 * What happens to the people on a case when the case moves.
 *
 * A handover moves the case to another team, and the people sitting on it may
 * not be in that team. Leaving them there gives the receiving team a case
 * whose handler cannot be reached and whose coordinator answers to a different
 * teamleider. Clearing them silently gives the receiving team a case with
 * nobody on it and no way to find out who came off.
 *
 * 🔑 SO A SEAT IS EMPTIED AND SAID SO. The emptying is written onto the
 * transfer record as `emptiedSeats`, the same record that already carries the
 * reason and the custody trail, which is where somebody asking "who had this
 * before" is already looking.
 *
 * A seat whose holder IS in the receiving team survives the move untouched. A
 * handover between two teams that share people is the common case, and
 * emptying a seat the receiving team was happy with would be work nobody
 * asked for.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transfer
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
 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transfer;

use OCA\Dossiq\Service\People\CaseSeats;

/**
 * Empties the seats the receiving team cannot fill, and reports which.
 *
 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md
 */
class CaseSeatReconciler {

	/**
	 * The one reason a seat is emptied by a handover.
	 *
	 * @var string
	 */
	public const NOT_IN_RECEIVING_TEAM = 'not-in-the-receiving-team';

	/**
	 * Constructor.
	 *
	 * @param CaseSeats     $seats The handler and the coordinator on a case.
	 * @param TeamDirectory $teams Who is in which team.
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md
	 */
	public function __construct(
		private readonly CaseSeats $seats,
		private readonly TeamDirectory $teams,
	) {
	}//end __construct()

	/**
	 * Which seats this move empties. Changes nothing.
	 *
	 * 🔑 PLANNING AND APPLYING ARE TWO CALLS, AND THE REASON IS NOT TIDINESS.
	 * The plan has to be on the transfer record before anything comes off the
	 * case: a version that cleared the coordinator first and then failed to
	 * write the record left a case with an empty seat, nobody named on it and
	 * no trace of who had been. Plan, record, then apply, so the only failure
	 * left is a seat that stayed filled with the record saying it should not
	 * have, which is visible rather than silent.
	 *
	 * The handler is reported as a case CHANGE rather than written, so the
	 * caller writes the case once with the team and the seat together. Two
	 * writes would leave a window in which the case has moved and still names
	 * a handler who cannot open it.
	 *
	 * @param array<string, mixed> $case The stored case, before the move.
	 * @param string               $team The receiving team's Nextcloud group id.
	 *
	 * @return array{emptied: array<int, array<string, string>>, changes: array<string, mixed>}
	 *         What will come off the case, and the case fields the caller must write.
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md#requirement-a-case-carries-a-handler-and-a-coordinator-req-hand-05
	 */
	public function plan(array $case, string $team): array {
		$seats = $this->seats->seatsOf(case: $case);

		$emptied = [];
		$changes = [];

		$handler = $seats['handler'];
		if ($handler !== '' && $this->teams->holds(uid: $handler, team: $team) === false) {
			$changes['assignee'] = '';
			$emptied[] = [
				'seat' => CaseSeats::HANDLER,
				'holder' => $handler,
				'reason' => self::NOT_IN_RECEIVING_TEAM,
			];
		}

		$coordinator = $seats['coordinator'];
		if ($coordinator !== '' && $this->teams->holds(uid: $coordinator, team: $team) === false) {
			$emptied[] = [
				'seat' => CaseSeats::COORDINATOR,
				'holder' => $coordinator,
				'reason' => self::NOT_IN_RECEIVING_TEAM,
			];
		}

		return ['emptied' => $emptied, 'changes' => $changes];
	}//end plan()

	/**
	 * Carry out the plan, once the handover has been recorded.
	 *
	 * Only the coordinator is touched here. The handler seat travels in the
	 * one case write the caller makes, for the reason `plan()` gives.
	 *
	 * @param string                          $caseId  The case uuid.
	 * @param array<int, array<string, string>> $emptied The plan's `emptied` list.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md#requirement-a-case-carries-a-handler-and-a-coordinator-req-hand-05
	 */
	public function apply(string $caseId, array $emptied): void {
		if ($caseId === '') {
			return;
		}

		foreach ($emptied as $seat) {
			if (($seat['seat'] ?? '') === CaseSeats::COORDINATOR) {
				$this->seats->clearCoordinator(caseId: $caseId);
			}
		}
	}//end apply()
}//end class
