<?php

/**
 * Procest Complaint Analytics Controller
 *
 * Read-only reporting surface over complaint (klacht) data: frequency
 * breakdowns, monthly trend, average resolution time, employee threshold
 * alerts, and the management KPI summary.
 *
 * Split out of ComplaintController along the sub-domain seam — these endpoints
 * aggregate across all complaints rather than acting on one, depend only on
 * ComplaintAnalyticsService, and need no per-object authorization guard.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\Complaint\ComplaintAccessGuard;
use OCA\Procest\Service\ComplaintAnalyticsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Controller for complaint analytics and KPIs.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
 */
class ComplaintAnalyticsController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                    $appName          App name
     * @param IRequest                  $request          Request
     * @param ComplaintAnalyticsService $analyticsService Analytics service
     * @param ComplaintAccessGuard      $accessGuard      Shared complaint authorization guard
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ComplaintAnalyticsService $analyticsService,
        private readonly ComplaintAccessGuard $accessGuard,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Get complaint frequency analytics.
     *
     * @return JSONResponse Analytics data
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
     */
    public function analytics(): JSONResponse
    {
        if ($this->accessGuard->currentUid() === '') {
            return $this->accessGuard->notAuthenticated();
        }

        $dateFrom = $this->request->getParam('dateFrom') ?? date('Y-01-01');
        $dateTo   = $this->request->getParam('dateTo') ?? date('Y-m-d');

        $byCategorie    = $this->analyticsService->getFrequencyByDimension(
            dimension: 'categorie',
            dateFrom: $dateFrom,
            dateTo: $dateTo,
        );
        $byAfdeling     = $this->analyticsService->getFrequencyByDimension(
            dimension: 'betrokkenAfdeling',
            dateFrom: $dateFrom,
            dateTo: $dateTo,
        );
        $byKanaal       = $this->analyticsService->getFrequencyByDimension(
            dimension: 'ontvangstkanaal',
            dateFrom: $dateFrom,
            dateTo: $dateTo,
        );
        $monthlyTrend   = $this->analyticsService->getMonthlyTrend(dateFrom: $dateFrom, dateTo: $dateTo);
        $avgResolution  = $this->analyticsService->getAverageResolutionTime(dateFrom: $dateFrom, dateTo: $dateTo);
        $employeeAlerts = $this->analyticsService->checkEmployeeThresholdAlerts();

        return new JSONResponse(
                [
                    'byCategorie'    => $byCategorie,
                    'byAfdeling'     => $byAfdeling,
                    'byKanaal'       => $byKanaal,
                    'monthlyTrend'   => $monthlyTrend,
                    'avgResolution'  => $avgResolution,
                    'employeeAlerts' => $employeeAlerts,
                ]
                );
    }//end analytics()

    /**
     * Get KPI cards for management dashboard.
     *
     * @return JSONResponse KPI data
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
     */
    public function kpi(): JSONResponse
    {
        if ($this->accessGuard->currentUid() === '') {
            return $this->accessGuard->notAuthenticated();
        }

        $dateFrom = $this->request->getParam('dateFrom') ?? date('Y-m-01');
        $dateTo   = $this->request->getParam('dateTo') ?? date('Y-m-d');

        $kpi = $this->analyticsService->getKpiSummary($dateFrom, $dateTo);
        return new JSONResponse($kpi);
    }//end kpi()
}//end class
