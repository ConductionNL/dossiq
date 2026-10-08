<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\Zaakdossier\InformatieobjectReader;
use OCA\Dossiq\Service\Zaakdossier\LinkedDocumentsReader;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use RuntimeException;

/**
 * The documents joined to a case whose file lives in another case's folder.
 *
 * The Files tab shows the case folder itself; these are the rows it adds
 * after the folder's own files, read-only, naming the case that holds the
 * file (REQ-DPR-004). Filtered by the reader's clearance rule like the
 * dossier list. Its own controller so ZaakdossierController stays under its
 * coupling ceiling.
 *
 * @spec openspec/specs/document-projection/spec.md
 */
class LinkedDocumentsController extends Controller {

	/**
	 * @param string $appName The app id.
	 * @param IRequest $request The request.
	 * @param LinkedDocumentsReader $linkedDocuments The rows.
	 * @param InformatieobjectReader $reader The clearance gate per document.
	 * @param IUserSession $userSession Who asks.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly LinkedDocumentsReader $linkedDocuments,
		private readonly InformatieobjectReader $reader,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The linked rows of a case the caller may read.
	 *
	 * @param string $caseId The case (zaak) UUID.
	 *
	 * @return JSONResponse The rows, an array.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/document-projection/spec.md
	 */
	public function index(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$items = $this->linkedDocuments->linkedDocuments(caseId: $caseId);
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$readable = [];
		foreach ($items as $item) {
			if ($this->reader->guardReadable(user: $user, infoObjectId: (string)$item['id']) === null) {
				$readable[] = $item;
			}
		}

		return new JSONResponse($readable);
	}//end index()
}//end class
