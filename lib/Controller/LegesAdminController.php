<?php

/**
 * Procest Leges Admin Controller
 *
 * Administrative API for leges tariff tables: import a verordening from a
 * decidesk raadsbesluit, list/inspect tariff tables, edit tariffs while in
 * concept, and approve a concept table to `vastgesteld`. All endpoints are
 * gated to app administrators (config-class operations, ADR-005).
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/leges-heffingen/specs.md#req-leges-001
 * @spec openspec/changes/leges-heffingen/specs.md#req-leges-009
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\LegesVerordeningService;
use OCA\Procest\Service\LegesVerordingImportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Administrative endpoints for leges verordeningen.
 *
 * @psalm-suppress UnusedClass
 */
class LegesAdminController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                    $request            The request.
     * @param LegesVerordingImportService $importService      Verordening import service.
     * @param LegesVerordeningService     $verordeningService Verordening read/approve service.
     * @param LoggerInterface             $logger             The logger.
     */
    public function __construct(
        IRequest $request,
        private readonly LegesVerordingImportService $importService,
        private readonly LegesVerordeningService $verordeningService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Import a verordening from a decidesk raadsbesluit (creates a concept table).
     *
     * @return JSONResponse
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function importVerordening(): JSONResponse
    {
        $metaData = $this->request->getParam('metaData', []);
        $rows     = $this->request->getParam('tarieven', []);
        $csv      = (string) $this->request->getParam('csv', '');

        if (is_string($metaData) === true) {
            $metaData = (json_decode($metaData, true) ?? []);
        }

        if (is_array($metaData) === false || $metaData === []) {
            return new JSONResponse(['error' => 'metaData is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            if ($csv !== '') {
                $rows = $this->importService->parseRawTable(bytes: $csv, format: 'csv');
            } else if (is_string($rows) === true) {
                $rows = (json_decode($rows, true) ?? []);
            }

            $result = $this->importService->createTariefTabelVersion(metaData: $metaData, rows: (array) $rows);
            return new JSONResponse($result, Http::STATUS_CREATED);
        } catch (\Throwable $e) {
            $this->logger->error('Procest leges import failed: '.$e->getMessage());
            return new JSONResponse(['error' => 'Import failed: '.$e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end importVerordening()

    /**
     * List all tariff tables (concept/vastgesteld/vervallen).
     *
     * @return JSONResponse
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function listVerordeningen(): JSONResponse
    {
        try {
            return new JSONResponse(['results' => $this->verordeningService->listTariefTabellen()]);
        } catch (\Throwable $e) {
            $this->logger->error('Procest leges list failed: '.$e->getMessage());
            return new JSONResponse(['error' => 'Could not list verordeningen'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end listVerordeningen()

    /**
     * Update a tariff amount while the table is in concept.
     *
     * @param string $id The tariff table UUID.
     *
     * @return JSONResponse
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function updateVerordening(string $id): JSONResponse
    {
        $tarieven = $this->request->getParam('tarieven', []);
        if (is_string($tarieven) === true) {
            $tarieven = (json_decode($tarieven, true) ?? []);
        }

        try {
            $result = $this->verordeningService->updateConceptTarieven(tariefTabelId: $id, tarieven: (array) $tarieven);
            return new JSONResponse($result);
        } catch (\Throwable $e) {
            $this->logger->error('Procest leges update failed: '.$e->getMessage());
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end updateVerordening()

    /**
     * Approve a concept tariff table (status concept -> vastgesteld).
     *
     * @param string $id The tariff table UUID.
     *
     * @return JSONResponse
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function approveVerordening(string $id): JSONResponse
    {
        try {
            $result = $this->verordeningService->approve(tariefTabelId: $id);
            return new JSONResponse($result);
        } catch (\Throwable $e) {
            $this->logger->error('Procest leges approve failed: '.$e->getMessage());
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end approveVerordening()
}//end class
