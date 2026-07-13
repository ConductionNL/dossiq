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
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-procest/tasks.md#task-3
 * @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md#task-1
 * @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md#task-2
 * @spec openspec/changes/zaaktype-copy/tasks.md#T06
 * @spec openspec/changes/zaaktype-copy/tasks.md#T07
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\CaseDefinitionExportService;
use OCA\Procest\Service\CaseDefinitionImportService;
use OCA\Procest\Service\CaseTypeCopyService;
use OCA\Procest\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for case definition export/import operations.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/zaaktype-copy/tasks.md#T06
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
     * @param CaseTypeCopyService         $copyService   The copy/guarded-delete service.
     * @param LoggerInterface             $logger        The logger.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly CaseDefinitionExportService $exportService,
        private readonly CaseDefinitionImportService $importService,
        private readonly CaseTypeCopyService $copyService,
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

      * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
      */
    #[AuthorizedAdminSetting(AdminSettings::class)]
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

      * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
      */
    #[AuthorizedAdminSetting(AdminSettings::class)]
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

      * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
      */
    #[AuthorizedAdminSetting(AdminSettings::class)]
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

    /**
     * Deep-copy a case type into a new draft.
     *
     * @param string $id The case type id to copy.
     *
     * @return JSONResponse
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @spec openspec/changes/zaaktype-copy/tasks.md#T06
     */
    #[AuthorizedAdminSetting(AdminSettings::class)]
    public function copy(string $id): JSONResponse
    {
        try {
            $copy = $this->copyService->copy($id);

            if ($copy === null) {
                return new JSONResponse(
                    ['error' => 'Case type not found'],
                    Http::STATUS_NOT_FOUND
                );
            }

            return new JSONResponse($copy);
        } catch (\Throwable $e) {
            $this->logger->error('Case type copy failed: '.$e->getMessage());
            return new JSONResponse(
                ['error' => 'Copy failed: '.$e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }//end copy()

    /**
     * Delete a case type, but only when it is a draft.
     *
     * @param string $id The case type id to delete.
     *
     * @return JSONResponse
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @spec openspec/changes/zaaktype-copy/tasks.md#T07
     */
    #[AuthorizedAdminSetting(AdminSettings::class)]
    public function delete(string $id): JSONResponse
    {
        try {
            $result = $this->copyService->deleteDraft($id);

            if ($result['ok'] === true) {
                return new JSONResponse(['success' => true]);
            }

            $statusCode = match ($result['reason'] ?? 'error') {
                'not_found' => Http::STATUS_NOT_FOUND,
                'published' => Http::STATUS_CONFLICT,
                default => Http::STATUS_INTERNAL_SERVER_ERROR,
            };

            $message = match ($result['reason'] ?? 'error') {
                'not_found' => 'Case type not found',
                'published' => 'Cannot delete a published case type. Unpublish it first.',
                default => 'Delete failed',
            };

            return new JSONResponse(['error' => $message], $statusCode);
        } catch (\Throwable $e) {
            $this->logger->error('Case type delete failed: '.$e->getMessage());
            return new JSONResponse(
                ['error' => 'Delete failed: '.$e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end delete()
}//end class
