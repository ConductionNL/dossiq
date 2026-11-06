<?php

/**
 * Dossiq Document Copy Controller.
 *
 *  - POST /api/informatieobjecten/bulk/copy
 *
 * Copies documents to another case. The caller's right to add documents to
 * the target case is checked before anything is read; each document is then
 * copied only when the caller may read it.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/documents-copy-to-case/spec.md
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\Zaakdossier\DocumentCopier;
use OCA\Dossiq\Service\Zaakdossier\DossierUploadHandler;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Copies documents to another case.
 *
 * @spec openspec/specs/documents-copy-to-case/spec.md
 */
class DocumentCopyController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string               $appName       The app name.
	 * @param IRequest             $request       The request.
	 * @param IUserSession         $userSession   The user session.
	 * @param DossierUploadHandler $uploadHandler The right to add documents to a case.
	 * @param DocumentCopier       $copier        Copies documents to another case.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly DossierUploadHandler $uploadHandler,
		private readonly DocumentCopier $copier,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Copy documents to another case (`ids`, `targetCaseId`).
	 *
	 * Refused before anything is read when the caller may not add documents
	 * to the target case; each document is then copied only when the caller
	 * may read it.
	 *
	 * @return JSONResponse Per-id results, or the refusal.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/documents-copy-to-case/spec.md#requirement-documents-are-copied-to-a-case-the-caller-may-add-to
	 */
	public function bulkCopy(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$ids = (array)$this->request->getParam('ids', []);
		$targetCaseId = (string)$this->request->getParam('targetCaseId', '');
		if ($targetCaseId === '' || $ids === []) {
			return new JSONResponse(['error' => 'Choose the documents and the case to copy them to'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->uploadHandler->hasCaseUploadAccess(user: $user, caseId: $targetCaseId) === false) {
			return new JSONResponse(['error' => 'You may not add documents to that case'], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(['results' => $this->copier->copyAll(user: $user, ids: $ids, targetCaseId: $targetCaseId)]);
	}//end bulkCopy()
}//end class
