<?php

/**
 * Dossiq Woo review controller: relevance and the case summary.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Woo\WooDocumentReviews;
use OCA\Dossiq\Woo\WooPagesSeen;
use OCA\Dossiq\Woo\WooReviewBatches;
use OCA\Dossiq\Woo\WooReviewSummary;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Marks a Woo document in or out of scope, reports the case by relevance beside the verdicts,
 * and assigns batches of documents to reviewers.
 *
 * Every route checks the case through CaseAccessGuard in its body: reading
 * the summary needs read access to the case, marking needs mutation access.
 *
 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md
 */
class WooReviewController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param WooDocumentReviews $reviews The relevance store.
	 * @param WooReviewSummary $summary The case summary.
	 * @param WooReviewBatches $batches The review batches.
	 * @param WooPagesSeen $pagesSeen The pages seen per document.
	 * @param CaseAccessGuard $caseAccessGuard The per-case access check.
	 * @param IUserSession $userSession The session.
	 * @param IL10N $l10n The translations.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly WooDocumentReviews $reviews,
		private readonly WooReviewSummary $summary,
		private readonly WooReviewBatches $batches,
		private readonly WooPagesSeen $pagesSeen,
		private readonly CaseAccessGuard $caseAccessGuard,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The case summary: documents by relevance, verdicts, and what is outstanding.
	 *
	 * @param string $id The Woo case UUID.
	 *
	 * @return JSONResponse The summary, or the refusal.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-relevance-is-marked-apart-from-the-verdict-and-reported-req-wrt-001
	 */
	#[NoAdminRequired]
	public function summary(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'not-authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->caseAccessGuard->hasCaseReadAccess(caseId: $id, user: $user) === false) {
			return $this->noAccess();
		}

		return new JSONResponse($this->summary->forCase(caseId: $id));
	}//end summary()

	/**
	 * A reviewer marks a document in scope, out of scope, or back to unmarked.
	 *
	 * @param string $id The Woo case UUID.
	 * @param string $documentRef The document.
	 *
	 * @return JSONResponse The saved review, or the refusal.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-relevance-is-marked-apart-from-the-verdict-and-reported-req-wrt-001
	 */
	#[NoAdminRequired]
	public function relevance(string $id, string $documentRef): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'not-authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->caseAccessGuard->hasCaseMutationAccess(caseId: $id, user: $user) === false) {
			return $this->noAccess();
		}

		try {
			$review = $this->reviews->mark(
				caseId: $id,
				documentRef: $documentRef,
				relevance: (string)$this->request->getParam('relevance', ''),
				userId: $user->getUID(),
			);
		} catch (RefusedException $e) {
			return new JSONResponse(['error' => $e->getRule(), 'message' => $this->sentence(rule: $e->getRule())], $e->getStatus());
		}

		return new JSONResponse($review);
	}//end relevance()

	/**
	 * The case's review batches, each with its reviewer and its progress.
	 *
	 * @param string $id The Woo case UUID.
	 *
	 * @return JSONResponse `{results: [...]}`, or the refusal.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-batches-are-assigned-to-named-reviewers-before-any-verdict-req-wrt-003
	 */
	#[NoAdminRequired]
	public function batches(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'not-authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->caseAccessGuard->hasCaseReadAccess(caseId: $id, user: $user) === false) {
			return $this->noAccess();
		}

		return new JSONResponse(['results' => $this->batches->forCase(caseId: $id, assessed: $this->summary->assessed(caseId: $id))]);
	}//end batches()

	/**
	 * Create a review batch from a list of documents or a filter, and give its reviewer a task.
	 *
	 * @param string $id The Woo case UUID.
	 *
	 * @return JSONResponse The batch (201), or the refusal; a taken document answers 409 with `taken`.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-batches-are-assigned-to-named-reviewers-before-any-verdict-req-wrt-003
	 */
	#[NoAdminRequired]
	public function createBatch(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'not-authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->caseAccessGuard->hasCaseMutationAccess(caseId: $id, user: $user) === false) {
			return $this->noAccess();
		}

		$filter = array_map('strval', array_filter((array)$this->request->getParam('filter', []), 'is_scalar'));
		$listed = array_values(array_map('strval', array_filter((array)$this->request->getParam('documents', []), 'is_scalar')));
		try {
			$documents = $this->batches->select(caseId: $id, documents: $listed, filter: $filter);
			$taken = $this->batches->taken(caseId: $id, documents: $documents);
			if ($taken !== []) {
				return new JSONResponse(
					['error' => 'woo-batch-document-taken', 'message' => $this->sentence(rule: 'woo-batch-document-taken'), 'taken' => $taken],
					Http::STATUS_CONFLICT
				);
			}

			$made = $this->batches->create(
				caseId: $id,
				name: (string)$this->request->getParam('name', ''),
				assignee: (string)$this->request->getParam('assignee', ''),
				documents: $documents,
				userId: $user->getUID(),
				filter: $filter,
			);
		} catch (RefusedException $e) {
			return new JSONResponse(['error' => $e->getRule(), 'message' => $this->sentence(rule: $e->getRule())], $e->getStatus());
		}

		return new JSONResponse($made, Http::STATUS_CREATED);
	}//end createBatch()

	/**
	 * The review viewer reports the pages a reviewer displayed, and the page count when it knows it.
	 *
	 * Read access is enough: a reviewer reads, and seeing a page is reading it.
	 *
	 * @param string $id The Woo case UUID.
	 * @param string $documentRef The document.
	 *
	 * @return JSONResponse The saved review with its unseen required pages, or the refusal.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-nothing-is-decided-or-published-before-the-required-pages-are-seen-req-wrt-005
	 */
	#[NoAdminRequired]
	public function pagesSeen(string $id, string $documentRef): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'not-authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->caseAccessGuard->hasCaseReadAccess(caseId: $id, user: $user) === false) {
			return $this->noAccess();
		}

		$pages = array_values(array_map('intval', array_filter((array)$this->request->getParam('pages', []), 'is_numeric')));
		$reported = $this->request->getParam('pageCount');
		$pageCount = null;
		if (is_numeric($reported) === true) {
			$pageCount = (int)$reported;
		}

		try {
			$review = $this->pagesSeen->record(caseId: $id, documentRef: $documentRef, pages: $pages, pageCount: $pageCount, userId: $user->getUID());
		} catch (RefusedException $e) {
			return new JSONResponse(['error' => $e->getRule(), 'message' => $this->sentence(rule: $e->getRule())], $e->getStatus());
		}

		return new JSONResponse($review + ['unseen' => $this->pagesSeen->unseenPages(review: $review)]);
	}//end pagesSeen()

	/**
	 * The refusal of a user without access to the case.
	 *
	 * @return JSONResponse The refusal.
	 */
	private function noAccess(): JSONResponse {
		return new JSONResponse(
			['error' => 'no-case-access', 'message' => $this->l10n->t('You do not have access to this case')],
			Http::STATUS_FORBIDDEN
		);
	}//end noAccess()

	/**
	 * The translated sentence of a refusal rule.
	 *
	 * @param string $rule The rule.
	 *
	 * @return string The sentence.
	 */
	private function sentence(string $rule): string {
		return match ($rule) {
			'woo-relevance-unknown' => $this->l10n->t('Mark the document in scope, out of scope or unmarked.'),
			'woo-batch-filter-unknown' => $this->l10n->t('A batch can be taken by the rule that marked its documents, or by a list of documents.'),
			'woo-batch-name-required' => $this->l10n->t('Give the batch a name.'),
			'woo-batch-assignee-unknown' => $this->l10n->t('Choose the reviewer of the batch.'),
			'woo-batch-empty' => $this->l10n->t('Put at least one document in the batch.'),
			'woo-batch-document-unknown' => $this->l10n->t('A batch only holds documents of this case.'),
			'woo-batch-document-taken' => $this->l10n->t('A document is already in another open batch.'),
			'woo-pages-required' => $this->l10n->t('Name the pages that were displayed.'),
			'woo-batch-unavailable' => $this->l10n->t('The Woo review batch cannot be stored, so nothing was assigned.'),
			default => $this->l10n->t('The Woo review cannot be stored, so nothing was marked.'),
		};
	}//end sentence()
}//end class
