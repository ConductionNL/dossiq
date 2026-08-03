<?php

/**
 * Procest Zaakdossier Controller
 *
 * Authenticated REST API for the ZGW DRC case dossier: list/upload documents,
 * link/unlink existing informatieobjecten, update metadata, transition status
 * (single + bulk), export a ZIP with manifest, download a single file, and a
 * ZGW DRC-compatible streaming download endpoint with HTTP Range support.
 *
 * Every read/download endpoint enforces {@see InformatieobjectAccessGuard} so
 * confidentiality (`vertrouwelijkheidaanduiding`) is gated server-side and
 * per-object — never relying on the UI alone (OWASP A01:2021, ADR-005 Rule 3).
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Http\RangeStreamResponse;
use OCA\Procest\Service\InformatieobjectAccessGuard;
use OCA\Procest\Service\ZaakdossierService;
use OCA\Procest\Service\ZgwDocumentService;
use OCA\Procest\Service\ZipManifestBuilder;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\NotPermittedException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Controller for the ZGW DRC zaakdossier.
 */
class ZaakdossierController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                      $appName         The app name.
     * @param IRequest                    $request         The request.
     * @param ZaakdossierService          $dossierService  The dossier orchestrator.
     * @param InformatieobjectAccessGuard $accessGuard     The confidentiality guard.
     * @param ZipManifestBuilder          $zipBuilder      The ZIP export builder.
     * @param ZgwDocumentService          $documentService The binary storage service.
     * @param IUserSession                $userSession     The user session.
     * @param LoggerInterface             $logger          The logger.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ZaakdossierService $dossierService,
        private readonly InformatieobjectAccessGuard $accessGuard,
        private readonly ZipManifestBuilder $zipBuilder,
        private readonly ZgwDocumentService $documentService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
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
     * @spec openspec/changes/document-zaakdossier/tasks.md#T05
     */
    public function listDossier(string $caseId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $dossier = $this->dossierService->getDossierForCase(caseId: $caseId);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        $filtered = $this->accessGuard->filterDossierForUser(
            user: $user,
            informatieobjecten: ($dossier['informatieobjecten'] ?? []),
        );

        $regrouped = $this->dossierService->groupByType(documents: $filtered);

        return new JSONResponse($regrouped);
    }//end listDossier()

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
     * @spec openspec/changes/document-zaakdossier/tasks.md#T05
     */
    public function uploadDocument(string $caseId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $metadata = $this->decodeMetadata(raw: $this->request->getParam('metadata', '{}'));
        if (($metadata['auteur'] ?? '') === '') {
            $metadata['auteur'] = $user->getDisplayName();
        }

        $files = $this->normaliseUploadedFiles(uploaded: $this->request->getUploadedFile('files'));
        if (empty($files) === true) {
            return new JSONResponse(['error' => 'No files uploaded'], Http::STATUS_BAD_REQUEST);
        }

        $results = [];
        foreach ($files as $file) {
            $name    = (string) ($file['name'] ?? '');
            $tmpName = (string) ($file['tmp_name'] ?? '');
            try {
                if ($this->isExecutable(name: $name, tmpName: $tmpName) === true) {
                    throw new RuntimeException('Executable files are not permitted: '.$name);
                }

                $content = '';
                if ($tmpName !== '') {
                    $content = (string) file_get_contents($tmpName);
                }

                $meta = $metadata;
                if (isset($file['type']) === true && $file['type'] !== '') {
                    $meta['formaat'] = $file['type'];
                }

                $created   = $this->dossierService->uploadDocument(
                    caseId: $caseId,
                    fileName: $name,
                    content: $content,
                    metadata: $meta,
                );
                $results[] = ['name' => $name, 'success' => true, 'informatieobject' => $created];
            } catch (\Throwable $e) {
                $results[] = ['name' => $name, 'success' => false, 'error' => $e->getMessage()];
            }//end try
        }//end foreach

        return new JSONResponse(['results' => $results], Http::STATUS_CREATED);
    }//end uploadDocument()

    /**
     * Link an existing informatieobject to a case.
     *
     * @param string $caseId       The case UUID.
     * @param string $infoObjectId The informatieobject UUID.
     *
     * @return JSONResponse The join result.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/document-zaakdossier/tasks.md#T05
     */
    public function linkExisting(string $caseId, string $infoObjectId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $authError = $this->guardReadable(user: $user, infoObjectId: $infoObjectId);
        if ($authError !== null) {
            return $authError;
        }

        try {
            $result = $this->dossierService->linkExistingInformatieobject(caseId: $caseId, infoObjectId: $infoObjectId);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        return new JSONResponse($result, Http::STATUS_CREATED);
    }//end linkExisting()

    /**
     * Unlink an informatieobject from a case (preserves the document).
     *
     * @param string $caseId       The case UUID.
     * @param string $infoObjectId The informatieobject UUID.
     *
     * @return JSONResponse The unlink result.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/document-zaakdossier/tasks.md#T05
     */
    public function unlinkDocument(string $caseId, string $infoObjectId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $authError = $this->guardReadable(user: $user, infoObjectId: $infoObjectId);
        if ($authError !== null) {
            return $authError;
        }

        try {
            $removed = $this->dossierService->unlinkInformatieobject(caseId: $caseId, infoObjectId: $infoObjectId);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        return new JSONResponse(['unlinked' => $removed]);
    }//end unlinkDocument()

    /**
     * Update editable metadata on an informatieobject.
     *
     * @param string $infoObjectId The informatieobject UUID.
     *
     * @return JSONResponse The updated metadata or an error status.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/document-zaakdossier/tasks.md#T05
     */
    public function updateMetadata(string $infoObjectId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $authError = $this->guardReadable(user: $user, infoObjectId: $infoObjectId);
        if ($authError !== null) {
            return $authError;
        }

        $metadata = [
            'titel'                       => $this->request->getParam('titel'),
            'beschrijving'                => $this->request->getParam('beschrijving'),
            'informatieobjecttype'        => $this->request->getParam('informatieobjecttype'),
            'vertrouwelijkheidaanduiding' => $this->request->getParam('vertrouwelijkheidaanduiding'),
        ];
        $metadata = array_filter($metadata, static fn($value) => $value !== null);

        try {
            $result = $this->dossierService->updateMetadata(infoObjectId: $infoObjectId, metadata: $metadata);
        } catch (\DomainException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        return new JSONResponse($result);
    }//end updateMetadata()

    /**
     * Transition a single informatieobject's status.
     *
     * @param string $infoObjectId The informatieobject UUID.
     *
     * @return JSONResponse The transition result. HTTP 400 on an invalid transition.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/document-zaakdossier/tasks.md#T05
     */
    public function transitionStatus(string $infoObjectId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $authError = $this->guardReadable(user: $user, infoObjectId: $infoObjectId);
        if ($authError !== null) {
            return $authError;
        }

        $newStatus = (string) $this->request->getParam('status', '');

        try {
            $result = $this->dossierService->transitionStatus(infoObjectId: $infoObjectId, newStatus: $newStatus);
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
     * @spec openspec/changes/document-zaakdossier/tasks.md#T05
     */
    public function bulkTransitionStatus(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $ids       = (array) $this->request->getParam('ids', []);
        $newStatus = (string) $this->request->getParam('status', '');

        // Per-object clearance gate before any mutation.
        foreach ($ids as $id) {
            if ($this->guardReadable(user: $user, infoObjectId: (string) $id) !== null) {
                return new JSONResponse(
                    ['error' => 'Insufficient clearance for one or more selected documents'],
                    Http::STATUS_FORBIDDEN,
                );
            }
        }

        $results = $this->dossierService->bulkTransitionStatus(infoObjectIds: $ids, newStatus: $newStatus);

        return new JSONResponse(['results' => $results]);
    }//end bulkTransitionStatus()

    /**
     * Apply a bulk metadata update over multiple informatieobjecten.
     *
     * @return JSONResponse Per-id success/failure list.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/document-zaakdossier/tasks.md#T05
     */
    public function bulkUpdateMetadata(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $ids      = (array) $this->request->getParam('ids', []);
        $metadata = (array) $this->request->getParam('metadata', []);

        $results = [];
        foreach ($ids as $id) {
            $id = (string) $id;
            if ($this->guardReadable(user: $user, infoObjectId: $id) !== null) {
                $results[] = ['id' => $id, 'success' => false, 'error' => 'Insufficient clearance'];
                continue;
            }

            try {
                $this->dossierService->updateMetadata(infoObjectId: $id, metadata: $metadata);
                $results[] = ['id' => $id, 'success' => true];
            } catch (\Throwable $e) {
                $results[] = ['id' => $id, 'success' => false, 'error' => $e->getMessage()];
            }
        }

        return new JSONResponse(['results' => $results]);
    }//end bulkUpdateMetadata()

    /**
     * Export a case dossier as a ZIP with manifest, clearance-filtered.
     *
     * @param string $caseId The case UUID.
     *
     * @return DataDownloadResponse|JSONResponse The ZIP download or an error status.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/document-zaakdossier/tasks.md#T05
     */
    public function downloadZip(string $caseId): DataDownloadResponse | JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $dossier = $this->dossierService->getDossierForCase(caseId: $caseId);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        $documents = ($dossier['informatieobjecten'] ?? []);

        // Allow restricting to a selected subset of ids.
        $selectedIds = (array) $this->request->getParam('ids', []);
        if (empty($selectedIds) === false) {
            $documents = array_values(
                    array_filter(
                $documents,
                static fn(array $doc) => in_array((string) ($doc['id'] ?? ''), array_map('strval', $selectedIds), true),
            )
                    );
        }

        $tmpPath = (string) tempnam(sys_get_temp_dir(), 'procest-dossier-');
        try {
            $this->zipBuilder->buildZip(
                targetPath: $tmpPath,
                user: $user,
                documents: $documents,
                subfolderPerType: $this->subfolderPerTypeEnabled(),
            );
            $data = (string) file_get_contents($tmpPath);
        } catch (\Throwable $e) {
            $this->logger->error('Procest dossier ZIP build failed: '.$e->getMessage());
            return new JSONResponse(['error' => 'ZIP export failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        } finally {
            if (is_file($tmpPath) === true) {
                @unlink($tmpPath);
            }
        }

        return new DataDownloadResponse($data, 'dossier-'.$caseId.'.zip', 'application/zip');
    }//end downloadZip()

    /**
     * Download a single dossier file, gated by clearance.
     *
     * @param string $register The register slug (kept for ZGW DRC path parity).
     * @param string $schema   The schema slug (kept for ZGW DRC path parity).
     * @param string $objectId The informatieobject UUID.
     * @param int    $fileId   The Nextcloud file id (kept for ZGW DRC path parity).
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
    public function downloadFile(string $register, string $schema, string $objectId, int $fileId): DataDownloadResponse | JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        // Per-object clearance gate before any content is read (OWASP A01:2021).
        $authError = $this->guardReadable(user: $user, infoObjectId: $objectId);
        if ($authError !== null) {
            return $authError;
        }

        return $this->streamSingle(infoObjectId: $objectId);
    }//end downloadFile()

    /**
     * ZGW DRC-compatible download with HTTP Range support.
     *
     * @param string $uuid The informatieobject (enkelvoudiginformatieobject) UUID.
     *
     * @return \OCP\AppFramework\Http\Response|JSONResponse Full (200) or partial (206) content, or an error status.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/document-zaakdossier/tasks.md#T05
     */
    public function downloadZgwDocumenten(string $uuid): \OCP\AppFramework\Http\Response | JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $doc = $this->loadReadable(user: $user, infoObjectId: $uuid);
        if ($doc instanceof JSONResponse) {
            return $doc;
        }

        $fileName = (string) ($doc['bestandsnaam'] ?? 'document');
        try {
            $content = $this->documentService->getContent(uuid: $uuid, fileName: $fileName);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => 'File not found'], Http::STATUS_NOT_FOUND);
        }

        $rangeHeader = (string) $this->request->getHeader('Range');
        $mime        = (string) ($doc['formaat'] ?? 'application/octet-stream');

        return new RangeStreamResponse(
            content: $content,
            fileName: $fileName,
            contentType: $mime,
            rangeHeader: $rangeHeader,
        );
    }//end downloadZgwDocumenten()

    /**
     * Stream a single file as a download after a clearance check.
     *
     * @param string $infoObjectId The informatieobject UUID.
     *
     * @return DataDownloadResponse|JSONResponse The download or an error status.
     */
    private function streamSingle(string $infoObjectId): DataDownloadResponse | JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $doc = $this->loadReadable(user: $user, infoObjectId: $infoObjectId);
        if ($doc instanceof JSONResponse) {
            return $doc;
        }

        $fileName = (string) ($doc['bestandsnaam'] ?? 'document');
        try {
            $content = $this->documentService->getContent(uuid: $infoObjectId, fileName: $fileName);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => 'File not found'], Http::STATUS_NOT_FOUND);
        }

        $mime = (string) ($doc['formaat'] ?? 'application/octet-stream');
        return new DataDownloadResponse($content, $fileName, $mime);
    }//end streamSingle()

    /**
     * Load an informatieobject, returning a 404/403 JSONResponse on failure.
     *
     * @param IUser  $user         The requesting user.
     * @param string $infoObjectId The informatieobject UUID.
     *
     * @return array<string, mixed>|JSONResponse The document, or an error response.
     */
    private function loadReadable(IUser $user, string $infoObjectId): array | JSONResponse
    {
        try {
            $doc = $this->dossierService->getInformatieobject(infoObjectId: $infoObjectId);
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
     * Per-object clearance guard returning a 403/404 JSONResponse on denial.
     *
     * @param IUser  $user         The requesting user.
     * @param string $infoObjectId The informatieobject UUID.
     *
     * @return JSONResponse|null Null when readable, otherwise the error response.
     */
    private function guardReadable(IUser $user, string $infoObjectId): ?JSONResponse
    {
        $result = $this->loadReadable(user: $user, infoObjectId: $infoObjectId);
        if ($result instanceof JSONResponse) {
            return $result;
        }

        return null;
    }//end guardReadable()

    /**
     * Whether ZIP exports should be organised into per-type sub-folders.
     *
     * @return bool
     */
    private function subfolderPerTypeEnabled(): bool
    {
        return $this->request->getParam('subfolderPerType', '1') !== '0';
    }//end subfolderPerTypeEnabled()

    /**
     * Decode the shared metadata JSON body into an array.
     *
     * @param mixed $raw The raw metadata param (JSON string or array).
     *
     * @return array<string, mixed>
     */
    private function decodeMetadata(mixed $raw): array
    {
        if (is_array($raw) === true) {
            return $raw;
        }

        if (is_string($raw) === true && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) === true) {
                return $decoded;
            }
        }

        return [];
    }//end decodeMetadata()

    /**
     * Normalise the PHP uploaded-file structure into a flat list of files.
     *
     * @param mixed $uploaded The value returned by IRequest::getUploadedFile().
     *
     * @return array<int, array<string, mixed>> A list of single-file arrays.
     */
    private function normaliseUploadedFiles(mixed $uploaded): array
    {
        if (is_array($uploaded) === false) {
            return [];
        }

        // Single-file shape: ['name' => 'x', 'tmp_name' => '/tmp/...'].
        if (isset($uploaded['name']) === true && is_array($uploaded['name']) === false) {
            return [$uploaded];
        }

        // Multi-file shape: ['name' => [...], 'tmp_name' => [...], ...].
        if (isset($uploaded['name']) === true && is_array($uploaded['name']) === true) {
            $files = [];
            foreach (array_keys($uploaded['name']) as $index) {
                $files[] = [
                    'name'     => ($uploaded['name'][$index] ?? ''),
                    'type'     => ($uploaded['type'][$index] ?? ''),
                    'tmp_name' => ($uploaded['tmp_name'][$index] ?? ''),
                    'size'     => ($uploaded['size'][$index] ?? 0),
                ];
            }

            return $files;
        }

        return [];
    }//end normaliseUploadedFiles()

    /**
     * Detect executable uploads via extension and magic bytes.
     *
     * @param string $name    The original filename.
     * @param string $tmpName The temp path of the uploaded content.
     *
     * @return bool True when the file appears to be an executable.
     */
    private function isExecutable(string $name, string $tmpName): bool
    {
        $blockedExtensions = ['exe', 'bat', 'cmd', 'com', 'msi', 'scr', 'sh', 'php', 'phar', 'dll'];
        $extension         = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($extension, $blockedExtensions, true) === true) {
            return true;
        }

        if ($tmpName === '' || is_readable($tmpName) === false) {
            return false;
        }

        $handle = fopen($tmpName, 'rb');
        if ($handle === false) {
            return false;
        }

        $magic = (string) fread($handle, 4);
        fclose($handle);

        // MZ (PE/DOS), ELF (\x7fELF) and shell shebang.
        if (str_starts_with($magic, 'MZ') === true) {
            return true;
        }

        if (str_starts_with($magic, "\x7f".'ELF') === true) {
            return true;
        }

        if (str_starts_with($magic, '#!') === true) {
            return true;
        }

        return false;
    }//end isExecutable()
}//end class
