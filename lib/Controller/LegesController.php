<?php

/**
 * Procest Leges Controller
 *
 * Handles API endpoints for municipal fee (leges) calculation,
 * history retrieval, and financial system export.
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
 * @spec openspec/changes/retrofit-2026-05-24-leges-fees/tasks.md#task-1
 * @spec openspec/changes/retrofit-2026-05-24-leges-fees/tasks.md#task-3
 * @spec openspec/changes/retrofit-2026-05-24-leges-fees/tasks.md#task-4
 * @spec openspec/changes/retrofit-2026-05-24-leges-fees/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\LegesCalculationService;
use OCA\Procest\Service\LegesExportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for leges calculation and export operations.
 *
 * @psalm-suppress UnusedClass
 */
class LegesController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                  $appName            The app name.
     * @param IRequest                $request            The request object.
     * @param LegesCalculationService $calculationService The calculation service.
     * @param LegesExportService      $exportService      The export service.
     * @param IUserSession            $userSession        The user session.
     * @param LoggerInterface         $logger             The logger.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly LegesCalculationService $calculationService,
        private readonly LegesExportService $exportService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Calculate leges for a case.
     *
     * @return JSONResponse
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    /** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
    public function calculate(): JSONResponse
    {
        try {
            $caseData    = $this->request->getParam('caseData', []);
            $verordening = $this->request->getParam('verordening', []);

            if (empty($caseData) === true || empty($verordening) === true) {
                return new JSONResponse(
                    ['error' => 'Parameters caseData and verordening are required'],
                    Http::STATUS_BAD_REQUEST
                );
            }

            if (is_string($caseData) === true) {
                $caseData = json_decode($caseData, true) ?? [];
            }

            if (is_string($verordening) === true) {
                $verordening = json_decode($verordening, true) ?? [];
            }

            $userId = $this->userSession->getUser()?->getUID() ?? 'system';

            $result = $this->calculationService->calculate($caseData, $verordening, $userId);

            return new JSONResponse($result);
        } catch (\Throwable $e) {
            $this->logger->error('Leges calculation failed: '.$e->getMessage());
            return new JSONResponse(
                ['error' => 'Calculation failed: '.$e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end calculate()

    /**
     * Recalculate leges with corrected data.
     *
     * @return JSONResponse
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    /** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
    public function recalculate(): JSONResponse
    {
        try {
            $caseData     = $this->request->getParam('caseData', []);
            $verordening  = $this->request->getParam('verordening', []);
            $previousCalc = $this->request->getParam('previousCalculation', []);
            $reason       = $this->request->getParam('correctionReason', '');

            if (is_string($caseData) === true) {
                $caseData = json_decode($caseData, true) ?? [];
            }

            if (is_string($verordening) === true) {
                $verordening = json_decode($verordening, true) ?? [];
            }

            if (is_string($previousCalc) === true) {
                $previousCalc = json_decode($previousCalc, true) ?? [];
            }

            $userId = $this->userSession->getUser()?->getUID() ?? 'system';

            $result = $this->calculationService->recalculate(
                $caseData,
                $verordening,
                $previousCalc,
                $userId,
                $reason
            );

            return new JSONResponse($result);
        } catch (\Throwable $e) {
            $this->logger->error('Leges recalculation failed: '.$e->getMessage());
            return new JSONResponse(
                ['error' => 'Recalculation failed: '.$e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end recalculate()

    /**
     * Calculate verrekening (deduction).
     *
     * @return JSONResponse
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    /** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
    public function verrekening(): JSONResponse
    {
        try {
            $currentAmount  = (float) $this->request->getParam('currentAmount', 0);
            $previousAmount = (float) $this->request->getParam('previousAmount', 0);

            $result = $this->calculationService->calculateVerrekening($currentAmount, $previousAmount);

            return new JSONResponse($result);
        } catch (\Throwable $e) {
            $this->logger->error('Verrekening calculation failed: '.$e->getMessage());
            return new JSONResponse(
                ['error' => 'Verrekening failed: '.$e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }//end verrekening()

    /**
     * Calculate teruggaaf (refund).
     *
     * @return JSONResponse
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    /** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
    public function teruggaaf(): JSONResponse
    {
        try {
            $imposedAmount  = (float) $this->request->getParam('imposedAmount', 0);
            $refundFraction = (float) $this->request->getParam('refundFraction', 1.0);
            $reason         = (string) $this->request->getParam('reason', '');

            $result = $this->calculationService->calculateTeruggaaf(
                $imposedAmount,
                $refundFraction,
                $reason
            );

            return new JSONResponse($result);
        } catch (\Throwable $e) {
            $this->logger->error('Teruggaaf calculation failed: '.$e->getMessage());
            return new JSONResponse(
                ['error' => 'Teruggaaf failed: '.$e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }//end teruggaaf()

    /**
     * Export berekeningen to financial system format.
     *
     * @return DataDownloadResponse|JSONResponse
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    /** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
    public function export(): DataDownloadResponse|JSONResponse
    {
        try {
            $berekeningen = $this->request->getParam('berekeningen', []);
            $format       = $this->request->getParam('format', LegesExportService::FORMAT_CSV);

            if (is_string($berekeningen) === true) {
                $berekeningen = json_decode($berekeningen, true) ?? [];
            }

            if (empty($berekeningen) === true) {
                return new JSONResponse(
                    ['error' => 'No berekeningen provided for export'],
                    Http::STATUS_BAD_REQUEST
                );
            }

            $result = $this->exportService->export($berekeningen, $format);

            return new DataDownloadResponse(
                $result['content'],
                $result['filename'],
                $result['contentType']
            );
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_BAD_REQUEST
            );
        } catch (\Throwable $e) {
            $this->logger->error('Leges export failed: '.$e->getMessage());
            return new JSONResponse(
                ['error' => 'Export failed: '.$e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end export()
}//end class
