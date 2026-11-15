<?php

/**
 * Dossiq review writes
 *
 * What a person writes on a document's review: its relevance and the pages
 * they displayed. A review is private to the people on its case, so one that
 * such a write creates is shared with them in the same request.
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
 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-rule-of-the-case-reads-the-whole-case-req-cra-011
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Review;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Kernel\Contracts\CaseReviewerAccessInterface;
use OCA\Dossiq\Kernel\Contracts\ReviewWritesInterface;

/**
 * A person's writes on a review, each followed by the grants a new review needs.
 *
 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-rule-of-the-case-reads-the-whole-case-req-cra-011
 */
class ReviewWrites implements ReviewWritesInterface {

	/**
	 * Constructor.
	 *
	 * @param DocumentRelevance  $reviews        The reviews.
	 * @param CaseReviewerAccessInterface $reviewerAccess Shares a review that was just created with the people on its case.
	 * @param ReviewConfiguration|null    $configuration  Whether the case type switches document review on.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly DocumentRelevance $reviews,
		private readonly CaseReviewerAccessInterface $reviewerAccess,
		private readonly ?ReviewConfiguration $configuration = null,
	) {
	}//end __construct()

	/**
	 * Mark a document's relevance as this person.
	 *
	 * @param string $caseId      The case UUID.
	 * @param string $documentRef The document.
	 * @param string $relevance   `in-scope`, `out-of-scope` or `unmarked`.
	 * @param string $userId      The person.
	 *
	 * @return array<string, mixed> The saved review.
	 *
	 * @throws RefusedException When the relevance is unknown or the review cannot be written.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
	 */
	public function mark(string $caseId, string $documentRef, string $relevance, string $userId): array {
		$isNew = $this->hasNoReview(caseId: $caseId, documentRef: $documentRef);
		$review = $this->reviews->mark(caseId: $caseId, documentRef: $documentRef, relevance: $relevance, userId: $userId);
		$this->reviewerAccess->reconcileAfterCreate(caseId: $caseId, created: (int)$isNew);

		return $review;
	}//end mark()

	/**
	 * Open an unmarked review for each document just added to a case, then share the new reviews.
	 *
	 * Only on a case whose type switches document review on, and only when the
	 * review schema is configured: without it a document reads as unmarked all
	 * the same. A document that already has a review keeps it, whoever can read
	 * that review; the reconcile runs only when a review was created, in the
	 * same request, so the people on the case read it at once.
	 *
	 * @param string       $caseId       The case UUID.
	 * @param list<string> $documentRefs The documents just added.
	 *
	 * @return int How many reviews were created.
	 *
	 * @throws RefusedException When a review cannot be written.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-relevance-is-marked-apart-from-the-verdict-and-reported-req-wrt-001
	 */
	public function openReviews(string $caseId, array $documentRefs): int {
		if ($this->configuration !== null && ($this->configuration->forCase(caseId: $caseId)['enabled'] ?? false) !== true) {
			return 0;
		}

		$created = 0;
		if ($this->reviews->isAvailable() === true && $caseId !== '') {
			$reviews = $this->reviews->forCase(caseId: $caseId);
			foreach (array_unique($documentRefs) as $documentRef) {
				$documentRef = (string)$documentRef;
				if ($documentRef === '' || isset($reviews[$documentRef]) === true) {
					continue;
				}

				$this->reviews->save(review: ['case' => $caseId, 'documentRef' => $documentRef, 'relevance' => DocumentRelevance::UNMARKED]);
				$reviews[$documentRef] = ['documentRef' => $documentRef];
				$created++;
			}
		}

		$this->reviewerAccess->reconcileAfterCreate(caseId: $caseId, created: $created);

		return $created;
	}//end openReviews()

	/**
	 * Record the pages this person displayed, and say which required pages nobody has seen yet.
	 *
	 * @param string    $caseId      The case UUID.
	 * @param string    $documentRef The document.
	 * @param list<int> $pages       The pages displayed.
	 * @param int|null  $pageCount   The document's page count, when the viewer reports it.
	 * @param string    $userId      The person.
	 *
	 * @return array<string, mixed> The saved review, with `unseen`.
	 *
	 * @throws RefusedException When no page is given or the review cannot be written.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-nothing-is-decided-or-published-before-the-required-pages-are-seen-req-wrt-005
	 */
	public function pagesSeen(string $caseId, string $documentRef, array $pages, ?int $pageCount, string $userId): array {
		$isNew = $this->hasNoReview(caseId: $caseId, documentRef: $documentRef);
		$pagesSeen = $this->reviews->pages();
		$review = $pagesSeen->record(caseId: $caseId, documentRef: $documentRef, pages: $pages, pageCount: $pageCount, userId: $userId);
		$this->reviewerAccess->reconcileAfterCreate(caseId: $caseId, created: (int)$isNew);

		return ($review + ['unseen' => $pagesSeen->unseenPages(review: $review)]);
	}//end pagesSeen()

	/**
	 * Share every private record of a case with the people on it, after a rule of the case was stored.
	 *
	 * A stopping rule is private to its case (REQ-WRT-006); reconciling right
	 * after it is stored lets the assignee the case type grants it to read and
	 * update it in the same request.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array{status: string, granted: int, revoked: int, refused: int} What the reconcile did.
	 *
	 * @spec openspec/changes/woo-review-recall-and-stopping/tasks.md#1-the-stopping-rule
	 */
	public function ruleStored(string $caseId): array {
		return $this->reviewerAccess->reconcile(caseId: $caseId);
	}//end ruleStored()

	/**
	 * Whether the document has no review yet, so the write about to happen creates one.
	 *
	 * Asked of the whole case: a review the person cannot read is still a review.
	 *
	 * @param string $caseId      The case UUID.
	 * @param string $documentRef The document.
	 *
	 * @return bool
	 */
	private function hasNoReview(string $caseId, string $documentRef): bool {
		return isset($this->reviews->forCase(caseId: $caseId)[$documentRef]) === false;
	}//end hasNoReview()
}//end class
