<?php

/**
 * Dossiq: whether a case records that nothing in it can be made public.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Assessment
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

namespace OCA\Dossiq\Service\Assessment;

use OCA\Dossiq\Service\WOODocumentAssessmentService;
use Throwable;

/**
 * Whether a case RECORDS that nothing in it can be disclosed.
 *
 * Decision D2 (Ruben, 14 October): with nothing to disclose the decision
 * itself is still published. But only when the case records that, never
 * because the disclosable set happens to be empty: a partly public document
 * whose redacted version is not finished also leaves the set empty, and
 * publishing then would publish the decision without the documents it
 * promised.
 *
 * Recorded means one of two things. The search found nothing and the handler
 * noted why ({@see DocumentSearchOutcome}). Or every document under review is
 * assessed and every assessment is `niet_openbaar`, each with its refusal
 * ground. A register that cannot be read records nothing.
 *
 * @spec openspec/changes/woo-stages-are-guarded-by-their-flow/specs/woo-publication-via-opencatalogi/spec.md
 */
class NothingToDisclose {
	/**
	 * Constructor.
	 *
	 * @param WOODocumentAssessmentService $assessments   The documents under review and the outstanding ones.
	 * @param DocumentSearchOutcome        $searchOutcome Whether the case records that nothing was found.
	 */
	public function __construct(
		private readonly WOODocumentAssessmentService $assessments,
		private readonly DocumentSearchOutcome $searchOutcome = new DocumentSearchOutcome(),
	) {
	}//end __construct()

	/**
	 * Whether the case records that nothing in it can be disclosed.
	 *
	 * @param array<string, mixed>             $case        The case.
	 * @param string                           $caseId      The case uuid.
	 * @param array<int, array<string, mixed>> $assessments The case's assessments.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/woo-stages-are-guarded-by-their-flow/specs/woo-publication-via-opencatalogi/spec.md
	 */
	public function recorded(array $case, string $caseId, array $assessments): bool {
		if ($this->searchOutcome->recordsNothingFound(case: $case) === true) {
			return true;
		}

		if ($assessments === []) {
			return false;
		}

		foreach ($assessments as $assessment) {
			if ((string)($assessment['classification'] ?? '') !== 'niet_openbaar') {
				return false;
			}
		}

		try {
			$underReview = $this->assessments->getDocumentsUnderReview(caseId: $caseId);
			$outstanding = (int)($this->assessments->getOutstanding(caseId: $caseId)['count'] ?? 0);
		} catch (Throwable) {
			return false;
		}

		return $underReview !== [] && $outstanding === 0;
	}//end recorded()
}//end class
