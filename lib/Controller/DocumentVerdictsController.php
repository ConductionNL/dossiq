<?php

/**
 * Dossiq document verdicts controller: the verdicts on a case's documents and the grounds cited.
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
 * @spec openspec/specs/document-assessment/spec.md#requirement-a-case-answers-its-document-verdicts-and-the-grounds-cited-req-cdv-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Review\DocumentVerdicts;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Answers a reader of a case the verdicts on its documents and the grounds they cite.
 *
 * The case read check runs before anything is read.
 *
 * @spec openspec/specs/document-assessment/spec.md#requirement-a-case-answers-its-document-verdicts-and-the-grounds-cited-req-cdv-001
 */
class DocumentVerdictsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest         $request         The request.
	 * @param DocumentVerdicts $verdicts        The verdicts and the grounds of a case.
	 * @param CaseAccessGuard  $caseAccessGuard The per-case access check.
	 * @param IUserSession     $userSession     The session.
	 * @param IL10N            $l10n            The translations.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly DocumentVerdicts $verdicts,
		private readonly CaseAccessGuard $caseAccessGuard,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The verdicts on the case's documents so far, and every ground they cite.
	 *
	 * @param string $id The case UUID.
	 *
	 * @return JSONResponse The verdicts, or the refusal.
	 *
	 * @spec openspec/specs/document-assessment/spec.md#requirement-a-case-answers-its-document-verdicts-and-the-grounds-cited-req-cdv-001
	 */
	#[NoAdminRequired]
	public function forCase(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'not-authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->caseAccessGuard->hasCaseReadAccess(caseId: $id, user: $user) === false) {
			return new JSONResponse(
				['error' => 'no-case-access', 'message' => $this->l10n->t('You do not have access to this case')],
				Http::STATUS_FORBIDDEN
			);
		}

		return new JSONResponse($this->verdicts->forCase(caseId: $id));
	}//end forCase()
}//end class
