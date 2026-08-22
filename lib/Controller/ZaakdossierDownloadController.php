<?php

/**
 * Dossiq Zaakdossier Download Controller
 *
 * Binary download surface for the ZGW DRC case dossier: a ZIP export with
 * manifest, a single-file download, and a ZGW DRC-compatible streaming
 * endpoint with HTTP Range support.
 *
 * Split out of ZaakdossierController along the response-type seam — every
 * endpoint here returns file bytes rather than JSON, and they are the only ones
 * that touch the archive builder and the range-streaming response. The JSON
 * dossier surface stays on ZaakdossierController.
 *
 * Every endpoint enforces {@see InformatieobjectReader} so confidentiality
 * (`vertrouwelijkheidaanduiding`) is gated server-side and per-object before
 * any content is read — never relying on the UI alone (OWASP A01:2021,
 * ADR-005 Rule 3).
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
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Http\RangeStreamResponse;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Zaakdossier\DossierZipExporter;
use OCA\Dossiq\Service\Zaakdossier\InformatieobjectReader;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for zaakdossier binary downloads.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
 */
class ZaakdossierDownloadController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param InformatieobjectReader $reader The clearance-gated document reader.
	 * @param DossierZipExporter $zipExporter The ZIP export collaborator.
	 * @param IUserSession $userSession The user session.
	 * @param LoggerInterface $logger The logger.
	 * @param CaseAccessGuard $caseAccessGuard Per-case authorization (fails closed).
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly InformatieobjectReader $reader,
		private readonly DossierZipExporter $zipExporter,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly CaseAccessGuard $caseAccessGuard,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Export a case dossier as a ZIP with manifest, clearance-filtered.
	 *
	 * Per-object guard: `CaseAccessGuard::hasCaseReadAccess()`.
	 *
	 * ⚠️ THE CLEARANCE FILTER DEEPER DOWN IS NOT THIS GUARD. `buildZipData()`
	 * routes through `ZipManifestBuilder::filterByClearance()` →
	 * `InformatieobjectAccessGuard::filterDossierForUser()`, which compares the
	 * caller's clearance ORDINAL against each document's
	 * `vertrouwelijkheidaanduiding`. That is a classification filter: it decides
	 * how sensitive a document the caller may see, and says nothing about
	 * whether the caller has anything to do with THIS case. So before this
	 * guard, any authenticated account whose clearance cleared the documents
	 * could download any case's dossier by supplying its id.
	 *
	 * The sibling capability was already guarded this way:
	 * `DossierExportController::plan()` calls `hasCaseReadAccess()` before
	 * returning the export PLAN. This endpoint returns the BYTES the plan
	 * describes, so the two were inconsistent rather than deliberately
	 * different.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return DataDownloadResponse|JSONResponse The ZIP download or an error status.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
	 */
	public function downloadZip(string $caseId): DataDownloadResponse|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->caseAccessGuard->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['error' => 'Not authorized'], Http::STATUS_FORBIDDEN);
		}

		try {
			$documents = $this->zipExporter->collectDocuments(
				caseId: $caseId,
				selectedIds: (array)$this->request->getParam('ids', []),
			);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		try {
			$data = $this->zipExporter->buildZipData(
				user: $user,
				documents: $documents,
				flatLayout: ($this->request->getParam('subfolderPerType', '1') === '0'),
			);
		} catch (\Throwable $e) {
			$this->logger->error('Dossiq dossier ZIP build failed: ' . $e->getMessage());
			return new JSONResponse(['error' => 'ZIP export failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new DataDownloadResponse($data, 'dossier-' . $caseId . '.zip', 'application/zip');
	}//end downloadZip()

	/**
	 * Download a single dossier file, gated by clearance.
	 *
	 * @param string $register The register slug (kept for ZGW DRC path parity).
	 * @param string $schema The schema slug (kept for ZGW DRC path parity).
	 * @param string $objectId The informatieobject UUID.
	 * @param int $fileId The Nextcloud file id (kept for ZGW DRC path parity).
	 *
	 * @return DataDownloadResponse|JSONResponse The file download or an error status.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $register, $schema and $fileId are
	 * URL segments of the route
	 * `/api/objects/{register}/{schema}/{objectId}/files/{fileId}/download` and are bound
	 * positionally by the dispatcher; they cannot be dropped without changing the route.
	 */
	public function downloadFile(string $register, string $schema, string $objectId, int $fileId): DataDownloadResponse|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		// Per-object clearance gate before any content is read (OWASP A01:2021).
		$doc = $this->reader->loadReadable(user: $user, infoObjectId: $objectId);
		if ($doc instanceof JSONResponse) {
			return $doc;
		}

		$fileName = (string)($doc['fileName'] ?? 'document');
		$content = $this->reader->contentFor(uuid: $objectId, fileName: $fileName);
		if ($content === null) {
			return new JSONResponse(['error' => 'File not found'], Http::STATUS_NOT_FOUND);
		}

		$mime = (string)($doc['format'] ?? 'application/octet-stream');
		return new DataDownloadResponse($content, $fileName, $mime);
	}//end downloadFile()

	/**
	 * ZGW DRC-compatible download with HTTP Range support.
	 *
	 * @param string $uuid The informatieobject (enkelvoudiginformatieobject) UUID.
	 *
	 * @return RangeStreamResponse|JSONResponse Full (200) or partial (206) content, or an error status.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
	 */
	public function downloadZgwDocumenten(string $uuid): RangeStreamResponse|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$doc = $this->reader->loadReadable(user: $user, infoObjectId: $uuid);
		if ($doc instanceof JSONResponse) {
			return $doc;
		}

		$fileName = (string)($doc['fileName'] ?? 'document');
		$content = $this->reader->contentFor(uuid: $uuid, fileName: $fileName);
		if ($content === null) {
			return new JSONResponse(['error' => 'File not found'], Http::STATUS_NOT_FOUND);
		}

		return new RangeStreamResponse(
			content: $content,
			fileName: $fileName,
			contentType: (string)($doc['format'] ?? 'application/octet-stream'),
			rangeHeader: (string)$this->request->getHeader('Range'),
		);
	}//end downloadZgwDocumenten()
}//end class
