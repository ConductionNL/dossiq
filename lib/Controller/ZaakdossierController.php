<?php

/**
 * Dossiq Zaakdossier Controller
 *
 * Authenticated JSON REST API for the ZGW DRC case dossier: list/upload
 * documents, link/unlink existing informatieobjecten, update metadata and
 * transition status (single + bulk).
 *
 * The binary surface — ZIP export, single-file download and the ZGW DRC
 * streaming endpoint — lives on {@see ZaakdossierDownloadController}. Upload
 * decoding and screening is delegated to {@see DossierUploadHandler} (ADR-022).
 *
 * Every read endpoint enforces {@see InformatieobjectReader}, which wraps
 * InformatieobjectAccessGuard, so confidentiality
 * (`vertrouwelijkheidaanduiding`) is gated server-side and per-object — never
 * relying on the UI alone (OWASP A01:2021, ADR-005 Rule 3).
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
 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T05
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Kernel\Contracts\DocumentApprovalClearanceInterface;
use OCA\Dossiq\Kernel\Contracts\DocumentWriteGateInterface;
use OCA\Dossiq\Kernel\Contracts\DossierUploadHandlerInterface;
use OCA\Dossiq\Kernel\Contracts\InformatieobjectReaderInterface;
use OCA\Dossiq\Kernel\Contracts\ZaakdossierServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for the ZGW DRC zaakdossier.
 *
 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T05
 */
class ZaakdossierController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param ZaakdossierServiceInterface $fileService The dossier orchestrator.
	 * @param InformatieobjectReaderInterface $reader The clearance-gated document reader.
	 * @param DossierUploadHandlerInterface $uploadHandler The upload decoding/screening collaborator.
	 * @param IUserSession $userSession The user session.
	 * @param DocumentApprovalClearanceInterface $approvals Reads decidiq's approval chain for a document,
	 *        so a document in an unfinished route cannot be made final.
	 * @param DocumentWriteGateInterface $writes What a document write passes before it runs: the change right
	 *        on every case the document belongs to, the clearance over a bulk selection, and the bulk
	 *        metadata run behind them.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ZaakdossierServiceInterface $fileService,
		private readonly InformatieobjectReaderInterface $reader,
		private readonly DossierUploadHandlerInterface $uploadHandler,
		private readonly IUserSession $userSession,
		private readonly DocumentApprovalClearanceInterface $approvals,
		private readonly DocumentWriteGateInterface $writes,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * List the dossier for a case, grouped by type and filtered by clearance.
	 *
	 * @param string $caseId The case (zaak) UUID.
	 *
	 * @return JSONResponse Grouped dossier or an error status.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T05
	 */
	public function listDossier(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$file = $this->fileService->getDossierForCase(
				caseId: $caseId,
				// "Show me everything that went to this person." The value is
				// a party of the case, never a name: two people called Jansen
				// are two filters.
				correspondent: (string)$this->request->getParam('correspondent', ''),
			);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse($this->visibleDossier(user: $user, caseId: $caseId, file: $file));
	}//end listDossier()

	/**
	 * The dossier as this user may see it, with its verdicts and rights, grouped by type.
	 *
	 * @param \OCP\IUser           $user   The signed-in user.
	 * @param string               $caseId The case (zaak) UUID.
	 * @param array<string, mixed> $file   The case's dossier.
	 *
	 * @return array<string, mixed> The grouped dossier.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T05
	 */
	private function visibleDossier($user, string $caseId, array $file): array {
		$filtered = $this->reader->filterForUser(
			user: $user,
			informatieobjecten: ($file['informatieobjecten'] ?? []),
		);

		return $this->fileService->groupByType(
			documents: $this->writes->withChangeRight(
				user: $user,
				documents: $this->reader->withAccessExceptionNames(
					documents: $this->reader->withReviewVerdicts(caseId: $caseId, documents: $this->reader->withScanVerdicts(documents: $filtered))
				)
			)
		);
	}//end visibleDossier()

	/**
	 * Upload one or more documents to a case dossier.
	 *
	 * Accepts multipart files plus a shared `metadata` JSON body. Returns a
	 * per-file result list so a single failure does not block the rest.
	 *
	 * @param string $caseId The case (zaak) UUID.
	 *
	 * @return JSONResponse Per-file upload results.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function uploadDocument(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		// Its sibling `linkExisting()` below already guards, via
		// `InformatieobjectReader::guardReadable()` — but that guard is about
		// the DOCUMENT, and upload has no existing document to check. The
		// missing half is the CASE, so this endpoint wrote attachments into
		// any case on the instance.
		if ($this->uploadHandler->hasCaseUploadAccess(user: $user, caseId: $caseId) === false) {
			return new JSONResponse(['error' => 'Not authorized'], Http::STATUS_FORBIDDEN);
		}

		$metadata = $this->uploadMetadataFor(fallbackAuthor: static fn (): string => $user->getDisplayName());

		$files = $this->uploadHandler->normaliseUploadedFiles(uploaded: $this->request->getUploadedFile('files'));
		if (empty($files) === true) {
			return new JSONResponse(['error' => 'No files uploaded'], Http::STATUS_BAD_REQUEST);
		}

		return $this->uploadEach(caseId: $caseId, files: $files, metadata: $metadata);
	}//end uploadDocument()

	/**
	 * The metadata sent with an upload, with the user as auteur when it names none.
	 *
	 * @param callable(): string $fallbackAuthor Reads the current user's display name.
	 *
	 * @return array The metadata.
	 */
	private function uploadMetadataFor(callable $fallbackAuthor): array {
		$metadata = $this->uploadHandler->decodeMetadata(raw: $this->request->getParam('metadata', '{}'));
		if (($metadata['auteur'] ?? '') === '') {
			$metadata['auteur'] = $fallbackAuthor();
		}

		return $metadata;
	}//end uploadMetadataFor()

	/**
	 * Upload each file into the case and answer the per-file results.
	 *
	 * @param string $caseId The case.
	 * @param array $files The normalised uploaded files.
	 * @param array $metadata The metadata for every file.
	 *
	 * @return JSONResponse 201 when at least one file was created, else 422.
	 */
	private function uploadEach(string $caseId, array $files, array $metadata): JSONResponse {
		$results = [];
		foreach ($files as $file) {
			$results[] = $this->uploadHandler->uploadOne(
				caseId: $caseId,
				file: $file,
				metadata: $metadata,
			);
		}

		// 201 CREATED ONLY IF SOMETHING WAS CREATED. A per-file result list is
		// the right shape when one file of five fails, but answering 201 when
		// EVERY file failed makes the failure invisible: the browser reads the
		// status, sees success, and shows nothing. That is how a total upload
		// failure sat on `development` looking like a working feature, with
		// the reason sitting unread inside the body of a 201.
		//
		// Partial success stays 201, because something was in fact created and
		// the per-file list says which.
		$created = array_filter($results, static fn (array $r): bool => ($r['success'] ?? false) === true);
		if (count($created) === 0) {
			return new JSONResponse(['results' => $results], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new JSONResponse(['results' => $results], Http::STATUS_CREATED);
	}//end uploadEach()

	/**
	 * Link an existing informatieobject to a case.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $infoObjectId The informatieobject UUID.
	 *
	 * @return JSONResponse The join result.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T05
	 */
	public function linkExisting(string $caseId, string $infoObjectId): JSONResponse {
		$refusal = $this->linkRefusal(caseId: $caseId, infoObjectId: $infoObjectId);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$result = $this->fileService->linkExistingInformatieobject(caseId: $caseId, infoObjectId: $infoObjectId);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse($result, Http::STATUS_CREATED);
	}//end linkExisting()

	/**
	 * Unlink an informatieobject from a case (preserves the document).
	 *
	 * @param string $caseId The case UUID.
	 * @param string $infoObjectId The informatieobject UUID.
	 *
	 * @return JSONResponse The unlink result.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T05
	 */
	public function unlinkDocument(string $caseId, string $infoObjectId): JSONResponse {
		$refusal = $this->linkRefusal(caseId: $caseId, infoObjectId: $infoObjectId);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$removed = $this->fileService->unlinkInformatieobject(caseId: $caseId, infoObjectId: $infoObjectId);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse(['unlinked' => $removed]);
	}//end unlinkDocument()

	/**
	 * The refusal for linking a document into, or unlinking it from, a case file; null when the caller may.
	 *
	 * No session user is 401. The CASE is checked first, before the document
	 * is read: the document guard says nothing about whether this caller may
	 * change the case it links into. Then a document the caller may not read
	 * answers the reader's refusal.
	 *
	 * @param string $caseId       The case.
	 * @param string $infoObjectId The document.
	 *
	 * @return JSONResponse|null The refusal, or null when the change may go ahead.
	 */
	private function linkRefusal(string $caseId, string $infoObjectId): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->uploadHandler->hasCaseUploadAccess(user: $user, caseId: $caseId) === false) {
			return new JSONResponse(['error' => 'Not authorized'], Http::STATUS_FORBIDDEN);
		}

		return $this->reader->guardReadable(user: $user, infoObjectId: $infoObjectId);
	}//end linkRefusal()

	/**
	 * Update editable metadata on an informatieobject.
	 *
	 * @param string $infoObjectId The informatieobject UUID.
	 *
	 * @return JSONResponse The updated metadata or an error status.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T05
	 */
	public function updateMetadata(string $infoObjectId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$refusal = $this->writes->refusedWrite(user: $user, documentId: $infoObjectId);
		if ($refusal !== null) {
			return $refusal;
		}

		$authError = $this->reader->guardReadable(user: $user, infoObjectId: $infoObjectId);
		if ($authError !== null) {
			return $authError;
		}

		return $this->saveMetadata(infoObjectId: $infoObjectId, metadata: $this->metadataFromRequest());
	}//end updateMetadata()

	/**
	 * The metadata fields a request sets, leaving out the ones it does not send.
	 *
	 * @return array The metadata.
	 */
	private function metadataFromRequest(): array {
		$metadata = [
			'title' => $this->request->getParam('title'),
			// Key = schema property (renamed). The request param keeps both
			// spellings: it is a published contract and an un-updated client
			// must not break.
			'description' => $this->request->getParam('description') ?? $this->request->getParam('beschrijving'),
			'informatieobjecttype' => $this->request->getParam('informatieobjecttype'),
			'vertrouwelijkheidaanduiding' => $this->request->getParam('vertrouwelijkheidaanduiding'),
			// The Files tab's Document properties dialog edits these two as well.
			'direction' => $this->request->getParam('direction'),
			'keywords' => $this->request->getParam('keywords'),
			// Who the document came from and who it went to. Both name a
			// party OF THIS CASE; a value naming anything else is dropped by
			// DocumentCorrespondents rather than stored as a typed name.
			'sender' => $this->request->getParam('sender'),
			'recipients' => $this->request->getParam('recipients'),
		];
		$metadata = array_filter($metadata, static fn ($value) => $value !== null);

		return $metadata;
	}//end metadataFromRequest()

	/**
	 * Save a document's metadata, answering a conflict as 409 and an unavailable store as 503.
	 *
	 * @param string $infoObjectId The document.
	 * @param array $metadata The metadata.
	 *
	 * @return JSONResponse
	 */
	private function saveMetadata(string $infoObjectId, array $metadata): JSONResponse {
		try {
			$result = $this->fileService->updateMetadata(infoObjectId: $infoObjectId, metadata: $metadata);
		} catch (\DomainException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse($result);
	}//end saveMetadata()

	/**
	 * Which of these documents are in an approval route, and at which step.
	 *
	 * 🔑 IT IS A READ AND NEVER A COPY. The marker is decidiq's route state,
	 * asked for at load time. A stored copy on the document would be written
	 * once and would then disagree with the route the first time somebody
	 * approved from decidiq's own page, and the row would go on saying "step
	 * two of three" after the route had finished.
	 *
	 * 🔑 ONE CALL FOR THE WHOLE TAB. The Files tab has one row per document, so
	 * a per-row question is one round trip per row on a case with forty of
	 * them. The ids come in together and the answers go back together.
	 *
	 * 🔴 EVERY ID IS GUARDED. A route's step names a person, and being signed
	 * in is not permission to read who is holding up somebody else's file, so
	 * each id goes through the same readability check a single read does and an
	 * id the caller may not read is DROPPED from the answer rather than
	 * refused: refusing would turn one unreadable document into a Files tab
	 * with no markers at all.
	 *
	 * @return JSONResponse The markers, keyed by document id.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/besluitvorming-leaf/spec.md
	 */
	public function approvalMarkers(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$raw = (string)$this->request->getParam('ids', '');
		$ids = array_values(array_filter(array_map('trim', explode(',', $raw))));

		$markers = [];
		foreach ($ids as $id) {
			if ($this->reader->guardReadable(user: $user, infoObjectId: $id) !== null) {
				continue;
			}

			$clearance = $this->approvals->forDocument(documentId: $id);
			if (($clearance['routed'] ?? false) !== true) {
				continue;
			}

			$markers[$id] = [
				'routed' => true,
				'cleared' => ($clearance['cleared'] ?? false),
				'waitingOn' => ($clearance['waitingOn'] ?? []),
				'reason' => $this->approvals->describe(clearance: $clearance),
			];
		}

		return new JSONResponse(['markers' => $markers]);
	}//end approvalMarkers()

	/**
	 * Transition a single informatieobject's status.
	 *
	 * @param string $infoObjectId The informatieobject UUID.
	 *
	 * @return JSONResponse The transition result. HTTP 400 on an invalid transition.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T05
	 */
	public function transitionStatus(string $infoObjectId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$refusal = $this->writes->refusedWrite(user: $user, documentId: $infoObjectId);
		if ($refusal !== null) {
			return $refusal;
		}

		$authError = $this->reader->guardReadable(user: $user, infoObjectId: $infoObjectId);
		if ($authError !== null) {
			return $authError;
		}

		$newStatus = (string)$this->request->getParam('status', '');

		try {
			$result = $this->fileService->transitionStatus(infoObjectId: $infoObjectId, newStatus: $newStatus);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse($result);
	}//end transitionStatus()

	/**
	 * Apply a bulk status transition over multiple informatieobjecten.
	 *
	 * @return JSONResponse Per-id success/failure list.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T05
	 */
	public function bulkTransitionStatus(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$ids = (array)$this->request->getParam('ids', []);
		$newStatus = (string)$this->request->getParam('status', '');

		// Case write and per-object clearance gate before any mutation.
		$refusal = $this->writes->refusedBulkTransition(user: $user, ids: $ids);
		if ($refusal !== null) {
			return $refusal;
		}

		$results = $this->fileService->bulkTransitionStatus(infoObjectIds: $ids, newStatus: $newStatus);

		return new JSONResponse(['results' => $results]);
	}//end bulkTransitionStatus()

	/**
	 * Apply a bulk metadata update over multiple informatieobjecten.
	 *
	 * @return JSONResponse Per-id success/failure list.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T05
	 */
	public function bulkUpdateMetadata(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$ids = (array)$this->request->getParam('ids', []);
		$metadata = (array)$this->request->getParam('metadata', []);

		$refusal = $this->writes->refusedBulkMetadata(user: $user, ids: $ids);
		if ($refusal !== null) {
			return $refusal;
		}

		return new JSONResponse(
			['results' => $this->writes->updateAll(user: $user, ids: $ids, metadata: $metadata)]
		);
	}//end bulkUpdateMetadata()

}//end class
