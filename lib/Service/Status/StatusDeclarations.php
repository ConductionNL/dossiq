<?php

/**
 * The one door the transition engine knocks on for what a status declares.
 *
 * `StatusTransitionService` already carries ten collaborators, and each of the
 * three declarations this change adds would have been an eleventh, a twelfth
 * and a thirteenth. They arrive together on the same row and they are read at
 * the same two moments — when the engine lists what a handler may do, and when
 * a case moves — so they are one seam rather than three.
 *
 * Nothing here decides a transition. It answers questions about the status the
 * case is in or is entering, and settles the dwell fields on a payload the
 * engine is about to save.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Status
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
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Status;

use DateTimeImmutable;
use OCA\Dossiq\Service\Transitions\StatusTypeLookup;

/**
 * The declarations of the status a case is in, and the dwell bookkeeping.
 *
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */
class StatusDeclarations {

	/**
	 * Constructor.
	 *
	 * @param StatusTypeLookup     $lookup      Both directions of "which status is this".
	 * @param StatusDeclaration    $declaration What a status says about itself.
	 * @param DerivedStatusService $derived     Which status the case type derives.
	 * @param StatusDwellService   $dwell       The numbers the case carries.
	 * @param StatusDwellTimer     $timer       The engine clock behind a maximum.
	 */
	public function __construct(
		private readonly StatusTypeLookup $lookup,
		private readonly StatusDeclaration $declaration,
		private readonly DerivedStatusService $derived,
		private readonly StatusDwellService $dwell,
		private readonly StatusDwellTimer $timer,
	) {
	}//end __construct()

	/**
	 * What the case's current status declares, as the transition list reports it.
	 *
	 * Published beside the transitions rather than on an endpoint of its own,
	 * because it answers the same question the handler is asking at the same
	 * moment: what can I do with this case, and why not the thing I expected.
	 * A second endpoint would be a second round trip for one panel.
	 *
	 * @param array<string, mixed>   $case The case.
	 * @param DateTimeImmutable|null $now  The moment to read at; defaults to now.
	 *
	 * @return array{
	 *     waitingOn: string,
	 *     dwell: array{days: int, maximum: int|null, breached: bool, enteredAt: string},
	 *     derivation: array{statusId: string, name: string, unmet: array<int, string>}|null
	 * }
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	public function panelFor(array $case, ?DateTimeImmutable $now = null): array {
		$statusType = $this->lookup->rowFor(statusTypeId: (string)($case['status'] ?? ''));

		return [
			'waitingOn' => $this->declaration->waitingOn(statusType: $statusType),
			'dwell' => $this->dwell->snapshot(
				case: $case,
				maximum: $this->declaration->maximumDwell(statusType: $statusType),
				now: $now,
			),
			'derivation' => $this->derived->blockingReasonFor(case: $case),
		];
	}//end panelFor()

	/**
	 * Whether a status is one the case type derives rather than offers.
	 *
	 * @param string $caseTypeId   The case type.
	 * @param string $statusTypeId The destination status.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	public function isDerivedStatus(string $caseTypeId, string $statusTypeId): bool {
		return $this->derived->isDerivedStatus(caseTypeId: $caseTypeId, statusTypeId: $statusTypeId);
	}//end isDerivedStatus()

	/**
	 * Settle the dwell fields on a payload about to be saved.
	 *
	 * @param array<string, mixed>   $case     The case payload.
	 * @param string                 $toStatus The status being entered.
	 * @param DateTimeImmutable|null $now      The moment of the move.
	 *
	 * @return array<string, mixed> The payload.
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/doorlooptijd-dashboard/spec.md
	 */
	public function applyStatusChange(array $case, string $toStatus, ?DateTimeImmutable $now = null): array {
		return $this->dwell->applyStatusChange(case: $case, toStatus: $toStatus, now: $now);
	}//end applyStatusChange()

	/**
	 * Stop the clock on the status the case left and start one on the status it
	 * entered, when that status declares a maximum.
	 *
	 * Cancelling comes first and happens unconditionally, including a move into
	 * a status with no maximum: leaving a status has to stop its clock whatever
	 * it moved to, or the next breach names a status the case is no longer in.
	 *
	 * @param string $caseId   The case.
	 * @param string $toStatus The status entered.
	 *
	 * @return string|null The armed timer uuid, or null when nothing was armed.
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	public function retime(string $caseId, string $toStatus): ?string {
		$this->timer->cancel(caseId: $caseId, reason: 'the case left the status this clock was about');

		$statusType = $this->lookup->rowFor(statusTypeId: $toStatus);
		$maximum = $this->declaration->maximumDwell(statusType: $statusType);
		if ($maximum === null) {
			return null;
		}

		return $this->timer->arm(
			caseId: $caseId,
			statusTypeId: $toStatus,
			maximumDwell: $maximum,
			statusName: (string)($statusType['name'] ?? ''),
		);
	}//end retime()
}//end class
