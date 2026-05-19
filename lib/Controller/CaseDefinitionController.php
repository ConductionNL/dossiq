<?php

/**
 * Procest Case Definition Controller
 *
 * Handles API endpoints for exporting and importing case type definitions
 * as portable ZIP archives for DTAP pipeline deployment.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\CaseDefinitionExportService;
use OCA\Procest\Service\CaseDefinitionImportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for case definition export/import operations.
 *
 * @psalm-suppress UnusedClass
 */
class CaseDefinitionController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                      $appName       The app name.
     * @param IRequest                    $request       The request object.
     * @param CaseDefinitionExportService $exportService The export service.
     * @param CaseDefinitionImportService $importService The import service.
     * @param LoggerInterface             $logger        The logger.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly CaseDefinitionExportService $exportService,
        private readonly CaseDefinitionImportService $importService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Export a case definition as a ZIP archive.
     *
     * @return DataDownloadResponse|JSONResponse
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function export(): DataDownloadResponse|JSONResponse
    {
        try {
            $caseTypeId = $this->request->getParam('caseTypeId', '');
            $components = $this->request->getParam('components', []);

            if (empty($caseTypeId) === true) {
                return new JSONResponse(
                    ['error' => 'Parameter caseTypeId is required'],
                    Http::STATUS_BAD_REQUEST
                );
            }

            if (is_string($components) === true) {
                $components = json_decode($components, true) ?? [];
            }

            $result = $this->exportService->exportCaseDefinition($caseTypeId, $components);

            $content = file_get_contents($result['path']);
            unlink($result['path']);

            if ($content === false) {
                return new JSONResponse(
                    ['error' => 'Failed to read export file'],
                    Http::STATUS_INTERNAL_SERVER_ERROR
                );
            }

            return new DataDownloadResponse(
                $content,
                $result['filename'],
                'application/zip'
            );
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_BAD_REQUEST
            );
        } catch (\Throwable $e) {
            $this->logger->error('Case definition export failed: '.$e->getMessage());
            return new JSONResponse(
                ['error' => 'Export failed: '.$e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end export()

    /**
     * Validate a case definition package without importing it.
     *
     * @return JSONResponse
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function validate(): JSONResponse
    {
        try {
            $file = $this->request->getUploadedFile('package');

            if (is_array($file) === false || isset($file['tmp_name']) === false) {
                return new JSONResponse(
                    ['error' => 'No package file uploaded'],
                    Http::STATUS_BAD_REQUEST
                );
            }

            $result = $this->importService->validatePackage($file['tmp_name']);

            return new JSONResponse($result);
        } catch (\Throwable $e) {
            $this->logger->error('Case definition validation failed: '.$e->getMessage());
            return new JSONResponse(
                ['error' => 'Validation failed: '.$e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }//end validate()

    /**
     * Import a case definition package.
     *
     * @return JSONResponse
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function import(): JSONResponse
    {
        try {
            $file     = $this->request->getUploadedFile('package');
            $strategy = $this->request->getParam('strategy', 'skip');

            if (is_array($file) === false || isset($file['tmp_name']) === false) {
                return new JSONResponse(
                    ['error' => 'No package file uploaded'],
                    Http::STATUS_BAD_REQUEST
                );
            }

            if (in_array($strategy, ['skip', 'overwrite', 'merge'], true) === false) {
                return new JSONResponse(
                    ['error' => 'Invalid strategy. Must be: skip, overwrite, or merge'],
                    Http::STATUS_BAD_REQUEST
                );
            }

            $result = $this->importService->importCaseDefinition(
                $file['tmp_name'],
                $strategy
            );

            if ($result['success'] === true) {
                $statusCode = Http::STATUS_OK;
            } else {
                $statusCode = Http::STATUS_UNPROCESSABLE_ENTITY;
            }

            return new JSONResponse($result, $statusCode);
        } catch (\Throwable $e) {
            $this->logger->error('Case definition import failed: '.$e->getMessage());
            return new JSONResponse(
                ['error' => 'Import failed: '.$e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end import()
}//end class
