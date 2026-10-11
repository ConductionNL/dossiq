<?php

/**
 * Dossiq batch selection: the documents a review batch takes, listed or by filter.
 *
 * @category Review
 * @package  OCA\Dossiq\Review
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-batches-are-assigned-to-named-reviewers-before-any-verdict-req-wrt-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Review;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Zaakdossier\CaseDocumentProvenance;
use Throwable;

/**
 * Selects the documents of a case for a batch.
 *
 * A filter field is either one the review carries (`rule`, the triage rule
 * that marked the document) or one the document's provenance carries
 * (`custodian`, `sourceSystem`, `source`, see CaseDocumentProvenance). Every
 * field of the filter must hold. A field neither carries is refused: it
 * would answer an empty batch that looks like a real one.
 *
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-batches-are-assigned-to-named-reviewers-before-any-verdict-req-wrt-003
 */
class BatchSelection {

	/**
	 * The filter fields read from the document's review.
	 */
	public const REVIEW_FIELDS = ['rule'];

	/**
	 * Constructor.
	 *
	 * @param DocumentRelevance      $reviews    The reviews of the case's documents.
	 * @param CaseDocumentProvenance $provenance The provenance of the case's documents.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly DocumentRelevance $reviews,
		private readonly CaseDocumentProvenance $provenance,
	) {
	}//end __construct()

	/**
	 * Every field a batch can be taken by.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-batches-are-assigned-to-named-reviewers-before-any-verdict-req-wrt-003
	 */
	public static function fields(): array {
		return array_merge(self::REVIEW_FIELDS, CaseDocumentProvenance::FILTER_FIELDS);
	}//end fields()

	/**
	 * The documents a batch takes: the listed ones, or those matching every field of the filter.
	 *
	 * @param string                $caseId    The case UUID.
	 * @param list<string>          $documents The listed documents; used when no filter is given.
	 * @param array<string, string> $filter    The filter, by field of the review or of the provenance.
	 *
	 * @return list<string> The documents.
	 *
	 * @throws RefusedException When the filter names a field neither carries.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-batches-are-assigned-to-named-reviewers-before-any-verdict-req-wrt-003
	 */
	public function select(string $caseId, array $documents, array $filter): array {
		if ($filter === []) {
			return array_values(array_unique(array_map('strval', $documents)));
		}

		if (array_diff(array_keys($filter), self::fields()) !== []) {
			throw new RefusedException(
				rule: 'review-batch-filter-unknown',
				sentence: 'A batch can be taken by custodian, source system, the rule that marked its documents, or a list of documents.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$byReview = array_intersect_key($filter, array_flip(self::REVIEW_FIELDS));
		$byProvenance = array_diff_key($filter, $byReview);

		$selected = null;
		if ($byProvenance !== []) {
			$selected = $this->byProvenance(caseId: $caseId, filter: $byProvenance);
		}

		if ($byReview !== []) {
			$matching = [];
			foreach ($this->reviews->forCase(caseId: $caseId) as $ref => $review) {
				if ($this->matches(review: $review, filter: $byReview) === true) {
					$matching[] = (string)$ref;
				}
			}

			$selected = $this->intersect(selected: $selected, matching: $matching);
		}

		return ($selected ?? []);
	}//end select()

	/**
	 * The documents whose provenance matches; a provenance that cannot be read refuses the batch.
	 *
	 * @param string                $caseId The case UUID.
	 * @param array<string, string> $filter The provenance part of the filter.
	 *
	 * @return list<string>
	 *
	 * @throws RefusedException When the documents' provenance cannot be read.
	 */
	private function byProvenance(string $caseId, array $filter): array {
		try {
			return $this->provenance->select(caseId: $caseId, filter: $filter);
		} catch (Throwable $e) {
			throw new RefusedException(
				rule: 'review-batch-unavailable',
				sentence: 'The Review batch cannot be stored, so nothing was assigned.',
				status: RefusedException::STATUS_INDETERMINATE,
				previous: $e,
			);
		}
	}//end byProvenance()

	/**
	 * The matching documents, within the earlier selection when there is one.
	 *
	 * @param list<string>|null $selected The earlier selection, or null.
	 * @param list<string>      $matching The documents matching this part of the filter.
	 *
	 * @return list<string>
	 */
	private function intersect(?array $selected, array $matching): array {
		if ($selected === null) {
			return $matching;
		}

		return array_values(array_intersect($selected, $matching));
	}//end intersect()

	/**
	 * Whether a review matches every field of the filter.
	 *
	 * @param array<string, mixed>  $review The review.
	 * @param array<string, string> $filter The filter.
	 *
	 * @return bool True on a match.
	 */
	private function matches(array $review, array $filter): bool {
		foreach ($filter as $field => $value) {
			if ((string)($review[$field] ?? '') !== trim((string)$value)) {
				return false;
			}
		}

		return true;
	}//end matches()
}//end class
