<?php

/**
 * Dossiq Woo pages seen: which required pages of a document a reviewer has displayed.
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
 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-nothing-is-decided-or-published-before-the-required-pages-are-seen-req-wrt-005
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Dossiq\Exception\RefusedException;

/**
 * Records the pages a reviewer displayed on a document's review, and answers which required pages are still unseen.
 *
 * A verdict, a decision and a publication each wait on the same answer, so
 * the bulk assessment, the OpenRegister write guard, the decision and the
 * publication all ask this class.
 *
 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-nothing-is-decided-or-published-before-the-required-pages-are-seen-req-wrt-005
 */
class WooPagesSeen {

	/**
	 * Constructor.
	 *
	 * @param WooDocumentReviews $reviews The reviews that hold the required and seen pages.
	 * @param WooReviewDepth $depth Draws the required pages once the page count is known.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly WooDocumentReviews $reviews,
		private readonly WooReviewDepth $depth,
	) {
	}//end __construct()

	/**
	 * A reviewer saw these pages: append each one not yet recorded for them, with the time.
	 *
	 * The viewer also reports the page count. The first count a review
	 * hears sets `pageCount`, and the required pages are then drawn again
	 * from the recorded depth, so a sample keeps its seed.
	 *
	 * @param string $caseId The Woo case UUID.
	 * @param string $documentRef The document.
	 * @param list<int> $pages The pages displayed.
	 * @param int|null $pageCount The document's page count, when the viewer knows it.
	 * @param string $userId The reviewer.
	 *
	 * @return array<string, mixed> The saved review.
	 *
	 * @throws RefusedException When no page is given or the review cannot be written.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-nothing-is-decided-or-published-before-the-required-pages-are-seen-req-wrt-005
	 */
	public function record(string $caseId, string $documentRef, array $pages, ?int $pageCount, string $userId): array {
		$pages = array_values(array_unique(array_filter($pages, static fn (int $page): bool => $page >= 1)));
		if ($pages === []) {
			throw new RefusedException(
				rule: 'woo-pages-required',
				sentence: 'Name the pages that were displayed.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$fresh = ['case' => $caseId, 'documentRef' => $documentRef, 'relevance' => WooDocumentReviews::UNMARKED];
		$review = ($this->reviews->forCase(caseId: $caseId)[$documentRef] ?? $fresh);
		if ($pageCount !== null && $pageCount >= 1 && isset($review['pageCount']) === false) {
			$review['pageCount'] = $pageCount;
			$review['pagesRequired'] = $this->depth->pagesRequired(depth: (array)($review['depth'] ?? []), pageCount: $pageCount);
		}

		$seen = array_values((array)($review['pagesSeen'] ?? []));
		$known = [];
		foreach ($seen as $entry) {
			$known[(string)($entry['by'] ?? '').':'.(int)($entry['page'] ?? 0)] = true;
		}

		$now = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
		foreach ($pages as $page) {
			if (isset($known[$userId.':'.$page]) === false) {
				$seen[] = ['page' => $page, 'by' => $userId, 'at' => $now];
			}
		}

		$review['pagesSeen'] = $seen;

		return $this->reviews->save(review: $review);
	}//end record()

	/**
	 * The required pages of a review that nobody has seen yet.
	 *
	 * @param array<string, mixed> $review The review.
	 *
	 * @return list<int> The unseen required pages, ascending.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-nothing-is-decided-or-published-before-the-required-pages-are-seen-req-wrt-005
	 */
	public function unseenPages(array $review): array {
		$seen = [];
		foreach ((array)($review['pagesSeen'] ?? []) as $entry) {
			$seen[(int)($entry['page'] ?? 0)] = true;
		}

		$required = array_map('intval', (array)($review['pagesRequired'] ?? []));
		$unseen = array_values(array_filter($required, static fn (int $page): bool => isset($seen[$page]) === false));
		sort($unseen);

		return $unseen;
	}//end unseenPages()

	/**
	 * The required pages of an in-scope document nobody has seen yet; empty for any other document.
	 *
	 * @param string $caseId The Woo case UUID.
	 * @param string $documentRef The document.
	 *
	 * @return list<int> The unseen required pages.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-nothing-is-decided-or-published-before-the-required-pages-are-seen-req-wrt-005
	 */
	public function unseenFor(string $caseId, string $documentRef): array {
		$review = ($this->reviews->forCase(caseId: $caseId)[$documentRef] ?? []);
		if (($review['relevance'] ?? WooDocumentReviews::UNMARKED) !== WooDocumentReviews::IN_SCOPE) {
			return [];
		}

		return $this->unseenPages(review: $review);
	}//end unseenFor()

	/**
	 * Every in-scope document of a case with required pages nobody has seen yet.
	 *
	 * @param string $caseId The Woo case UUID.
	 *
	 * @return array<string, list<int>> The unseen pages by document.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-nothing-is-decided-or-published-before-the-required-pages-are-seen-req-wrt-005
	 */
	public function unseenInCase(string $caseId): array {
		if ($this->reviews->isAvailable() === false) {
			return [];
		}

		$unseen = [];
		foreach ($this->reviews->forCase(caseId: $caseId) as $ref => $review) {
			if (($review['relevance'] ?? WooDocumentReviews::UNMARKED) !== WooDocumentReviews::IN_SCOPE) {
				continue;
			}

			$pages = $this->unseenPages(review: $review);
			if ($pages !== []) {
				$unseen[(string)$ref] = $pages;
			}
		}

		return $unseen;
	}//end unseenInCase()

	/**
	 * The sentence naming the pages still to be seen.
	 *
	 * @param list<int> $pages The unseen pages.
	 *
	 * @return string The sentence.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-nothing-is-decided-or-published-before-the-required-pages-are-seen-req-wrt-005
	 */
	public function sentence(array $pages): string {
		return 'Open these pages before giving a verdict: ' . implode(', ', $pages) . '.';
	}//end sentence()

	/**
	 * Refuse a decision while any in-scope document of the case has unseen required pages.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return void
	 *
	 * @throws RefusedException With 409 while a required page is unseen.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-nothing-is-decided-or-published-before-the-required-pages-are-seen-req-wrt-005
	 */
	public function assertAllSeen(string $caseId): void {
		if ($this->unseenInCase(caseId: $caseId) !== []) {
			throw new RefusedException(
				rule: 'woo-pages-unseen',
				sentence: 'The decision waits until every required page has been seen.',
				status: RefusedException::STATUS_REFUSED,
			);
		}
	}//end assertAllSeen()

	/**
	 * The refusal of a verdict on an in-scope document whose required pages are not all seen.
	 *
	 * @param string $caseId The case UUID.
	 * @param array<string, mixed> $assessment The assessment.
	 *
	 * @return array<string, string> `['pagesSeen' => sentence]`, or empty.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-nothing-is-decided-or-published-before-the-required-pages-are-seen-req-wrt-005
	 */
	public function refusalFor(string $caseId, array $assessment): array {
		if (empty($assessment['classification']) === true || empty($assessment['documentRef']) === true) {
			return [];
		}

		$unseen = $this->unseenFor(caseId: $caseId, documentRef: (string)$assessment['documentRef']);
		if ($unseen === []) {
			return [];
		}

		return ['pagesSeen' => $this->sentence(pages: $unseen)];
	}//end refusalFor()
}//end class
