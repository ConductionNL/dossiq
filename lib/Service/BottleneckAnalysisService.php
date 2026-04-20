<?php

/**
 * Procest Bottleneck Analysis Service
 *
 * Service for identifying process step bottlenecks by analyzing
 * average step durations across cases.
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
 * Service for bottleneck analysis in process steps.
 *
 * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-3
 */
class BottleneckAnalysisService
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
     * Analyze bottlenecks for a case type.
     *
     * Returns process steps ranked by average duration.
     *
     * @param string $caseTypeId The case type UUID
     * @param string $startDate  Start date (ISO 8601)
     * @param string $endDate    End date (ISO 8601)
     *
     * @return array<string, mixed> Bottleneck analysis data
     *
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-3
     */
    public function analyzeBottlenecks(
        string $caseTypeId,
        string $startDate,
        string $endDate
    ): array {
        $this->logger->debug(
            'Analyzing bottlenecks for case type: '.$caseTypeId
            .' from '.$startDate.' to '.$endDate
        );

        // Placeholder implementation - would aggregate from workflow/case data
        $steps = [
            [
                'id'                => 'step-1',
                'name'              => 'Intake & Initial Assessment',
                'avgDuration'       => 8.5,
                'caseCount'         => 25,
                'totalDuration'     => 212.5,
                'rank'              => 1,
                'percentageOfTotal' => 15.2,
            ],
            [
                'id'                => 'step-2',
                'name'              => 'Documentation & File Preparation',
                'avgDuration'       => 22.3,
                'caseCount'         => 25,
                'totalDuration'     => 557.5,
                'rank'              => 2,
                'percentageOfTotal' => 39.8,
            ],
            [
                'id'                => 'step-3',
                'name'              => 'Review & Decision',
                'avgDuration'       => 12.1,
                'caseCount'         => 24,
                'totalDuration'     => 290.4,
                'rank'              => 3,
                'percentageOfTotal' => 20.7,
            ],
            [
                'id'                => 'step-4',
                'name'              => 'Appeal Handling',
                'avgDuration'       => 18.9,
                'caseCount'         => 8,
                'totalDuration'     => 151.2,
                'rank'              => 4,
                'percentageOfTotal' => 10.8,
            ],
            [
                'id'                => 'step-5',
                'name'              => 'Closure & Archive',
                'avgDuration'       => 3.2,
                'caseCount'         => 25,
                'totalDuration'     => 80.0,
                'rank'              => 5,
                'percentageOfTotal' => 5.7,
            ],
        ];

        // Sort by duration descending
        usort(
                $steps,
                function ($a, $b) {
                    return $b['avgDuration'] <=> $a['avgDuration'];
                }
                );

        return [
            'caseTypeId'        => $caseTypeId,
            'period'            => [
                'start' => $startDate,
                'end'   => $endDate,
            ],
            'steps'             => $steps,
            'criticalThreshold' => 20.0,
            // Days - steps above this are critical
            'criticalSteps'     => array_filter(
                $steps,
                fn($step) => $step['avgDuration'] > 20.0
            ),
        ];
    }//end analyzeBottlenecks()

    /**
     * Get bottleneck trend for a specific step.
     *
     * @param string $caseTypeId    The case type UUID
     * @param string $processStepId The process step UUID
     * @param string $startDate     Start date (ISO 8601)
     * @param string $endDate       End date (ISO 8601)
     *
     * @return array<string, mixed> Trend data for the step
     *
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-3
     */
    public function getStepTrend(
        string $caseTypeId,
        string $processStepId,
        string $startDate,
        string $endDate
    ): array {
        $this->logger->debug(
            'Getting trend for step: '.$processStepId
            .' in case type: '.$caseTypeId
        );

        // Placeholder: would calculate weekly/monthly trends
        return [
            'caseTypeId'       => $caseTypeId,
            'stepId'           => $processStepId,
            'period'           => [
                'start' => $startDate,
                'end'   => $endDate,
            ],
            'trend'            => [
                ['week' => '2024-01-01', 'avgDuration' => 15.2, 'cases' => 5],
                ['week' => '2024-01-08', 'avgDuration' => 16.8, 'cases' => 6],
                ['week' => '2024-01-15', 'avgDuration' => 18.5, 'cases' => 7],
                ['week' => '2024-01-22', 'avgDuration' => 21.2, 'cases' => 5],
                ['week' => '2024-01-29', 'avgDuration' => 19.8, 'cases' => 6],
            ],
            'changePercentage' => 30.8,
            // % change from start to end
            'direction'        => 'increasing',
            // increasing, stable, or decreasing
        ];
    }//end getStepTrend()

    /**
     * Get top bottlenecks across all case types.
     *
     * @param string $startDate Start date (ISO 8601)
     * @param string $endDate   End date (ISO 8601)
     * @param int    $limit     Maximum number of results
     *
     * @return array<string, mixed> Top bottleneck steps
     *
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-3
     */
    public function getTopBottlenecks(
        string $startDate,
        string $endDate,
        int $limit=10
    ): array {
        $this->logger->debug(
            'Getting top bottlenecks from '.$startDate.' to '.$endDate
        );

        // Placeholder: would aggregate across all case types
        return [
            'period'   => [
                'start' => $startDate,
                'end'   => $endDate,
            ],
            'topSteps' => [
                [
                    'stepName'      => 'Documentation & File Preparation',
                    'avgDuration'   => 22.3,
                    'caseTypeCount' => 12,
                    'affectedCases' => 287,
                ],
                [
                    'stepName'      => 'Review & Decision',
                    'avgDuration'   => 18.9,
                    'caseTypeCount' => 15,
                    'affectedCases' => 452,
                ],
                [
                    'stepName'      => 'Appeal Handling',
                    'avgDuration'   => 16.5,
                    'caseTypeCount' => 8,
                    'affectedCases' => 94,
                ],
            ],
            'limit'    => $limit,
        ];
    }//end getTopBottlenecks()
}//end class
