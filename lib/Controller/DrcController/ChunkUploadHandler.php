<?php

/**
 * ChunkUpload Handler
 *
 * Extracted handler for DRC bestandsdelen (chunked file upload) operations.
 * Receives raw binary data for a single chunk and stores it, then merges
 * all chunks into the final file when the upload is complete.
 *
 * @category Controller
 * @package  OCA\Procest\Controller\DrcController
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-6
 */

declare(strict_types=1);

namespace OCA\Procest\Controller\DrcController;

use OCA\Procest\Service\ZgwService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Handler for chunked document file upload (bestandsdelen).
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-7
 */
class ChunkUploadHandler
{

    /**
     * The ZGW API identifier for the Documenten register.
     *
     * @var string
     */
    private const ZGW_API = 'documenten';

    /**
     * The EIO resource name.
     *
     * @var string
     */
    private const EIO_RESOURCE = 'enkelvoudiginformatieobjecten';

    /**
     * Constructor.
     *
     * @param ZgwService $zgwService The shared ZGW service.
     * @param IL10N      $l10n       The localization service.
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-7
     */
    public function __construct(
        private readonly ZgwService $zgwService,
        private readonly IL10N $l10n,
    ) {
    }//end __construct()

    /**
     * Upload a chunk (bestandsdeel) for a document.
     *
     * Receives raw binary data for a single chunk and stores it.
     * When all chunks have been uploaded, merges them into the final file.
     *
     * @param string   $uuid    The document UUID.
     * @param IRequest $request The incoming request.
     *
     * @return JSONResponse
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-7
     */
    public function uploadChunk(string $uuid, IRequest $request): JSONResponse
    {
        $objectService = $this->zgwService->getObjectService();
        if ($objectService === null) {
            return $this->zgwService->unavailableResponse();
        }

        $mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, self::EIO_RESOURCE);
        if ($mappingConfig === null) {
            return $this->zgwService->mappingNotFoundResponse(self::ZGW_API, self::EIO_RESOURCE);
        }

        try {
            // Find the EIO object.
            $existing = $objectService->find(
                $uuid,
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema']
            );
            if (is_array($existing) === true) {
                $objectData = $existing;
            } else {
                $objectData = $existing->jsonSerialize();
            }

            // Verify this document has a pending chunked upload.
            $chunkInfo = $this->parseFileParts(objectData: $objectData);
            if ($chunkInfo === null || ($chunkInfo['pending'] ?? false) !== true) {
                return new JSONResponse(
                    data: ['detail' => $this->l10n->t('This document has no pending chunked upload.')],
                    statusCode: Http::STATUS_BAD_REQUEST
                );
            }

            $totalParts = (int) ($chunkInfo['totalParts'] ?? 0);
            if ($totalParts <= 0) {
                return new JSONResponse(
                    data: ['detail' => $this->l10n->t('Invalid chunk configuration.')],
                    statusCode: Http::STATUS_BAD_REQUEST
                );
            }

            // Get volgnummer from query parameter or request body.
            $volgnummer = (int) ($request->getParam('volgnummer') ?? 0);
            if ($volgnummer <= 0 || $volgnummer > $totalParts) {
                return new JSONResponse(
                    data: ['detail' => $this->l10n->t('Invalid sequence number. Expected 1-%s.', [$totalParts])],
                    statusCode: Http::STATUS_BAD_REQUEST
                );
            }

            // Read raw body content.
            $content = file_get_contents('php://input');
            if ($content === false || $content === '') {
                return new JSONResponse(
                    data: ['detail' => $this->l10n->t('No file content received.')],
                    statusCode: Http::STATUS_BAD_REQUEST
                );
            }

            // Store the chunk.
            $docService = $this->zgwService->getDocumentService();
            $chunkSize  = $docService->storeChunk(
                uuid: $uuid,
                volgnummer: $volgnummer,
                content: $content
            );

            // Check if all chunks have been uploaded.
            $uploaded = $docService->getUploadedChunks(uuid: $uuid, totalParts: $totalParts);

            if (count($uploaded) === $totalParts) {
                // All chunks present — merge into final file.
                $fileName = $objectData['fileName'] ?? 'document';
                if ($fileName === '') {
                    $fileName = 'document';
                }

                $mergedSize = $docService->mergeChunks(
                    uuid: $uuid,
                    fileName: $fileName,
                    totalParts: $totalParts
                );

                // Update the object: clear chunk metadata, set file size.
                unset(
                    $objectData['@self'],
                    $objectData['id'],
                    $objectData['organisation']
                );
                $objectData['fileParts'] = '';
                $objectData['fileSize']  = $mergedSize;

                $objectService->saveObject(
                    register: $mappingConfig['sourceRegister'],
                    schema: $mappingConfig['sourceSchema'],
                    object: $objectData,
                    uuid: $uuid
                );

                return new JSONResponse(
                    data: [
                        'volgnummer'     => $volgnummer,
                        'omvang'         => $chunkSize,
                        'uploadComplete' => true,
                        'bestandsomvang' => $mergedSize,
                        'uploadedParts'  => count($uploaded),
                        'totalParts'     => $totalParts,
                    ],
                    statusCode: Http::STATUS_OK
                );
            }//end if

            return new JSONResponse(
                data: [
                    'volgnummer'     => $volgnummer,
                    'omvang'         => $chunkSize,
                    'uploadComplete' => false,
                    'uploadedParts'  => count($uploaded),
                    'totalParts'     => $totalParts,
                ],
                statusCode: Http::STATUS_OK
            );
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->error(
                'DRC chunk upload error: '.$e->getMessage(),
                ['exception' => $e]
            );

            return new JSONResponse(
                data: ['detail' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }//end try
    }//end uploadChunk()

    /**
     * Parse the fileParts field from an object's data.
     *
     * @param array $objectData The object data array.
     *
     * @return array|null Decoded chunk info, or null if not set.
     */
    private function parseFileParts(array $objectData): ?array
    {
        $raw = $objectData['fileParts'] ?? '';
        if ($raw === '') {
            return null;
        }

        if (is_string($raw) === true) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) === true) {
                return $decoded;
            }

            return null;
        }

        if (is_array($raw) === true) {
            return $raw;
        }

        return null;
    }//end parseFileParts()
}//end class
