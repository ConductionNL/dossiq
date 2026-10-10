<?php

/**
 * Dossiq Woo review: the case summary of relevance beside the verdicts.
 *
 * @category Woo
 * @package  OCA\Dossiq\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-relevance-is-marked-apart-from-the-verdict-and-reported-req-wrt-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\WOODocumentAssessmentService;

/**
 * Reports a Woo case's documents by relevance and by verdict, and what is still outstanding.
 *
 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-relevance-is-marked-apart-from-the-verdict-and-reported-req-wrt-001
 */
class WooReviewSummary {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings and OpenRegister access.
	 * @param WooDocumentReviews $reviews The relevance store.
	 * @param WOODocumentAssessmentService $assessments The verdicts and the outstanding list.
	 * @param WooCaseDocuments $caseDocuments Where a case's documents are.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly WooDocumentReviews $reviews,
		private readonly WOODocumentAssessmentService $assessments,
		private readonly WooCaseDocuments $caseDocuments,
	) {
	}//end __construct()

	/**
	 * The summary of one Woo case.
	 *
	 * @param string $caseId The Woo case UUID.
	 *
	 * @return array<string, mixed> `documents`, `relevance` (unmarked, inScope, outOfScope), `verdicts` and `outstanding`.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-relevance-is-marked-apart-from-the-verdict-and-reported-req-wrt-001
	 */
	public function forCase(string $caseId): array {
		$documents = $this->caseDocuments->idsFor(caseId: $caseId);
		$reviews = $this->reviews->forCase(caseId: $caseId);
		$verdicts = ['openbaar' => 0, 'deels_openbaar' => 0, 'niet_openbaar' => 0, 'total' => 0];
		foreach ($this->assessmentRows(caseId: $caseId) as $assessment) {
			$classification = (string)($assessment['classification'] ?? '');
			if (isset($verdicts[$classification]) === true && in_array((string)($assessment['documentRef'] ?? ''), $documents, true) === true) {
				$verdicts[$classification]++;
				$verdicts['total']++;
			}
		}

		return [
			'documents' => count($documents),
			'relevance' => $this->reviews->count(documentRefs: $documents, reviews: $reviews),
			'verdicts' => $verdicts,
			'outstanding' => $this->assessments->getOutstanding(caseId: $caseId),
		];
	}//end forCase()

	/**
	 * The case's assessments the caller may read.
	 *
	 * @param string $caseId The Woo case UUID.
	 *
	 * @return array<int, array<string, mixed>> The assessments.
	 */
	private function assessmentRows(string $caseId): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('woo_assessment_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return [];
		}

		return $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: ['caseRef' => $caseId, '_limit' => 1000],
		);
	}//end assessmentRows()
}//end class
