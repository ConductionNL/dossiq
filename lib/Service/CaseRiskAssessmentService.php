<?php

/**
 * Dossiq case risk assessment service.
 *
 * REQ-MRK-02: a case may carry a `riskAssessment` with a level, the ground it
 * rests on, the assessor, the assessment date and a review date, readable only
 * by a reader holding a permission beyond the permission to read the case.
 *
 * WHAT THIS CLASS DOES NOT DO IS CHECK THAT PERMISSION. The rule is declared on
 * the property in `register.d/38-markers-and-assessments.json`, in
 * OpenRegister's `row-field-level-security` vocabulary, and `PropertyRbacHandler`
 * enforces it while the object is rendered. `CitizenLookupGuard` is what the
 * other answer looks like, and `sensitive-fields-declared` is already moving
 * that class of check out of dossiq (design D-4). A hand-written check here
 * would be the next CitizenLookupGuard, so there is not one: a reader without
 * the group never sees the block, and this class is only ever handed what the
 * platform already decided to show.
 *
 * TWO THINGS ARE DERIVED AND NEITHER IS STORED AS A JUDGEMENT.
 *
 *   `dueForReview` is answered at READ time against today, because an
 *   assessment does not become stale when somebody saves the case. Stamping it
 *   on write would mean an assessment whose review date passed last month
 *   still reading as current until the next unrelated edit.
 *
 *   The impact a level implies is a MAP, not a second priority vocabulary.
 *   Four priority words is the defect `case-priority-impact-urgency` D-6
 *   names, so the level feeds the impact axis that change already defines and
 *   `CasePriorityService` reads the map from here (design D-5).
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use Throwable;

/**
 * What a case's assessed risk level is, and what it feeds.
 *
 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
 */
class CaseRiskAssessmentService {

	/**
	 * The assessed levels, lowest first.
	 *
	 * Deliberately NOT the priority vocabulary. A risk level and a priority
	 * are different questions, and giving them the same four words is how a
	 * fifth priority gets written by accident.
	 *
	 * @var array<int, string>
	 */
	public const LEVELS = ['low', 'medium', 'high', 'critical'];

	/**
	 * The impact each assessed level implies.
	 *
	 * `CasePriorityService` reads this map as a constant rather than through a
	 * dependency, because it is a declaration and not behaviour: the level is
	 * one input to the matrix that already derives the priority, and the
	 * matrix stays the only place a priority is decided.
	 *
	 * `critical` and `high` both mean high impact. The impact axis has three
	 * values and the level has four, and collapsing the top pair is the honest
	 * reading: a critical case is not more than the highest impact the matrix
	 * can take, it is a high-impact case somebody wants to find.
	 *
	 * @var array<string, string>
	 */
	public const LEVEL_IMPACT = [
		'low' => 'low',
		'medium' => 'medium',
		'high' => 'high',
		'critical' => 'high',
	];

	/**
	 * The group a reader needs beyond the permission to read the case.
	 *
	 * Held here so the declaration in the register fragment and the sentence a
	 * refusal shows a handler cannot drift apart. Nothing in this class
	 * CHECKS it: OpenRegister does, from the property declaration.
	 */
	public const READER_GROUP = 'dossiq-risk-assessment';

	/**
	 * The assessment on a case, as a reader sees it.
	 *
	 * Answers an empty block for a case with no assessment AND for a reader
	 * the platform filtered the property out for. Those two are deliberately
	 * the same answer: a surface that could tell them apart would report the
	 * existence of an assessment to somebody not allowed to read it.
	 *
	 * @param array<string, mixed>   $case The case as this reader sees it.
	 * @param DateTimeImmutable|null $now  Today, for the review question.
	 *
	 * @return array{present: bool, level: string, ground: string, assessor: string, assessedAt: string, reviewDate: string, dueForReview: bool} The assessment.
	 *
	 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
	 */
	public function describe(array $case, ?DateTimeImmutable $now = null): array {
		$assessment = ($case['riskAssessment'] ?? []);
		if (is_array($assessment) === false) {
			$assessment = [];
		}

		$level = $this->levelOf(assessment: $assessment);
		$reviewDate = trim((string)($assessment['reviewDate'] ?? ''));

		return [
			'present' => ($level !== ''),
			'level' => $level,
			'ground' => trim((string)($assessment['ground'] ?? '')),
			'assessor' => trim((string)($assessment['assessor'] ?? '')),
			'assessedAt' => trim((string)($assessment['assessedAt'] ?? '')),
			'reviewDate' => $reviewDate,
			'dueForReview' => $this->isDueForReview(reviewDate: $reviewDate, now: $now),
		];
	}//end describe()

	/**
	 * The assessed level on a case, or the empty string.
	 *
	 * @param array<string, mixed> $assessment The stored assessment block.
	 *
	 * @return string One of LEVELS, or the empty string.
	 *
	 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
	 */
	public function levelOf(array $assessment): string {
		$level = trim((string)($assessment['level'] ?? ''));
		if (in_array($level, self::LEVELS, true) === true) {
			return $level;
		}

		return '';
	}//end levelOf()

	/**
	 * Whether an assessment is due to be looked at again.
	 *
	 * A review date that has PASSED makes it due. A review date of today does
	 * not: the day named is the day it is still good for, which is how every
	 * other term in this app reads a date.
	 *
	 * @param string                 $reviewDate The declared review date, or the empty string.
	 * @param DateTimeImmutable|null $now        Today.
	 *
	 * @return boolean True when the review date has passed.
	 *
	 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
	 */
	public function isDueForReview(string $reviewDate, ?DateTimeImmutable $now = null): bool {
		$reviewDate = trim($reviewDate);
		if ($reviewDate === '') {
			return false;
		}

		try {
			$review = new DateTimeImmutable($reviewDate);
		} catch (Throwable) {
			// An unreadable date is not evidence that anything is stale, and
			// marking every such assessment due for review would make the
			// signal meaningless on the first bad import.
			return false;
		}

		$today = ($now ?? new DateTimeImmutable());

		return ($review->format('Y-m-d') < $today->format('Y-m-d'));
	}//end isDueForReview()

	/**
	 * The impact half the matrix should read for this case.
	 *
	 * Answers the empty string when the case has no assessment, which is the
	 * signal to `CasePriorityService` to read the impact field instead. A case
	 * type that declared `impactFromRisk` and holds no assessment falls back
	 * rather than deriving from nothing: a case with no priority at all drops
	 * out of every sorted list.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return string One of the impact values, or the empty string.
	 *
	 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
	 */
	public function impactFor(array $case): string {
		$assessment = ($case['riskAssessment'] ?? []);
		if (is_array($assessment) === false) {
			return '';
		}

		$level = $this->levelOf(assessment: $assessment);
		if ($level === '') {
			return '';
		}

		return (string)(self::LEVEL_IMPACT[$level] ?? '');
	}//end impactFor()

	/**
	 * The top-level mirror of the level, for the save that is being written.
	 *
	 * A facet cannot be taken over a nested object property, so the work list
	 * filters on `riskLevel` beside the assessment. It is derived here on
	 * every save rather than typed, so the mirror and the assessment cannot
	 * disagree, and it sits behind the same declared rule so it is absent for
	 * a reader without the permission rather than blanked in a column they can
	 * see.
	 *
	 * @param array<string, mixed> $case The case being saved.
	 *
	 * @return array{riskLevel: string|null} The field to write back.
	 *
	 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
	 */
	public function resolve(array $case): array {
		$assessment = ($case['riskAssessment'] ?? []);
		if (is_array($assessment) === false) {
			$assessment = [];
		}

		$level = $this->levelOf(assessment: $assessment);

		return ['riskLevel' => ($level === '') ? null : $level];
	}//end resolve()
}//end class
