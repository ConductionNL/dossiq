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
use OCA\Dossiq\Woo\WooReviewSummary;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Marks a Woo document in or out of scope, and reports the case by relevance beside the verdicts.
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
		if ($rule === 'woo-relevance-unknown') {
			return $this->l10n->t('Mark the document in scope, out of scope or unmarked.');
		}

		return $this->l10n->t('The Woo review cannot be stored, so nothing was marked.');
	}//end sentence()
}//end class
