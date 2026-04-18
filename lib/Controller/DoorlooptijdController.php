<?php

/**
 * Procest Doorlooptijd Controller
 *
 * REST API controller for doorlooptijd tracking and analytics.
 * Provides endpoints for SLA adherence, bottleneck analysis, and trends.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\BottleneckAnalysisService;
use OCA\Procest\Service\DoorlooptijdService;
use OCA\Procest\Service\TrendAnalysisService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for doorlooptijd analytics endpoints.
 *
 * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-6
 */
class DoorlooptijdController extends Controller
{

    /**
     * Constructor.
     *
     * @param string                      $appName                  The app name
     * @param IRequest                    $request                  The request
     * @param DoorlooptijdService         $doorlooptijdService      Doorlooptijd service
     * @param BottleneckAnalysisService   $bottleneckAnalysisService Bottleneck service
     * @param TrendAnalysisService        $trendAnalysisService     Trend service
     * @param LoggerInterface             $logger                   Logger
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly DoorlooptijdService $doorlooptijdService,
        private readonly BottleneckAnalysisService $bottleneckAnalysisService,
        private readonly TrendAnalysisService $trendAnalysisService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }


    /**
     * Get doorlooptijd statistics for a case type.
     *
     * @param string $caseTypeId The case type UUID
     * @param string $startDate  Start date (ISO 8601) - default 90 days ago
     * @param string $endDate    End date (ISO 8601) - default today
     *
     * @return JSONResponse Statistics data
     *
     * @NoAdminRequired
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-6
     */
    public function statistics(
        string $caseTypeId,
        string $startDate = '',
        string $endDate = ''
    ): JSONResponse {
        try {
            if (empty($startDate)) {
                $startDate = date('Y-m-d', strtotime('-90 days'));
            }
            if (empty($endDate)) {
                $endDate = date('Y-m-d');
            }

            $stats = $this->doorlooptijdService->getCaseTypeStatistics(
                $caseTypeId,
                $startDate,
                $endDate
            );

            return new JSONResponse($stats);
        } catch (\Exception $e) {
            $this->logger->error('Error getting statistics: ' . $e->getMessage());
            return new JSONResponse(
                ['error' => $e->getMessage()],
                400
            );
        }
    }


    /**
     * Get process step bottleneck analysis.
     *
     * @param string $caseTypeId The case type UUID
     * @param string $startDate  Start date (ISO 8601)
     * @param string $endDate    End date (ISO 8601)
     *
     * @return JSONResponse Bottleneck analysis data
     *
     * @NoAdminRequired
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-6
     */
    public function bottlenecks(
        string $caseTypeId,
        string $startDate = '',
        string $endDate = ''
    ): JSONResponse {
        try {
            if (empty($startDate)) {
                $startDate = date('Y-m-d', strtotime('-90 days'));
            }
            if (empty($endDate)) {
                $endDate = date('Y-m-d');
            }

            $analysis = $this->bottleneckAnalysisService->analyzeBottlenecks(
                $caseTypeId,
                $startDate,
                $endDate
            );

            return new JSONResponse($analysis);
        } catch (\Exception $e) {
            $this->logger->error('Error analyzing bottlenecks: ' . $e->getMessage());
            return new JSONResponse(
                ['error' => $e->getMessage()],
                400
            );
        }
    }


    /**
     * Get historical trend analysis.
     *
     * @param string $caseTypeId  The case type UUID
     * @param string $startDate   Start date (ISO 8601)
     * @param string $endDate     End date (ISO 8601)
     * @param string $granularity Time granularity: weekly, monthly, quarterly
     *
     * @return JSONResponse Trend analysis data
     *
     * @NoAdminRequired
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-6
     */
    public function trends(
        string $caseTypeId,
        string $startDate = '',
        string $endDate = '',
        string $granularity = 'weekly'
    ): JSONResponse {
        try {
            if (empty($startDate)) {
                $startDate = date('Y-m-d', strtotime('-180 days'));
            }
            if (empty($endDate)) {
                $endDate = date('Y-m-d');
            }

            // Validate granularity
            if (!in_array($granularity, ['weekly', 'monthly', 'quarterly'])) {
                $granularity = 'weekly';
            }

            $trend = $this->trendAnalysisService->getTrend(
                $caseTypeId,
                $startDate,
                $endDate,
                $granularity
            );

            return new JSONResponse($trend);
        } catch (\Exception $e) {
            $this->logger->error('Error getting trends: ' . $e->getMessage());
            return new JSONResponse(
                ['error' => $e->getMessage()],
                400
            );
        }
    }


    /**
     * Get SLA trend over time.
     *
     * @param string $caseTypeId  The case type UUID
     * @param string $startDate   Start date (ISO 8601)
     * @param string $endDate     End date (ISO 8601)
     * @param string $granularity Time granularity
     *
     * @return JSONResponse SLA trend data
     *
     * @NoAdminRequired
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-6
     */
    public function slaTrend(
        string $caseTypeId,
        string $startDate = '',
        string $endDate = '',
        string $granularity = 'weekly'
    ): JSONResponse {
        try {
            if (empty($startDate)) {
                $startDate = date('Y-m-d', strtotime('-180 days'));
            }
            if (empty($endDate)) {
                $endDate = date('Y-m-d');
            }

            $trend = $this->trendAnalysisService->getSLATrend(
                $caseTypeId,
                $startDate,
                $endDate,
                $granularity
            );

            return new JSONResponse($trend);
        } catch (\Exception $e) {
            $this->logger->error('Error getting SLA trend: ' . $e->getMessage());
            return new JSONResponse(
                ['error' => $e->getMessage()],
                400
            );
        }
    }
}
