<?php

/**
 * A draft case: no clock, no list, its author only, promoted in one act.
 *
 * "A concept-zaak whose Awb clock has not started is a real object in a
 * gemeente, and the alternative is a half-filled case that is already
 * overdue." That is the whole argument. Without a draft state, a handler who
 * starts writing down a request at four o'clock on Friday has created a case
 * whose statutory term began on Friday, and it will read as overdue before
 * anybody has decided it is a case at all.
 *
 * So a draft binds no term, appears in no working list, count or report, and
 * is promoted in one act that binds the term and makes it a case.
 *
 * 🔑 THE DRAFT'S OWN MOMENT SURVIVES THE PROMOTION. `draftCreatedAt` is kept
 * on the case afterwards, because when the aanvraag arrived is a fact somebody
 * will ask about and the promotion moment is not it. The statutory term still
 * runs from the promotion, which is when the gemeente accepted it.
 *
 * 🔴 WHAT ENFORCES THE PRIVACY, EXACTLY. Two things, and neither is a new
 * permission model. The lists exclude `isDraft: true`, so a draft is in
 * nobody's queue, count or report. And dossiq's own case endpoints already go
 * through `CaseAccessGuard`, which grants a case to its assignee, its
 * assignees and an administrator and denies everyone else; a draft is created
 * assigned to its author, so those endpoints answer the author alone. What
 * this change does NOT add is a per-object grant inside OpenRegister: a reader
 * who queries OpenRegister directly, with a filter that names drafts, can see
 * one. That half belongs with the grants gateway, and is named in the PR
 * rather than implied to be done.
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

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;

/**
 * Begins a draft case and promotes it into a real one.
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */
class DraftCaseActs {

	/**
	 * Whether the case is still a draft.
	 *
	 * @var string
	 */
	public const DRAFT_FIELD = 'isDraft';

	/**
	 * When the draft was begun.
	 *
	 * @var string
	 */
	public const BEGUN_FIELD = 'draftCreatedAt';

	/**
	 * Constructor.
	 *
	 * @param CaseStatusStore $store Reads and writes the case.
	 * @param CaseJournal $journal The case's own record, and the actor.
	 */
	public function __construct(
		private readonly CaseStatusStore $store,
		private readonly CaseJournal $journal,
	) {
	}//end __construct()

	/**
	 * Mark an existing case as a draft, unbinding its term.
	 *
	 * Written as a mark rather than a create because creating a case is the
	 * index form's job and belongs in one place. What the draft act owns is
	 * the three fields that make it a draft, and above all the CLEARING of the
	 * dates: a case created through the ordinary form already has a startDate,
	 * and a draft that kept it would be counting down.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed> How the case now reads.
	 *
	 * @throws RefusedException When the case cannot be read or is already ended.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function begin(string $caseId): array {
		$case = $this->load(caseId: $caseId);

		$case[self::DRAFT_FIELD] = true;
		$case[self::BEGUN_FIELD] = (new DateTimeImmutable())->format('c');
		$case['startDate'] = null;
		$case['deadline'] = null;
		$case['plannedEndDate'] = null;
		if (trim((string)($case['assignee'] ?? '')) === '') {
			// A draft with no assignee is a draft nobody owns, which is a
			// draft nobody can reach: `CaseAccessGuard` grants by assignee.
			$case['assignee'] = $this->journal->actor();
		}

		$case = $this->journal->append(case: $case, entry: ['type' => 'draft']);
		$this->store->saveCase(case: $case);

		return [
			'caseId' => $caseId,
			'draft' => true,
			'draftCreatedAt' => $case[self::BEGUN_FIELD],
		];
	}//end begin()

	/**
	 * Promote a draft into a case: one act that binds the term.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed> The bound term and the kept draft moment.
	 *
	 * @throws RefusedException When the case is not a draft.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function promote(string $caseId): array {
		$case = $this->load(caseId: $caseId);
		if ($this->isDraft(case: $case) === false) {
			throw new RefusedException(
				rule: 'case-not-a-draft',
				sentence: 'This case is not a draft.',
				status: RefusedException::STATUS_REFUSED,
			);
		}

		$start = (new DateTimeImmutable())->format('Y-m-d');

		$case[self::DRAFT_FIELD] = false;
		$case['startDate'] = $start;
		// `deadline` is a declared calculation over startDate and the case
		// type's processingDeadline, resolved by OpenRegister's save-time
		// listener. Writing a date here would be a second answer to a
		// question the register already answers, and the two would disagree
		// the first time a case type's term changed.
		$case = $this->journal->append(
			case: $case,
			entry: ['type' => 'promote', 'draftCreatedAt' => (string)($case[self::BEGUN_FIELD] ?? '')],
		);
		$saved = $this->store->saveCase(case: $case);

		return [
			'caseId' => $caseId,
			'draft' => false,
			'startDate' => $start,
			'deadline' => (string)($saved['deadline'] ?? ''),
			'draftCreatedAt' => (string)($case[self::BEGUN_FIELD] ?? ''),
		];
	}//end promote()

	/**
	 * Whether a case is still a draft.
	 *
	 * @param array<string, mixed> $case The loaded case.
	 *
	 * @return bool True when it is.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function isDraft(array $case): bool {
		return in_array(($case[self::DRAFT_FIELD] ?? false), [true, 1, '1', 'true'], true);
	}//end isDraft()

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
