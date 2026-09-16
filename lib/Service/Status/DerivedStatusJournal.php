<?php

/**
 * What a derived status move staged, until the save that made it lands.
 *
 * A derived status is set BEFORE the case is written, because the derivation
 * has to reach the same save that made it true. A timeline entry written there
 * would survive a save that then failed, and the timeline would carry a move
 * that never happened. `DerivedStatusListener` says so in its own words and
 * names the shape to reach for: a post-persist listener.
 *
 * This is the half sentence between the two. The pre-persist listener stages
 * what it decided; `DerivedStatusTimelineListener` takes it back after the
 * write and records it. Nothing is stored anywhere: the journal lives for one
 * request, and a staged move that never reaches a successful write is simply
 * never taken.
 *
 * IT IS REGISTERED EXPLICITLY, AND THAT IS THE WHOLE POINT. Two listeners have
 * to see ONE journal, and an autowired class is built again for each of them.
 * `registerService()` memoises, so the registration in
 * `DerivedStatusListenerRegistrar` is what makes staging and taking the same
 * list rather than two empty ones.
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
 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Status;

/**
 * The derived moves this request decided but has not yet recorded.
 *
 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
 */
class DerivedStatusJournal {

	/**
	 * The staged moves, keyed by case id.
	 *
	 * @var array<string, array{from: string, to: string}>
	 */
	private array $staged = [];

	/**
	 * Note that a case is about to be written into a derived status.
	 *
	 * The LAST staging for a case wins. One save runs the derivation once, and
	 * a second derivation on the same case in the same request is a later
	 * decision about the same row, not a second move to record.
	 *
	 * @param string $caseId     The case being written.
	 * @param string $fromStatus The status it carried.
	 * @param string $toStatus   The status the declaration derives.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function stage(string $caseId, string $fromStatus, string $toStatus): void {
		if ($caseId === '' || $toStatus === '' || $fromStatus === $toStatus) {
			return;
		}

		$this->staged[$caseId] = ['from' => $fromStatus, 'to' => $toStatus];
	}//end stage()

	/**
	 * Take back what was staged for a case, and forget it.
	 *
	 * TAKING RATHER THAN READING is deliberate. A staged move must be recorded
	 * at most once, and a save that fails leaves an entry nobody takes, which
	 * the next request never sees because the journal is per request.
	 *
	 * @param string $caseId The case that was written.
	 *
	 * @return array{from: string, to: string}|null The staged move, or null.
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function take(string $caseId): ?array {
		if (isset($this->staged[$caseId]) === false) {
			return null;
		}

		$move = $this->staged[$caseId];
		unset($this->staged[$caseId]);

		return $move;
	}//end take()
}//end class
