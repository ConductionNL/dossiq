<?php

/**
 * Dossiq Case Document Generation Controller
 *
 * One endpoint: render a library template over a case and file the result in
 * its dossier. It sits beside {@see ZaakdossierController} rather than on it
 * because that controller already couples to thirteen collaborators, and one
 * more crosses the phpmd threshold — a controller that keeps growing is the
 * thing the threshold is there to notice.
 *
 * The write is the same write an upload makes, so it carries the same case
 * guard: without it this endpoint would file a letter, under the caller's
 * name, into any case on the instance (OWASP A01:2021, ADR-005 Rule 3).
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 * @spec openspec/specs/template-library/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\CaseDocumentGenerationService;
use OCA\Dossiq\Service\Zaakdossier\DossierUploadHandler;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for the Generate document action on a case.
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */
class CaseDocumentGenerationController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param CaseDocumentGenerationService $generationService Renders the
	 *                                                        template and files
	 *                                                        the result.
	 * @param DossierUploadHandler $uploadHandler Supplies the case write guard
	 *                                            the upload endpoint uses.
	 * @param IUserSession $userSession The user session.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CaseDocumentGenerationService $generationService,
		private readonly DossierUploadHandler $uploadHandler,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Generate a document on a case from a library template.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse The created informatieobject, or a named failure.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 * @spec openspec/specs/template-library/spec.md
	 */
	public function generateDocument(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->uploadHandler->hasCaseUploadAccess(user: $user, caseId: $caseId) === false) {
			return new JSONResponse(['error' => 'Not authorized'], Http::STATUS_FORBIDDEN);
		}

		$templateId = (string)$this->request->getParam('templateId', '');
		if (trim($templateId) === '') {
			return new JSONResponse(['error' => 'templateId is required'], Http::STATUS_BAD_REQUEST);
		}

		$result = $this->generationService->generate(caseId: $caseId, templateId: $templateId);
		if ($result->succeeded === false) {
			return new JSONResponse(['error' => $result->error], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(
			[
				'informatieobject' => ($result->data['informatieobject'] ?? ''),
				'case' => $caseId,
			],
			Http::STATUS_CREATED
		);
	}//end generateDocument()
}//end class
