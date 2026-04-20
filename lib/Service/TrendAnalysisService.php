<?php

/**
 * Procest Trend Analysis Service
 *
 * Service for analyzing historical doorlooptijd trends over time,
 * supporting multiple time granularities (weekly, monthly, quarterly).
 *
 * @category Service
 * @package  OCA\Procest\Service
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

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use Psr\Log\LoggerInterface;

/**
 * Service for trend analysis on doorlooptijd metrics.
 *
 * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-4
 */
class TrendAnalysisService
{
    /**
     * Constructor.
     *
     * @param LoggerInterface $logger Logger
     */
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get doorlooptijd trend for a case type.
     *
     * @param string $caseTypeId  The case type UUID
     * @param string $startDate   Start date (ISO 8601)
     * @param string $endDate     End date (ISO 8601)
     * @param string $granularity Time granularity: 'weekly', 'monthly', 'quarterly'
     *
     * @return array<string, mixed> Trend data
     *
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-4
     */
    public function getTrend(
        string $caseTypeId,
        string $startDate,
        string $endDate,
        string $granularity='weekly'
    ): array {
        $this->logger->debug(
            'Getting '.$granularity.' trend for case type: '.$caseTypeId
            .' from '.$startDate.' to '.$endDate
        );

        // Placeholder: would aggregate historical case data
        $trendData = [];

        switch ($granularity) {
            case 'monthly':
                $trendData = $this->getMonthlyTrend($caseTypeId, $startDate, $endDate);
                break;
            case 'quarterly':
                $trendData = $this->getQuarterlyTrend($caseTypeId, $startDate, $endDate);
                break;
            case 'weekly':
            default:
                $trendData = $this->getWeeklyTrend($caseTypeId, $startDate, $endDate);
                break;
        }

        // Calculate trend direction
        $direction = $this->determineTrendDirection($trendData);

        return [
            'caseTypeId'       => $caseTypeId,
            'period'           => [
                'start' => $startDate,
                'end'   => $endDate,
            ],
            'granularity'      => $granularity,
            'trend'            => $trendData,
            'direction'        => $direction,
            'changePercentage' => $this->calculateChangePercentage($trendData),
        ];
    }//end getTrend()

    /**
     * Get SLA adherence trend over time.
     *
     * @param string $caseTypeId  The case type UUID
     * @param string $startDate   Start date (ISO 8601)
     * @param string $endDate     End date (ISO 8601)
     * @param string $granularity Time granularity
     *
     * @return array<string, mixed> SLA adherence trend
     *
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-4
     */
    public function getSLATrend(
        string $caseTypeId,
        string $startDate,
        string $endDate,
        string $granularity='weekly'
    ): array {
        $this->logger->debug(
            'Getting SLA trend for case type: '.$caseTypeId
        );

        // Placeholder implementation
        $trendData = [
            ['period' => '2024-01-01', 'slaAdherence' => 92.5, 'cases' => 20],
            ['period' => '2024-01-08', 'slaAdherence' => 91.2, 'cases' => 21],
            ['period' => '2024-01-15', 'slaAdherence' => 88.7, 'cases' => 23],
            ['period' => '2024-01-22', 'slaAdherence' => 86.4, 'cases' => 19],
            ['period' => '2024-01-29', 'slaAdherence' => 89.3, 'cases' => 22],
        ];

        return [
            'caseTypeId'       => $caseTypeId,
            'period'           => [
                'start' => $startDate,
                'end'   => $endDate,
            ],
            'granularity'      => $granularity,
            'trend'            => $trendData,
            'averageAdherence' => 89.62,
            'direction'        => 'declining',
        ];
    }//end getSLATrend()

    /**
     * Get comparison trend between two case types.
     *
     * @param string $caseTypeId1 First case type UUID
     * @param string $caseTypeId2 Second case type UUID
     * @param string $startDate   Start date (ISO 8601)
     * @param string $endDate     End date (ISO 8601)
     *
     * @return array<string, mixed> Comparison trend data
     *
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-4
     */
    public function getComparisonTrend(
        string $caseTypeId1,
        string $caseTypeId2,
        string $startDate,
        string $endDate
    ): array {
        $this->logger->debug(
            'Getting comparison trend between '.$caseTypeId1.' and '.$caseTypeId2
        );

        // Placeholder: would fetch individual trends and compare
        return [
            'caseType1'  => $caseTypeId1,
            'caseType2'  => $caseTypeId2,
            'period'     => [
                'start' => $startDate,
                'end'   => $endDate,
            ],
            'comparison' => [
                'type1' => [
                    ['period' => '2024-01-01', 'avgDuration' => 25.3],
                    ['period' => '2024-01-08', 'avgDuration' => 24.8],
                ],
                'type2' => [
                    ['period' => '2024-01-01', 'avgDuration' => 18.2],
                    ['period' => '2024-01-08', 'avgDuration' => 19.1],
                ],
            ],
            'difference' => 'Type 1 is 30% slower',
        ];
    }//end getComparisonTrend()

    /**
     * Get weekly trend data.
     *
     * @param string $caseTypeId Case type UUID
     * @param string $startDate  Start date
     * @param string $endDate    End date
     *
     * @return array<int, array<string, mixed>> Weekly trend points
     */
    private function getWeeklyTrend(
        string $caseTypeId,
        string $startDate,
        string $endDate
    ): array {
        return [
            ['week' => '2024-01-01', 'avgDuration' => 26.2, 'cases' => 18, 'slaAdherence' => 88.9],
            ['week' => '2024-01-08', 'avgDuration' => 25.8, 'cases' => 19, 'slaAdherence' => 89.5],
            ['week' => '2024-01-15', 'avgDuration' => 27.4, 'cases' => 20, 'slaAdherence' => 85.0],
            ['week' => '2024-01-22', 'avgDuration' => 28.1, 'cases' => 17, 'slaAdherence' => 82.4],
            ['week' => '2024-01-29', 'avgDuration' => 26.9, 'cases' => 21, 'slaAdherence' => 85.7],
        ];
    }//end getWeeklyTrend()

    /**
     * Get monthly trend data.
     *
     * @param string $caseTypeId Case type UUID
     * @param string $startDate  Start date
     * @param string $endDate    End date
     *
     * @return array<int, array<string, mixed>> Monthly trend points
     */
    private function getMonthlyTrend(
        string $caseTypeId,
        string $startDate,
        string $endDate
    ): array {
        return [
            ['month' => '2023-11', 'avgDuration' => 28.5, 'cases' => 65, 'slaAdherence' => 84.6],
            ['month' => '2023-12', 'avgDuration' => 26.7, 'cases' => 71, 'slaAdherence' => 87.3],
            ['month' => '2024-01', 'avgDuration' => 27.1, 'cases' => 75, 'slaAdherence' => 85.9],
            ['month' => '2024-02', 'avgDuration' => 25.3, 'cases' => 68, 'slaAdherence' => 89.7],
        ];
    }//end getMonthlyTrend()

    /**
     * Get quarterly trend data.
     *
     * @param string $caseTypeId Case type UUID
     * @param string $startDate  Start date
     * @param string $endDate    End date
     *
     * @return array<int, array<string, mixed>> Quarterly trend points
     */
    private function getQuarterlyTrend(
        string $caseTypeId,
        string $startDate,
        string $endDate
    ): array {
        return [
            ['quarter' => '2023-Q3', 'avgDuration' => 29.8, 'cases' => 185, 'slaAdherence' => 82.3],
            ['quarter' => '2023-Q4', 'avgDuration' => 27.6, 'cases' => 192, 'slaAdherence' => 85.9],
            ['quarter' => '2024-Q1', 'avgDuration' => 26.4, 'cases' => 214, 'slaAdherence' => 87.8],
        ];
    }//end getQuarterlyTrend()

    /**
     * Determine overall trend direction.
     *
     * @param array<int, array<string, mixed>> $trendData The trend data
     *
     * @return string The direction: 'improving', 'declining', or 'stable'
     */
    private function determineTrendDirection(array $trendData): string
    {
        if (count($trendData) < 2) {
            return 'stable';
        }

        $first = $trendData[0]['avgDuration'] ?? 0;
        $last  = $trendData[count($trendData) - 1]['avgDuration'] ?? 0;

        if ($last < $first * 0.95) {
            return 'improving';
        } else if ($last > $first * 1.05) {
            return 'declining';
        }

        return 'stable';
    }//end determineTrendDirection()

    /**
     * Calculate percentage change over the trend period.
     *
     * @param array<int, array<string, mixed>> $trendData The trend data
     *
     * @return float The percentage change
     */
    private function calculateChangePercentage(array $trendData): float
    {
        if (count($trendData) < 2) {
            return 0.0;
        }

        $first = $trendData[0]['avgDuration'] ?? 0;
        $last  = $trendData[count($trendData) - 1]['avgDuration'] ?? 0;

        if ($first === 0) {
            return 0.0;
        }

        return round((($last - $first) / $first) * 100, 2);
    }//end calculateChangePercentage()
}//end class
