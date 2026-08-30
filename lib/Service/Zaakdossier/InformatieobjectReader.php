<?php

/**
 * Dossiq InformatieobjectReader.
 *
 * Clearance-gated read collaborator for ZGW DRC informatieobjecten. Split out
 * of ZaakdossierController when that controller was divided along the download
 * seam: both the JSON dossier surface and the binary download surface must
 * resolve a document and assert the caller's confidentiality clearance before
 * anything is returned, so that sequence lives here once (ADR-022).
 *
 * Every path fails closed — an unresolvable document is a 404 and an
 * insufficient clearance is a 403, never a silent pass-through (OWASP
 * A01:2021, ADR-005 Rule 3).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zaakdossier
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zaakdossier;

use OCA\Dossiq\Service\InformatieobjectAccessGuard;
use OCA\Dossiq\Service\ZaakdossierService;
use OCA\Dossiq\Service\ZgwDocumentService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\NotPermittedException;
use OCP\IUser;

/**
 * Resolves informatieobjecten behind the per-object clearance guard.
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
 */
class InformatieobjectReader {
	/**
	 * Constructor.
	 *
	 * @param ZaakdossierService $fileService The dossier orchestrator.
	 * @param InformatieobjectAccessGuard $accessGuard The confidentiality guard.
	 * @param ZgwDocumentService $documentService The binary storage service.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ZaakdossierService $fileService,
		private readonly InformatieobjectAccessGuard $accessGuard,
		private readonly ZgwDocumentService $documentService,
	) {
	}//end __construct()

	/**
	 * Load an informatieobject, returning a 404/403 JSONResponse on failure.
	 *
	 * @param IUser $user The requesting user.
	 * @param string $infoObjectId The informatieobject UUID.
	 *
	 * @return array<string, mixed>|JSONResponse The document, or an error response.
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
	 */
	public function loadReadable(IUser $user, string $infoObjectId): array|JSONResponse {
		try {
			$doc = $this->fileService->getInformatieobject(infoObjectId: $infoObjectId);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		if ($doc === null) {
			return new JSONResponse(['error' => 'Informatieobject not found'], Http::STATUS_NOT_FOUND);
		}

		try {
			$this->accessGuard->assertCanRead(user: $user, informatieobject: $doc);
		} catch (NotPermittedException $e) {
			return new JSONResponse(['error' => 'Insufficient clearance for this document'], Http::STATUS_FORBIDDEN);
		}

		return $doc;
	}//end loadReadable()

	/**
	 * Filter a dossier's informatieobjecten down to what the user may see.
	 *
	 * @param IUser $user The requesting user.
	 * @param array<int,mixed> $informatieobjecten The unfiltered documents.
	 *
	 * @return array<int, array<string, mixed>> The documents the user may read.
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
	 */
	public function filterForUser(IUser $user, array $informatieobjecten): array {
		return $this->accessGuard->filterDossierForUser(
			user: $user,
			informatieobjecten: $informatieobjecten,
		);
	}//end filterForUser()

	/**
	 * Per-object clearance guard returning a 403/404 JSONResponse on denial.
	 *
	 * @param IUser $user The requesting user.
	 * @param string $infoObjectId The informatieobject UUID.
	 *
	 * @return JSONResponse|null Null when readable, otherwise the error response.
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
	 */
	public function guardReadable(IUser $user, string $infoObjectId): ?JSONResponse {
		$result = $this->loadReadable(user: $user, infoObjectId: $infoObjectId);
		if ($result instanceof JSONResponse) {
			return $result;
		}

		return null;
	}//end guardReadable()

	/**
	 * Fetch a document's binary content.
	 *
	 * @param string $uuid The informatieobject UUID.
	 * @param string $fileName The stored file name.
	 *
	 * @return string|null The content, or null when the file is missing.
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
	 */
	public function contentFor(string $uuid, string $fileName): ?string {
		try {
			return $this->documentService->getContent(uuid: $uuid, fileName: $fileName);
		} catch (\Throwable $e) {
			return null;
		}
	}//end contentFor()
}//end class
