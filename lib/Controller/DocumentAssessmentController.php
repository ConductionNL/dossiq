<?php

/**
 * Dossiq document assessment controller
 *
 * GET /api/cases/{id}/document-assessments/outstanding answers which
 * documents of a case still wait for an assessment, with their titles, for
 * the assess dialog on the case page. It needs read access to the case.
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
 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-answers-which-documents-still-wait-for-an-assessment-req-das-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\Assessment\OutstandingDocuments;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * Answers the outstanding documents of a case to a user who may read it.
 *
 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-answers-which-documents-still-wait-for-an-assessment-req-das-001
 */
class DocumentAssessmentController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string               $appName     The app name.
	 * @param IRequest             $request     The request.
	 * @param OutstandingDocuments $outstanding Names the documents that wait for an assessment.
	 * @param CaseAccessGuard      $guard       Case read access.
	 * @param IUserSession         $userSession The caller.
	 * @param IL10N                $l10n        Translates the refusal the dialog shows.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly OutstandingDocuments $outstanding,
		private readonly CaseAccessGuard $guard,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The documents of one case that still wait for an assessment.
	 *
	 * @param string $id The case uuid.
	 *
	 * @return JSONResponse `{count, documents: [{id, title}]}`, or 401, 403 or 503 with `error` and `message`.
	 *
	 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-answers-which-documents-still-wait-for-an-assessment-req-das-001
	 */
	#[NoAdminRequired]
	public function outstanding(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->guard->hasCaseReadAccess(caseId: $id, user: $user) === false) {
			return new JSONResponse(
				['error' => 'forbidden', 'message' => $this->l10n->t('You do not have access to this case.')],
				Http::STATUS_FORBIDDEN
			);
		}

		try {
			return new JSONResponse($this->outstanding->forCase(caseId: $id));
		} catch (Throwable $e) {
			return new JSONResponse(
				['error' => 'unavailable', 'message' => $this->l10n->t('OpenRegister is not available.')],
				Http::STATUS_SERVICE_UNAVAILABLE
			);
		}
	}//end outstanding()
}//end class
