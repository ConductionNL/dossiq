<?php

/**
 * Procest Zaakdossier Controller
 *
 * Authenticated REST controller for the ZGW DRC-compliant zaakdossier.
 * Endpoints: list dossier, upload, link, unlink, metadata update, status
 * transition, bulk ops, ZIP export, file download, and ZGW DRC-compatible
 * streaming download.
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
 * @spec openspec/changes/document-zaakdossier/tasks.md#task-T05
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\ZaakdossierService;
use OCA\Procest\Service\InformatieobjectAccessGuard;
use OCA\Procest\Service\ZipManifestBuilder;
use OCA\Procest\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for zaakdossier (case document dossier) endpoints.
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#task-T05
 */
class ZaakdossierController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                      $appName            The app name
     * @param IRequest                    $request            The HTTP request
     * @param ZaakdossierService          $zaakdossierService The dossier service
     * @param InformatieobjectAccessGuard $accessGuard        The access guard
     * @param ZipManifestBuilder          $zipBuilder         The ZIP manifest builder
     * @param SettingsService             $settingsService    The settings service
     * @param IUserSession                $userSession        The user session
     * @param LoggerInterface             $logger             The logger
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ZaakdossierService $zaakdossierService,
        private readonly InformatieobjectAccessGuard $accessGuard,
        private readonly ZipManifestBuilder $zipBuilder,
        private readonly SettingsService $settingsService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * List all informatieobjecten for a case, grouped by type.
     *
     * @param string $caseId UUID of the case
     *
     * @return JSONResponse Grouped dossier
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/document-zaakdossier/tasks.md#task-T05
     */
    public function listDossier(string $caseId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $grouped = $this->zaakdossierService->getDossierForCase(caseId: $caseId);
            return new JSONResponse(
                    [
                        'caseId'  => $caseId,
                        'dossier' => $grouped,
                        'count'   => array_sum(array_map('count', $grouped)),
                    ]
                    );
        } catch (\Throwable $e) {
            $this->logger->error('ZaakdossierController: listDossier failed: '.$e->getMessage());
            return new JSONResponse(
                ['error' => 'Could not load dossier'],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }
    }//end listDossier()

    /**
     * Upload a file and create an informatieobject + zaakinformatieobject.
     *
     * @param string $caseId UUID of the case
     *
     * @return JSONResponse Created informatieobject
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/document-zaakdossier/tasks.md#task-T05
     */
    public function uploadDocument(string $caseId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $file     = $this->request->getUploadedFile('file') ?? [];
        $metadata = $this->readJsonBody();

        if (empty($file) === true) {
            return new JSONResponse(['error' => 'No file uploaded'], Http::STATUS_BAD_REQUEST);
        }

        $required = ['titel', 'vertrouwelijkheidaanduiding', 'informatieobjecttype'];
        foreach ($required as $field) {
            if (empty($metadata[$field]) === true) {
                return new JSONResponse(
                    ['error' => 'Missing required field: '.$field],
                    Http::STATUS_BAD_REQUEST,
                );
            }
        }

        try {
            $infoObject = $this->zaakdossierService->uploadDocument(
                caseId: $caseId,
                file: $file,
                metadata: $metadata,
            );
            return new JSONResponse($infoObject, Http::STATUS_CREATED);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('ZaakdossierController: uploadDocument failed: '.$e->getMessage());
            return new JSONResponse(
                ['error' => 'Upload failed'],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }
    }//end uploadDocument()

    /**
     * Link an existing informatieobject to a case.
     *
     * @param string $caseId       UUID of the case
     * @param string $infoObjectId UUID of the informatieobject
     *
     * @return JSONResponse The join record
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/document-zaakdossier/tasks.md#task-T05
     */
    public function linkExisting(string $caseId, string $infoObjectId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $join = $this->zaakdossierService->linkExistingInformatieobject(
                caseId: $caseId,
                infoObjectId: $infoObjectId,
            );
            return new JSONResponse($join);
        } catch (\Throwable $e) {
            $this->logger->error('ZaakdossierController: linkExisting failed: '.$e->getMessage());
            return new JSONResponse(['error' => 'Link failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end linkExisting()

    /**
     * Unlink an informatieobject from a case (preserves the document).
     *
     * @param string $caseId       UUID of the case
     * @param string $infoObjectId UUID of the informatieobject
     *
     * @return JSONResponse Empty success response
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/document-zaakdossier/tasks.md#task-T05
     */
    public function unlinkDocument(string $caseId, string $infoObjectId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $this->zaakdossierService->unlinkInformatieobject(
                caseId: $caseId,
                infoObjectId: $infoObjectId,
            );
            return new JSONResponse(['status' => 'unlinked']);
        } catch (\Throwable $e) {
            $this->logger->error('ZaakdossierController: unlinkDocument failed: '.$e->getMessage());
            return new JSONResponse(['error' => 'Unlink failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end unlinkDocument()

    /**
     * Update metadata fields on an informatieobject.
     *
     * @param string $infoObjectId UUID of the informatieobject
     *
     * @return JSONResponse Updated record
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/document-zaakdossier/tasks.md#task-T05
     */
    public function updateMetadata(string $infoObjectId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return new JSONResponse(['error' => 'Service unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        $schema = $this->settingsService->getConfigValue('dossier_informatieobject_schema');
        $data   = $this->readJsonBody();

        try {
            $infoObject = $objectService->findObject(register: 'procest', schema: $schema, id: $infoObjectId);
            if ($infoObject === null) {
                return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
            }

            $this->accessGuard->requireRead(user: $user, informatieobject: $infoObject);

            $allowed = ['titel', 'beschrijving', 'informatieobjecttype', 'vertrouwelijkheidaanduiding'];
            foreach ($allowed as $field) {
                if (isset($data[$field]) === true) {
                    $infoObject[$field] = $data[$field];
                }
            }

            $updated = $objectService->saveObject(register: 'procest', schema: $schema, object: $infoObject);
            return new JSONResponse($updated);
        } catch (\OCP\Files\NotPermittedException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\Throwable $e) {
            $this->logger->error('ZaakdossierController: updateMetadata failed: '.$e->getMessage());
            return new JSONResponse(['error' => 'Update failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try
    }//end updateMetadata()

    /**
     * Transition the status of an informatieobject.
     *
     * @param string $infoObjectId UUID of the informatieobject
     *
     * @return JSONResponse Updated record
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/document-zaakdossier/tasks.md#task-T05
     */
    public function transitionStatus(string $infoObjectId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $data      = $this->readJsonBody();
        $newStatus = (string) ($data['status'] ?? '');

        if ($newStatus === '') {
            return new JSONResponse(['error' => 'status is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $updated = $this->zaakdossierService->transitionStatus(
                infoObjectId: $infoObjectId,
                newStatus: $newStatus,
            );
            return new JSONResponse($updated);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('ZaakdossierController: transitionStatus failed: '.$e->getMessage());
            return new JSONResponse(['error' => 'Transition failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end transitionStatus()

    /**
     * Bulk-transition multiple informatieobjecten to a new status.
     *
     * @return JSONResponse Per-id success/failure results
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/document-zaakdossier/tasks.md#task-T05
     */
    public function bulkTransitionStatus(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $data   = $this->readJsonBody();
        $ids    = (array) ($data['ids'] ?? []);
        $status = (string) ($data['status'] ?? '');

        if (empty($ids) === true || $status === '') {
            return new JSONResponse(['error' => 'ids and status are required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $results = $this->zaakdossierService->bulkTransitionStatus(
                infoObjectIds: $ids,
                newStatus: $status,
            );
            return new JSONResponse($results);
        } catch (\Throwable $e) {
            $this->logger->error('ZaakdossierController: bulkTransitionStatus failed: '.$e->getMessage());
            return new JSONResponse(['error' => 'Bulk transition failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end bulkTransitionStatus()

    /**
     * Bulk-update metadata on multiple informatieobjecten.
     *
     * @return JSONResponse Per-id success/failure results
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/document-zaakdossier/tasks.md#task-T05
     */
    public function bulkUpdateMetadata(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return new JSONResponse(['error' => 'Service unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        $data   = $this->readJsonBody();
        $ids    = (array) ($data['ids'] ?? []);
        $schema = $this->settingsService->getConfigValue('dossier_informatieobject_schema');
        $patch  = array_intersect_key(
            $data,
            array_flip(['vertrouwelijkheidaanduiding', 'informatieobjecttype', 'beschrijving']),
        );

        if (empty($ids) === true || empty($patch) === true) {
            return new JSONResponse(
                ['error' => 'ids and at least one metadata field are required'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        $results = [];
        foreach ($ids as $id) {
            try {
                $obj = $objectService->findObject(register: 'procest', schema: $schema, id: (string) $id);
                if ($obj === null) {
                    $results[$id] = ['success' => false, 'error' => 'not found'];
                    continue;
                }

                $this->accessGuard->requireRead(user: $user, informatieobject: $obj);
                $updated      = $objectService->saveObject(
                    register: 'procest',
                    schema: $schema,
                    object: array_merge($obj, $patch),
                );
                $results[$id] = ['success' => true, 'object' => $updated];
            } catch (\OCP\Files\NotPermittedException $e) {
                $results[$id] = ['success' => false, 'error' => 'forbidden'];
            } catch (\Throwable $e) {
                $results[$id] = ['success' => false, 'error' => $e->getMessage()];
            }
        }//end foreach

        return new JSONResponse($results);
    }//end bulkUpdateMetadata()

    /**
     * Download a ZIP archive of the dossier (selected or full).
     *
     * @param string $caseId UUID of the case
     *
     * @return DataDownloadResponse|JSONResponse ZIP file or error
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/document-zaakdossier/tasks.md#task-T05
     */
    public function downloadZip(string $caseId): DataDownloadResponse|JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $data = $this->readJsonBody();
        if (isset($data['ids']) === true) {
            $selectedIds = (array) $data['ids'];
        } else {
            $selectedIds = null;
        }

        try {
            $grouped = $this->zaakdossierService->getDossierForCase(caseId: $caseId);
            $flat    = array_merge(...array_values($grouped));

            if ($selectedIds !== null) {
                $flat = array_filter($flat, fn(array $io) => in_array($io['id'] ?? '', $selectedIds, true));
                $flat = array_values($flat);
            }

            $zipPath = $this->zipBuilder->buildZip(user: $user, informatieobjecten: $flat);

            $content = file_get_contents($zipPath);
            unlink($zipPath);

            if ($content === false) {
                return new JSONResponse(['error' => 'ZIP generation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
            }

            return new DataDownloadResponse(
                $content,
                'zaakdossier-'.$caseId.'.zip',
                'application/zip',
            );
        } catch (\Throwable $e) {
            $this->logger->error('ZaakdossierController: downloadZip failed: '.$e->getMessage());
            return new JSONResponse(['error' => 'ZIP export failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try
    }//end downloadZip()

    /**
     * Download a single file from the dossier (vertrouwelijkheid-gated).
     *
     * @param string $register Register slug
     * @param string $schema   Schema slug
     * @param string $objectId Object UUID
     * @param string $fileId   Nextcloud file node ID
     *
     * @return DataDownloadResponse|JSONResponse File download or error
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/document-zaakdossier/tasks.md#task-T05
     */
    public function downloadFile(string $register, string $schema, string $objectId, string $fileId): DataDownloadResponse|JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return new JSONResponse(['error' => 'Service unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        try {
            $infoSchema = $this->settingsService->getConfigValue('dossier_informatieobject_schema');
            $infoObject = $objectService->findObject(
                register: 'procest',
                schema: $infoSchema,
                id: $objectId,
            );

            if ($infoObject === null) {
                return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
            }

            $this->accessGuard->requireRead(user: $user, informatieobject: $infoObject);

            $root  = \OC::$server->get(\OCP\Files\IRootFolder::class);
            $nodes = $root->getById((int) $fileId);

            if (empty($nodes) === true) {
                return new JSONResponse(['error' => 'File not found'], Http::STATUS_NOT_FOUND);
            }

            $node = $nodes[0];
            if ($node instanceof \OCP\Files\File) {
                $content = $node->getContent();
            } else {
                $content = null;
            }

            if ($content === null) {
                return new JSONResponse(['error' => 'File not readable'], Http::STATUS_INTERNAL_SERVER_ERROR);
            }

            return new DataDownloadResponse(
                $content,
                $infoObject['bestandsnaam'] ?? 'document',
                $infoObject['formaat'] ?? 'application/octet-stream',
            );
        } catch (\OCP\Files\NotPermittedException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\Throwable $e) {
            $this->logger->error('ZaakdossierController: downloadFile failed: '.$e->getMessage());
            return new JSONResponse(['error' => 'Download failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try
    }//end downloadFile()

    /**
     * ZGW DRC-compatible download of an enkelvoudiginformatieobject by UUID.
     *
     * Supports HTTP Range headers for resumable downloads.
     *
     * @param string $uuid UUID of the informatieobject
     *
     * @return StreamResponse|JSONResponse Streaming file response or error
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/document-zaakdossier/tasks.md#task-T05
     */
    public function downloadZgwDocumenten(string $uuid): StreamResponse|JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return new JSONResponse(['error' => 'Service unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        try {
            $schema     = $this->settingsService->getConfigValue('dossier_informatieobject_schema');
            $infoObject = $objectService->findObject(register: 'procest', schema: $schema, id: $uuid);

            if ($infoObject === null) {
                return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
            }

            $this->accessGuard->requireRead(user: $user, informatieobject: $infoObject);

            $fileId = $infoObject['fileId'] ?? null;
            if ($fileId === null) {
                return new JSONResponse(['error' => 'No file attached'], Http::STATUS_NOT_FOUND);
            }

            $root  = \OC::$server->get(\OCP\Files\IRootFolder::class);
            $nodes = $root->getById((int) $fileId);

            if (empty($nodes) === true) {
                return new JSONResponse(['error' => 'File not found'], Http::STATUS_NOT_FOUND);
            }

            $node = $nodes[0];
            if (($node instanceof \OCP\Files\File) === false) {
                return new JSONResponse(['error' => 'Not a file'], Http::STATUS_UNPROCESSABLE_ENTITY);
            }

            $stream = $node->fopen('r');
            $mime   = $infoObject['formaat'] ?? 'application/octet-stream';
            $name   = $infoObject['bestandsnaam'] ?? 'document';
            $size   = $infoObject['bestandsomvang'] ?? $node->getSize();

            $rangeHeader = $this->request->getHeader('Range');
            if ($rangeHeader !== '' && $rangeHeader !== null) {
                return $this->buildRangeResponse(stream: $stream, size: (int) $size, rangeHeader: $rangeHeader, mime: $mime, name: $name);
            }

            $response = new StreamResponse($stream);
            $response->addHeader('Content-Type', $mime);
            $response->addHeader('Content-Disposition', 'attachment; filename="'.$name.'"');
            $response->addHeader('Content-Length', (string) $size);

            return $response;
        } catch (\OCP\Files\NotPermittedException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\Throwable $e) {
            $this->logger->error('ZaakdossierController: downloadZgwDocumenten failed: '.$e->getMessage());
            return new JSONResponse(['error' => 'Download failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try
    }//end downloadZgwDocumenten()

    /**
     * Build an HTTP 206 Partial Content response for a Range request.
     *
     * @param resource $stream      Open file stream
     * @param int      $size        Total file size
     * @param string   $rangeHeader Raw Range header value
     * @param string   $mime        MIME type
     * @param string   $name        Filename
     *
     * @return StreamResponse|JSONResponse 206 or 416 response
     */
    private function buildRangeResponse(mixed $stream, int $size, string $rangeHeader, string $mime, string $name): StreamResponse|JSONResponse
    {
        if (preg_match('/bytes=(\d*)-(\d*)/', $rangeHeader, $matches) !== 1) {
            return new JSONResponse(['error' => 'Invalid Range header'], Http::STATUS_REQUEST_RANGE_NOT_SATISFIABLE);
        }

        if ($matches[1] !== '') {
            $start = (int) $matches[1];
        } else {
            $start = 0;
        }

        if ($matches[2] !== '') {
            $end = (int) $matches[2];
        } else {
            $end = $size - 1;
        }

        if ($start > $end || $end >= $size) {
            return new JSONResponse(
                ['error' => 'Range not satisfiable'],
                Http::STATUS_REQUEST_RANGE_NOT_SATISFIABLE,
            );
        }

        fseek($stream, $start);

        $partialStream = fopen('php://temp', 'r+');
        if ($partialStream === false) {
            return new JSONResponse(['error' => 'Stream error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        $length = $end - $start + 1;
        stream_copy_to_stream($stream, $partialStream, $length);
        rewind($partialStream);

        $response = new StreamResponse($partialStream);
        $response->setStatus(Http::STATUS_PARTIAL_CONTENT);
        $response->addHeader('Content-Type', $mime);
        $response->addHeader('Content-Range', "bytes {$start}-{$end}/{$size}");
        $response->addHeader('Content-Length', (string) $length);
        $response->addHeader('Content-Disposition', 'attachment; filename="'.$name.'"');
        $response->addHeader('Accept-Ranges', 'bytes');

        return $response;
    }//end buildRangeResponse()

    /**
     * Decode a JSON request body safely.
     *
     * @return array<string,mixed> Decoded payload or empty array
     */
    private function readJsonBody(): array
    {
        $params = $this->request->getParams();
        if (is_array($params) === true && empty($params) === false) {
            return $params;
        }

        return [];
    }//end readJsonBody()
}//end class
