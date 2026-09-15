<?php

/**
 * Hold and park: a reason, a wake date, and no effect on any statutory term.
 *
 * Znuny's Pending is a state with a time. Held means the case is deliberately
 * not being worked, with a reason somebody wrote down, and it comes back on
 * its date.
 *
 * 🔴 A HOLD DOES NOT STOP THE CLOCK, AND THAT IS THE WHOLE RISK OF THE
 * FEATURE. Only an opschorting under Awb 4:5 pauses a statutory term, and
 * dossiq already has that act: `CaseLifecycleService::suspend()`, which goes
 * through the TermijnInstance and is refused by a case type that does not
 * allow suspension. A hold that quietly stopped a term would look identical to
 * the handler and be indefensible afterwards, so this class touches no term,
 * no deadline and no TermijnInstance at all. Two fields and a journal entry.
 *
 * 🔑 A HELD CASE STAYS IN EVERY LIST, MARKED. Taking it out of the queue is
 * the obvious implementation and the wrong one: a parked case that nobody can
 * see is a case that gets forgotten rather than parked, and the wake date has
 * nothing to wake it into. It is listed, it reads held until its date, and on
 * that date the marker simply stops applying, which is why nothing has to run
 * overnight to bring it back.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Lifecycle;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;

/**
 * Puts a case on hold until a date, and takes it off again.
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */
class CaseHoldActs {

	/**
	 * The date a held case returns on.
	 *
	 * @var string
	 */
	public const UNTIL_FIELD = 'heldUntil';

	/**
	 * Why it is held.
	 *
	 * @var string
	 */
	public const REASON_FIELD = 'holdReason';

	/**
	 * Constructor.
	 *
	 * @param CaseStatusStore $store Reads and writes the case.
	 * @param CaseJournal $journal The case's own record.
	 * @param CaseDateNormaliser $dates The ONE class allowed to parse and zone a date.
	 */
	public function __construct(
		private readonly CaseStatusStore $store,
		private readonly CaseJournal $journal,
		private readonly CaseDateNormaliser $dates,
	) {
	}//end __construct()

	/**
	 * Put the case on hold until a date.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $reason Why it is not being worked.
	 * @param string $until The date it returns, as Y-m-d.
	 *
	 * @return array<string, mixed> The hold as it was recorded.
	 *
	 * @throws RefusedException When the reason is empty, the date is unreadable,
	 *                          or the date is not in the future.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function hold(string $caseId, string $reason, string $until): array {
		$case = $this->load(caseId: $caseId);
		if (trim($reason) === '') {
			throw new RefusedException(
				rule: 'reason-required',
				sentence: 'Say why the case is being held.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$wake = $this->wakeDate(named: $until);

		$case[self::UNTIL_FIELD] = $wake;
		$case[self::REASON_FIELD] = $reason;
		$case = $this->journal->append(
			case: $case,
			entry: ['type' => 'hold', 'reason' => $reason, 'until' => $wake],
		);
		$this->store->saveCase(case: $case);

		return [
			'caseId' => $caseId,
			'held' => true,
			'heldUntil' => $wake,
			'holdReason' => $reason,
			// Named in the answer because it is the property somebody will
			// doubt, and the answer is the cheapest place to settle it.
			'deadline' => (string)($case['deadline'] ?? ($case['plannedEndDate'] ?? '')),
		];
	}//end hold()

	/**
	 * Take the case off hold before its date.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $reason Why it is being picked up again.
	 *
	 * @return array<string, mixed> The case's hold state afterwards.
	 *
	 * @throws RefusedException When the case is not held.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function release(string $caseId, string $reason): array {
		$case = $this->load(caseId: $caseId);
		if ($this->isHeld(case: $case) === false) {
			throw new RefusedException(
				rule: 'case-not-held',
				sentence: 'This case is not on hold.',
				status: RefusedException::STATUS_REFUSED,
			);
		}

		$case[self::UNTIL_FIELD] = null;
		$case[self::REASON_FIELD] = '';
		$case = $this->journal->append(case: $case, entry: ['type' => 'release', 'reason' => $reason]);
		$this->store->saveCase(case: $case);

		return ['caseId' => $caseId, 'held' => false];
	}//end release()

	/**
	 * Whether the case is held right now.
	 *
	 * By DATE, not by a flag. A flag would need something to clear it, and the
	 * thing that clears it is exactly what would fail silently overnight; a
	 * date that has passed simply stops being in the future.
	 *
	 * @param array<string, mixed> $case The loaded case.
	 *
	 * @return bool True when the wake date is still ahead.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function isHeld(array $case): bool {
		$wake = $this->dates->tryParse(value: ($case[self::UNTIL_FIELD] ?? ''));
		if ($wake === null) {
			// An unreadable date reads as NOT held. Painting the marker off a
			// value nobody can parse would put a state on the case that no act
			// put there.
			return false;
		}

		return ($wake > $this->dates->today());
	}//end isHeld()

	/**
	 * The wake date a hold lands on.
	 *
	 * @param string $named The date the caller gave.
	 *
	 * @return string The date as Y-m-d.
	 *
	 * @throws RefusedException When it is missing, unreadable, or not ahead.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	private function wakeDate(string $named): string {
		if (trim($named) === '') {
			throw new RefusedException(
				rule: 'wake-date-required',
				sentence: 'Say when the case comes back.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$wake = $this->dates->tryParse(value: $named);
		if ($wake === null) {
			throw new RefusedException(
				rule: 'wake-date-unreadable',
				sentence: 'That date could not be read.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		if ($wake <= $this->dates->today()) {
			// A hold that is already over is not a hold; it is a reason
			// written onto a case nobody parked, and it would read as held
			// nowhere while claiming to be an act that happened.
			throw new RefusedException(
				rule: 'wake-date-not-ahead',
				sentence: 'A hold has to end on a future date.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		return $wake->format('Y-m-d');
	}//end wakeDate()

	/**
	 * Load the case, or refuse.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed> The case.
	 *
	 * @throws RefusedException When the case cannot be read.
	 *
	 * @spec exclude one read behind both acts in this class
	 */
	private function load(string $caseId): array {
		$case = $this->store->loadCase(caseId: $caseId);
		if ($case === null) {
			throw new RefusedException(
				rule: 'case-not-found',
				sentence: 'This case could not be found.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		return $case;
	}//end load()
}//end class
