<?php

/**
 * What a case is, and what may be done to it: the one read behind the act menu.
 *
 * 🔑 THE READ IS NOT AN ACT. This class only asks questions; every write on a
 * case lives in {@see CaseActs} and the classes behind it. They were one class
 * until 2026-09-20, and the seam is why: the read needed every act owner at
 * once to compose its answer, so the facade over the writes carried eleven
 * collaborators it used in a single method and was suppressed twice for it.
 * Separating the query from the commands leaves each side with the
 * collaborators it actually uses, and no suppression on either.
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
use OCA\Dossiq\Service\Transitions\CaseStatusStore;

/**
 * The state of a case and the menu that goes with it.
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */
class CaseActsOverview {

	/**
	 * Constructor.
	 *
	 * @param CaseStatusStore $store Reads the case the overview is about.
	 * @param CaseActsMenu $menu What the caller may do, and who the case waits on.
	 * @param CaseEndingActs $endings Whether and how the case ended.
	 * @param CaseHoldActs $holds Whether the case is held.
	 * @param DraftCaseActs $drafts Whether the case is still a draft.
	 * @param CaseIncompleteness $incompleteness What the case is knowingly missing.
	 * @param ProcessOwnedStatusRule $processStatus Whether a status may be hand-set.
	 * @param CaseArchiveState|null $archiveState Reads the platform's archive marker, so the
	 *                                            menu can offer Restore instead of Archive.
	 *                                            Optional, so an instance that predates the
	 *                                            half still answers.
	 */
	public function __construct(
		private readonly CaseStatusStore $store,
		private readonly CaseActsMenu $menu,
		private readonly CaseEndingActs $endings,
		private readonly CaseHoldActs $holds,
		private readonly DraftCaseActs $drafts,
		private readonly CaseIncompleteness $incompleteness,
		private readonly ProcessOwnedStatusRule $processStatus,
		private readonly ?CaseArchiveState $archiveState = null,
	) {
	}//end __construct()

	/**
	 * What this handler may do to this case, with a reason on every refusal.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed> The state and the verdicts.
	 *
	 * @throws RefusedException When the case cannot be read.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function overview(string $caseId): array {
		$case = $this->store->loadCase(caseId: $caseId);
		if ($case === null) {
			throw new RefusedException(
				rule: 'case-not-found',
				sentence: 'This case could not be found.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$missing = $this->incompleteness->missingOn(case: $case);

		// The archive marker, read off the case the overview already loaded.
		// The menu needs it to offer Restore in the place Archive stood: two
		// entries both enabled would let a handler archive a case that is
		// already archived, and the platform answers that with a shrug rather
		// than a refusal, so nothing on screen would say what happened.
		$marker = ($this->archiveState?->markerOn(case: $case) ?? []);

		return array_merge(
			[
				'caseId' => $caseId,
				'archived' => ($marker !== []),
				'archivedAt' => (string)($marker['at'] ?? ''),
				'archivedBy' => (string)($marker['by'] ?? ''),
				'archivedReason' => (string)($marker['reason'] ?? ''),
				'held' => $this->holds->isHeld(case: $case),
				'heldUntil' => (string)($case[CaseHoldActs::UNTIL_FIELD] ?? ''),
				'draft' => $this->drafts->isDraft(case: $case),
				'incomplete' => ($missing !== []),
				'missingFields' => $missing,
				'endingAct' => (string)($case[CaseEndingActs::ENDING_FIELD] ?? ''),
				'ending' => $this->endings->endingOf(case: $case),
				'statusIsHandSettable' => $this->processStatus->allowsHandSet(
					caseTypeId: (string)($case['caseType'] ?? '')
				),
			],
			$this->menu->forCase(caseId: $caseId, case: $case)
		);
	}//end overview()
}//end class
