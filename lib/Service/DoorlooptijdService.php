<?php

/**
 * Procest Doorlooptijd Service
 *
 * Service for calculating and analyzing case processing times (doorlooptijd)
 * and SLA compliance metrics. Provides metrics for dashboard analytics including
 * SLA compliance rates, processing time distribution, trends, and at-risk cases.
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
 *
 * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-DL-01
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTime;
use Psr\Log\LoggerInterface;

/**
 * Service for doorlooptijd (processing time) analytics and SLA compliance calculations.
 *
 * Computes metrics from existing case data (startDate, endDate, caseType, status)
 * without requiring schema changes. All calculations based on dates and configured
 * SLA targets (processingDeadline on caseType).
 *
 * @psalm-suppress UnusedClass
 */
class DoorlooptijdService
{
    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Settings service for OpenRegister access
     * @param LoggerInterface $logger          Logger
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }


    /**
     * Get all metrics for the doorlooptijd dashboard.
     *
     * @param string      $from       ISO 8601 date (start of date range)
     * @param string      $to         ISO 8601 date (end of date range)
     * @param string|null $caseTypeId Optional case type filter
     *
     * @return array<string, mixed> Metrics object with slaCompliance, distribution, etc.
     *
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-DL-01
     */
    public function getMetrics(
        string $from,
        string $to,
        ?string $caseTypeId = null
    ): array {
        try {
            $objectService = $this->settingsService->getObjectService();
            if ($objectService === null) {
                $this->logger->error('DoorlooptijdService: OpenRegister unavailable');
                return $this->getEmptyMetrics();
            }

            // Get configuration
            $register = $this->settingsService->getConfigValue('register');
            $caseSchema = $this->settingsService->getConfigValue('case_schema');
            $caseTypeSchema = $this->settingsService->getConfigValue('case_type_schema');

            if (empty($register) || empty($caseSchema) || empty($caseTypeSchema)) {
                $this->logger->error('DoorlooptijdService: Schemas not configured');
                return $this->getEmptyMetrics();
            }

            // Fetch case types
            $caseTypes = $this->getCaseTypes($objectService, $register, $caseTypeSchema);

            // Fetch completed cases in date range
            $completedCases = $this->getCompletedCases(
                $objectService,
                $register,
                $caseSchema,
                $from,
                $to,
                $caseTypeId
            );

            // Fetch open cases (for at-risk analysis)
            $openCases = $this->getOpenCases(
                $objectService,
                $register,
                $caseSchema,
                $caseTypeId
            );

            // Calculate metrics
            $slaCompliance = $this->computeSlaCompliance($completedCases, $caseTypes);
            $distribution = $this->computeProcessingTimeDistribution($completedCases, $caseTypes);
            $monthlyTrend = $this->computeMonthlyTrend($completedCases, $caseTypes);
            $atRiskCases = $this->getAtRiskCases($openCases, $caseTypes);
            $performanceTable = $this->computePerformanceTable($completedCases, $caseTypes);

            return [
                'slaCompliance' => $slaCompliance,
                'distribution' => $distribution,
                'monthlyTrend' => $monthlyTrend,
                'atRiskCases' => $atRiskCases,
                'performanceTable' => $performanceTable,
            ];
        } catch (\Exception $e) {
            $this->logger->error('DoorlooptijdService error: ' . $e->getMessage());
            return $this->getEmptyMetrics();
        }
    }//end getMetrics()


    /**
     * Get all case types from OpenRegister.
     *
     * @param object $objectService OpenRegister ObjectService
     * @param string $register      Register name
     * @param string $schema        Case type schema
     *
     * @return array<int, array<string, mixed>> Case types
     *
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-DL-01
     */
    private function getCaseTypes(object $objectService, string $register, string $schema): array
    {
        try {
            $result = $objectService->findObjects(
                $register,
                $schema,
                [],
                [],
                999,  // limit
                0     // offset
            );
            return is_array($result) ? $result : [];
        } catch (\Exception $e) {
            $this->logger->warning('Failed to fetch case types: ' . $e->getMessage());
            return [];
        }
    }//end getCaseTypes()


    /**
     * Get completed cases in date range.
     *
     * @param object      $objectService OpenRegister ObjectService
     * @param string      $register      Register name
     * @param string      $schema        Case schema
     * @param string      $from          Start date (ISO 8601)
     * @param string      $to            End date (ISO 8601)
     * @param string|null $caseTypeId    Optional case type filter
     *
     * @return array<int, array<string, mixed>> Completed cases
     *
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-DL-01
     */
    private function getCompletedCases(
        object $objectService,
        string $register,
        string $schema,
        string $from,
        string $to,
        ?string $caseTypeId = null
    ): array {
        try {
            $filters = [];

            // Filter by case type if provided
            if ($caseTypeId !== null) {
                $filters['caseType'] = $caseTypeId;
            }

            // Fetch cases with endDate in range
            $result = $objectService->findObjects(
                $register,
                $schema,
                $filters,
                [],
                999,  // limit
                0     // offset
            );

            $cases = is_array($result) ? $result : [];

            // Filter by date range on client side
            // (OpenRegister may not support complex date queries)
            return array_filter($cases, function (array $case) use ($from, $to) {
                $endDate = $case['endDate'] ?? null;
                if (empty($endDate)) {
                    return false;
                }
                return $endDate >= $from && $endDate <= $to;
            });
        } catch (\Exception $e) {
            $this->logger->warning('Failed to fetch completed cases: ' . $e->getMessage());
            return [];
        }
    }//end getCompletedCases()


    /**
     * Get open (non-completed) cases.
     *
     * @param object      $objectService OpenRegister ObjectService
     * @param string      $register      Register name
     * @param string      $schema        Case schema
     * @param string|null $caseTypeId    Optional case type filter
     *
     * @return array<int, array<string, mixed>> Open cases
     *
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-DL-01
     */
    private function getOpenCases(
        object $objectService,
        string $register,
        string $schema,
        ?string $caseTypeId = null
    ): array {
        try {
            $filters = [];

            // Filter by case type if provided
            if ($caseTypeId !== null) {
                $filters['caseType'] = $caseTypeId;
            }

            $result = $objectService->findObjects(
                $register,
                $schema,
                $filters,
                [],
                999,  // limit
                0     // offset
            );

            $cases = is_array($result) ? $result : [];

            // Filter: cases without endDate or with non-final status
            return array_filter($cases, function (array $case) {
                $endDate = $case['endDate'] ?? null;
                return empty($endDate);
            });
        } catch (\Exception $e) {
            $this->logger->warning('Failed to fetch open cases: ' . $e->getMessage());
            return [];
        }
    }//end getOpenCases()


    /**
     * Compute SLA compliance metrics.
     *
     * @param array<int, array<string, mixed>> $completedCases Completed cases
     * @param array<int, array<string, mixed>> $caseTypes      Case types
     *
     * @return array<string, mixed> SLA compliance data
     *
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-DL-01
     */
    private function computeSlaCompliance(array $completedCases, array $caseTypes): array
    {
        $caseTypeMap = $this->buildCaseTypeMap($caseTypes);
        $byType = [];
        $withinSla = 0;
        $total = 0;
        $excluded = 0;

        foreach ($completedCases as $case) {
            $targetDays = $this->getSlaTargetDays($case, $caseTypeMap);
            $actualDays = $this->getProcessingDays($case);

            if ($targetDays === null || $actualDays === null) {
                $excluded++;
                continue;
            }

            $total++;
            $isWithin = $actualDays <= $targetDays;
            if ($isWithin) {
                $withinSla++;
            }

            $ctId = $case['caseType'] ?? 'unknown';
            if (!isset($byType[$ctId])) {
                $ct = $caseTypeMap[$ctId] ?? [];
                $byType[$ctId] = [
                    'id' => $ctId,
                    'name' => $ct['title'] ?? $ct['name'] ?? 'Unknown',
                    'total' => 0,
                    'withinSla' => 0,
                    'totalDays' => 0,
                    'targetDays' => $targetDays,
                ];
            }

            $byType[$ctId]['total']++;
            $byType[$ctId]['totalDays'] += $actualDays;
            if ($isWithin) {
                $byType[$ctId]['withinSla']++;
            }
        }

        $breakdown = [];
        foreach ($byType as $entry) {
            $breakdown[] = [
                'id' => $entry['id'],
                'name' => $entry['name'],
                'total' => $entry['total'],
                'withinSla' => $entry['withinSla'],
                'rate' => $entry['total'] > 0 ? round(($entry['withinSla'] / $entry['total']) * 100) : null,
                'avgActual' => $entry['total'] > 0 ? round($entry['totalDays'] / $entry['total']) : null,
                'targetDays' => $entry['targetDays'],
            ];
        }

        return [
            'overallRate' => $total > 0 ? round(($withinSla / $total) * 100) : null,
            'withinSla' => $withinSla,
            'total' => $total,
            'excluded' => $excluded,
            'byType' => $breakdown,
        ];
    }//end computeSlaCompliance()


    /**
     * Compute processing time distribution (histogram bins).
     *
     * @param array<int, array<string, mixed>> $completedCases Completed cases
     * @param array<int, array<string, mixed>> $caseTypes      Case types
     *
     * @return array<string, mixed> Distribution bins and SLA target
     *
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-DL-01
     */
    private function computeProcessingTimeDistribution(array $completedCases, array $caseTypes): array
    {
        $caseTypeMap = $this->buildCaseTypeMap($caseTypes);

        $bins = [
            ['label' => '0-7', 'min' => 0, 'max' => 7, 'count' => 0],
            ['label' => '8-14', 'min' => 8, 'max' => 14, 'count' => 0],
            ['label' => '15-21', 'min' => 15, 'max' => 21, 'count' => 0],
            ['label' => '22-28', 'min' => 22, 'max' => 28, 'count' => 0],
            ['label' => '29-42', 'min' => 29, 'max' => 42, 'count' => 0],
            ['label' => '43-56', 'min' => 43, 'max' => 56, 'count' => 0],
            ['label' => '57+', 'min' => 57, 'max' => 999999, 'count' => 0],
        ];

        $slaTargetDays = null;
        $targetSet = [];

        foreach ($completedCases as $case) {
            $days = $this->getProcessingDays($case);
            if ($days === null) {
                continue;
            }

            $target = $this->getSlaTargetDays($case, $caseTypeMap);
            if ($target !== null) {
                $targetSet[$target] = true;
            }

            foreach ($bins as &$bin) {
                if ($days >= $bin['min'] && $days <= $bin['max']) {
                    $bin['count']++;
                    break;
                }
            }
        }

        // Show SLA target only when single case type
        if (count($targetSet) === 1) {
            $slaTargetDays = key($targetSet);
        }

        $result = [];
        foreach ($bins as $bin) {
            $result[] = [
                'label' => $bin['label'],
                'count' => $bin['count'],
            ];
        }

        return [
            'bins' => $result,
            'slaTargetDays' => $slaTargetDays,
        ];
    }//end computeProcessingTimeDistribution()


    /**
     * Compute monthly SLA compliance trend.
     *
     * @param array<int, array<string, mixed>> $completedCases Completed cases
     * @param array<int, array<string, mixed>> $caseTypes      Case types
     *
     * @return array<int, array<string, mixed>> Monthly trend data
     *
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-DL-01
     */
    private function computeMonthlyTrend(array $completedCases, array $caseTypes): array
    {
        $caseTypeMap = $this->buildCaseTypeMap($caseTypes);
        $now = new DateTime();

        // Build 12-month buckets
        $buckets = [];
        for ($i = 11; $i >= 0; $i--) {
            $d = new DateTime();
            $d->modify("-$i months");
            $d->setDate((int)$d->format('Y'), (int)$d->format('m'), 1);
            $key = $d->format('Y-m');
            $buckets[$key] = [
                'month' => $key,
                'withinSla' => 0,
                'total' => 0,
            ];
        }

        foreach ($completedCases as $case) {
            $endDate = $case['endDate'] ?? null;
            if (empty($endDate)) {
                continue;
            }

            $month = substr($endDate, 0, 7);
            if (!isset($buckets[$month])) {
                continue;
            }

            $targetDays = $this->getSlaTargetDays($case, $caseTypeMap);
            $actualDays = $this->getProcessingDays($case);
            if ($targetDays === null || $actualDays === null) {
                continue;
            }

            $buckets[$month]['total']++;
            if ($actualDays <= $targetDays) {
                $buckets[$month]['withinSla']++;
            }
        }

        $result = [];
        foreach ($buckets as $bucket) {
            $result[] = [
                'month' => $bucket['month'],
                'rate' => $bucket['total'] > 0 ? round(($bucket['withinSla'] / $bucket['total']) * 100) : null,
                'withinSla' => $bucket['withinSla'],
                'total' => $bucket['total'],
            ];
        }

        return $result;
    }//end computeMonthlyTrend()


    /**
     * Get at-risk open cases.
     *
     * @param array<int, array<string, mixed>> $openCases Open cases
     * @param array<int, array<string, mixed>> $caseTypes Case types
     * @param float                             $threshold Threshold as fraction (default 0.25)
     *
     * @return array<int, array<string, mixed>> At-risk cases
     *
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-DL-01
     */
    private function getAtRiskCases(
        array $openCases,
        array $caseTypes,
        float $threshold = 0.25
    ): array {
        $caseTypeMap = $this->buildCaseTypeMap($caseTypes);
        $today = new DateTime();
        $today->setTime(0, 0, 0);
        $results = [];

        foreach ($openCases as $case) {
            $targetDays = $this->getSlaTargetDays($case, $caseTypeMap);
            if ($targetDays === null) {
                continue;
            }

            $startDate = $case['startDate'] ?? null;
            if (empty($startDate)) {
                continue;
            }

            $start = new DateTime($startDate);
            $start->setTime(0, 0, 0);
            $elapsedDays = (int)round(($today->getTimestamp() - $start->getTimestamp()) / (24 * 3600));
            $remainingDays = $targetDays - $elapsedDays;
            $percentUsed = $targetDays > 0 ? $elapsedDays / $targetDays : 0;
            $isOverdue = $remainingDays < 0;

            // Include if overdue or remaining time is less than threshold
            if ($isOverdue || (1 - $percentUsed) <= $threshold) {
                $ct = $caseTypeMap[$case['caseType'] ?? 'unknown'] ?? [];
                $results[] = [
                    'id' => $case['id'] ?? '',
                    'title' => $case['title'] ?? '',
                    'identifier' => $case['identifier'] ?? '',
                    'caseTypeName' => $ct['title'] ?? $ct['name'] ?? 'Unknown',
                    'targetDays' => $targetDays,
                    'elapsedDays' => $elapsedDays,
                    'remainingDays' => $remainingDays,
                    'percentUsed' => min($percentUsed, 1.5),
                    'isOverdue' => $isOverdue,
                ];
            }
        }

        // Sort: overdue first, then by least remaining time
        usort($results, function (array $a, array $b) {
            if ($a['isOverdue'] && !$b['isOverdue']) {
                return -1;
            }
            if (!$a['isOverdue'] && $b['isOverdue']) {
                return 1;
            }
            return $a['remainingDays'] - $b['remainingDays'];
        });

        return $results;
    }//end getAtRiskCases()


    /**
     * Compute per-case-type performance table.
     *
     * @param array<int, array<string, mixed>> $completedCases Completed cases
     * @param array<int, array<string, mixed>> $caseTypes      Case types
     *
     * @return array<int, array<string, mixed>> Performance table rows
     *
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-DL-01
     */
    private function computePerformanceTable(array $completedCases, array $caseTypes): array
    {
        $byType = [];

        // Initialize all case types
        foreach ($caseTypes as $ct) {
            $ctId = $ct['id'] ?? 'unknown';
            $targetDays = null;
            if (!empty($ct['processingDeadline'])) {
                $targetDays = $this->parseDurationToDays($ct['processingDeadline']);
            }

            $byType[$ctId] = [
                'id' => $ctId,
                'name' => $ct['title'] ?? $ct['name'] ?? 'Unknown',
                'targetDays' => $targetDays,
                'totalDays' => 0,
                'total' => 0,
                'withinSla' => 0,
            ];
        }

        // Populate with completed case data
        foreach ($completedCases as $case) {
            $ctId = $case['caseType'] ?? 'unknown';
            if (!isset($byType[$ctId])) {
                continue;
            }

            $actualDays = $this->getProcessingDays($case);
            if ($actualDays === null) {
                continue;
            }

            $byType[$ctId]['total']++;
            $byType[$ctId]['totalDays'] += $actualDays;
            if ($byType[$ctId]['targetDays'] !== null && $actualDays <= $byType[$ctId]['targetDays']) {
                $byType[$ctId]['withinSla']++;
            }
        }

        $result = [];
        foreach ($byType as $entry) {
            $avgActualDays = $entry['total'] > 0 ? round($entry['totalDays'] / $entry['total']) : null;
            $complianceRate = $entry['total'] > 0 && $entry['targetDays'] !== null
                ? round(($entry['withinSla'] / $entry['total']) * 100)
                : null;

            $status = 'no-target';
            if ($entry['targetDays'] !== null && $complianceRate !== null) {
                if ($complianceRate >= 90) {
                    $status = 'good';
                } elseif ($complianceRate >= 70) {
                    $status = 'warning';
                } else {
                    $status = 'critical';
                }
            }

            $result[] = [
                'id' => $entry['id'],
                'name' => $entry['name'],
                'targetDays' => $entry['targetDays'],
                'avgActualDays' => $avgActualDays,
                'complianceRate' => $complianceRate,
                'total' => $entry['total'],
                'withinSla' => $entry['withinSla'],
                'status' => $status,
            ];
        }

        return $result;
    }//end computePerformanceTable()


    /**
     * Build a map of case type ID to case type object.
     *
     * @param array<int, array<string, mixed>> $caseTypes Case types
     *
     * @return array<string, array<string, mixed>> Case type map
     */
    private function buildCaseTypeMap(array $caseTypes): array
    {
        $map = [];
        foreach ($caseTypes as $ct) {
            $ctId = $ct['id'] ?? 'unknown';
            $map[$ctId] = $ct;
        }
        return $map;
    }//end buildCaseTypeMap()


    /**
     * Get SLA target days for a case based on its case type.
     *
     * @param array<string, mixed>              $case        Case object
     * @param array<string, array<string, mixed>> $caseTypeMap Case type map
     *
     * @return int|null Target days or null
     */
    private function getSlaTargetDays(array $case, array $caseTypeMap): ?int
    {
        $ctId = $case['caseType'] ?? 'unknown';
        $ct = $caseTypeMap[$ctId] ?? null;
        if ($ct === null || empty($ct['processingDeadline'])) {
            return null;
        }
        return $this->parseDurationToDays($ct['processingDeadline']);
    }//end getSlaTargetDays()


    /**
     * Calculate actual processing time in days for a case.
     *
     * @param array<string, mixed> $case Case object with startDate and endDate
     *
     * @return int|null Processing days or null
     */
    private function getProcessingDays(array $case): ?int
    {
        $startDate = $case['startDate'] ?? null;
        $endDate = $case['endDate'] ?? null;
        if (empty($startDate) || empty($endDate)) {
            return null;
        }

        try {
            $start = new DateTime($startDate);
            $end = new DateTime($endDate);
            $start->setTime(0, 0, 0);
            $end->setTime(0, 0, 0);

            $days = (int)round(($end->getTimestamp() - $start->getTimestamp()) / (24 * 3600));
            return max(0, $days);
        } catch (\Exception $e) {
            return null;
        }
    }//end getProcessingDays()


    /**
     * Parse ISO 8601 duration string to calendar days.
     *
     * @param string $duration ISO 8601 duration (e.g., "P30D")
     *
     * @return int|null Number of days or null
     */
    private function parseDurationToDays(string $duration): ?int
    {
        if (empty($duration)) {
            return null;
        }

        // Pattern: P[n]Y[n]M[n]W[n]D
        if (!preg_match('/^P(?:(\d+)Y)?(?:(\d+)M)?(?:(\d+)W)?(?:(\d+)D)?$/i', $duration, $matches)) {
            return null;
        }

        $years = (int)($matches[1] ?? 0);
        $months = (int)($matches[2] ?? 0);
        $weeks = (int)($matches[3] ?? 0);
        $days = (int)($matches[4] ?? 0);

        $total = ($years * 365) + ($months * 30) + ($weeks * 7) + $days;
        return $total > 0 ? $total : null;
    }//end parseDurationToDays()


    /**
     * Get empty metrics response.
     *
     * @return array<string, mixed> Empty metrics
     */
    private function getEmptyMetrics(): array
    {
        return [
            'slaCompliance' => [
                'overallRate' => null,
                'withinSla' => 0,
                'total' => 0,
                'excluded' => 0,
                'byType' => [],
            ],
            'distribution' => [
                'bins' => [],
                'slaTargetDays' => null,
            ],
            'monthlyTrend' => [],
            'atRiskCases' => [],
            'performanceTable' => [],
        ];
    }//end getEmptyMetrics()
}//end class
