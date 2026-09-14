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
	 * Reconcile both seats against the team receiving the case.
	 *
	 * The coordinator binding is removed here, because it is a record of its
	 * own. The handler is handed back as a case change instead of written, so
	 * the caller writes the case ONCE with the team and the seat together: two
	 * writes would leave a window in which the case has moved and still names
	 * a handler who cannot open it.
	 *
	 * @param array<string, mixed> $case The stored case, before the move.
	 * @param string               $team The receiving team's Nextcloud group id.
	 *
	 * @return array{emptied: array<int, array<string, string>>, changes: array<string, mixed>}
	 *         What came off the case, and the case fields the caller must write.
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md#requirement-a-case-carries-a-handler-and-a-coordinator-req-hand-05
	 */
	public function reconcile(array $case, string $team): array {
		$caseId = trim((string)($case['id'] ?? ($case['uuid'] ?? '')));
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
			$this->seats->clearCoordinator(caseId: $caseId);
			$emptied[] = [
				'seat' => CaseSeats::COORDINATOR,
				'holder' => $coordinator,
				'reason' => self::NOT_IN_RECEIVING_TEAM,
			];
		}

		return ['emptied' => $emptied, 'changes' => $changes];
	}//end reconcile()
}//end class
