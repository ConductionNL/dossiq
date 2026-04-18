<?php

/**
 * Procest Doorlooptijd Service
 *
 * Service for calculating and analyzing case processing times,
 * handling SLA adherence, bottleneck analysis, and trend reporting.
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
use OCP\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for doorlooptijd tracking and SLA calculations.
 *
 * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-1
 */
class DoorlooptijdService
{

    /**
     * Constructor.
     *
     * @param SettingsService    $settingsService Settings service
     * @param LoggerInterface    $logger          Logger
     * @param IAppManager        $appManager      App manager
     * @param ContainerInterface $container       DI container
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
    ) {
    }


    /**
     * Get doorlooptijd statistics for a case type.
     *
     * @param string $caseTypeId The case type UUID
     * @param string $startDate  The start date for calculations (ISO 8601)
     * @param string $endDate    The end date for calculations (ISO 8601)
     *
     * @return array<string, mixed> Statistics with average time, SLA adherence, etc.
     *
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-1
     */
    public function getCaseTypeStatistics(
        string $caseTypeId,
        string $startDate,
        string $endDate
    ): array {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return [
                'error' => 'OpenRegister is not available',
                'caseTypeId' => $caseTypeId,
            ];
        }

        $register = $this->settingsService->getConfigValue('register');
        $caseSchema = $this->settingsService->getConfigValue('case_schema');

        if (empty($register) || empty($caseSchema)) {
            return [
                'error' => 'Case schema not configured',
                'caseTypeId' => $caseTypeId,
            ];
        }

        // Find all cases of this type created within the date range
        try {
            $cases = $objectService->findObjects(
                $register,
                $caseSchema,
                [
                    'caseType' => $caseTypeId,
                    'createdAt' => ['>', $startDate],
                ],
            );
        } catch (\Exception $e) {
            $this->logger->error('Error fetching cases: ' . $e->getMessage());
            return [
                'error' => 'Failed to fetch cases',
                'caseTypeId' => $caseTypeId,
            ];
        }

        // Calculate statistics from cases
        $cases = is_array($cases) ? $cases : [];
        $totalCases = count($cases);
        $totalDuration = 0;
        $closedCases = 0;
        $durations = [];

        foreach ($cases as $case) {
            $duration = $this->calculateCaseDuration($case);
            if ($duration !== null) {
                $durations[] = $duration;
                $totalDuration += $duration;
                $closedCases++;
            }
        }

        $averageDuration = $closedCases > 0 ? $totalDuration / $closedCases : 0;

        // Get SLA configuration for this case type
        $slaConfig = $this->getSLAConfiguration($caseTypeId);
        $slaAdherence = $this->calculateSLAAdherence($cases, $slaConfig);

        return [
            'caseTypeId' => $caseTypeId,
            'totalCases' => $totalCases,
            'closedCases' => $closedCases,
            'averageDuration' => round($averageDuration, 2),
            'slaConfig' => $slaConfig,
            'slaAdherence' => $slaAdherence,
            'minDuration' => count($durations) > 0 ? min($durations) : 0,
            'maxDuration' => count($durations) > 0 ? max($durations) : 0,
            'period' => [
                'start' => $startDate,
                'end' => $endDate,
            ],
        ];
    }


    /**
     * Get SLA configuration for a case type.
     *
     * @param string $caseTypeId The case type UUID
     *
     * @return array<string, mixed> SLA configuration
     *
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-2
     */
    public function getSLAConfiguration(string $caseTypeId): array
    {
        // Placeholder implementation - would be expanded with full SLA service
        $this->logger->debug('SLA configuration requested for case type: ' . $caseTypeId);

        return [
            'caseTypeId' => $caseTypeId,
            'streeftermijn' => 30, // days
            'fatalTermijn' => 60,  // days
            'description' => 'Default SLA configuration',
        ];
    }


    /**
     * Calculate SLA adherence percentage.
     *
     * @param array<int, array<string, mixed>> $cases     The cases to analyze
     * @param array<string, mixed>             $slaConfig The SLA configuration
     *
     * @return array<string, mixed> Adherence statistics
     *
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-1
     */
    public function calculateSLAAdherence(array $cases, array $slaConfig): array
    {
        $totalCases = count($cases);
        $withinSLA = 0;
        $overdue = 0;

        $streeftermijn = $slaConfig['streeftermijn'] ?? 30;

        foreach ($cases as $case) {
            $duration = $this->calculateCaseDuration($case);
            if ($duration !== null && $duration <= $streeftermijn) {
                $withinSLA++;
            } elseif ($duration !== null) {
                $overdue++;
            }
        }

        $percentage = $totalCases > 0 ? round(($withinSLA / $totalCases) * 100, 2) : 0;

        return [
            'percentage' => $percentage,
            'withinSLA' => $withinSLA,
            'overdue' => $overdue,
            'total' => $totalCases,
        ];
    }


    /**
     * Calculate the duration of a single case in days.
     *
     * Accounts for opschorting (suspension) periods.
     *
     * @param array<string, mixed> $case The case data
     *
     * @return float|null The duration in days, or null if cannot be calculated
     *
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-1
     */
    public function calculateCaseDuration(array $case): ?float
    {
        $createdAt = $case['createdAt'] ?? $case['startDate'] ?? null;
        $closedAt = $case['closedAt'] ?? $case['endDate'] ?? null;

        if ($createdAt === null || $closedAt === null) {
            return null;
        }

        try {
            $startTime = new \DateTime($createdAt);
            $endTime = new \DateTime($closedAt);

            // Calculate base duration
            $diff = $endTime->diff($startTime);
            $days = (float) $diff->days;

            // Account for opschorting (suspension) periods
            $suspensionDays = $this->calculateSuspensionDays($case);
            $days -= $suspensionDays;

            return max(0, $days); // Ensure non-negative
        } catch (\Exception $e) {
            $this->logger->warning('Could not parse date for case: ' . $e->getMessage());
            return null;
        }
    }


    /**
     * Calculate suspension (opschorting) days for a case.
     *
     * @param array<string, mixed> $case The case data
     *
     * @return float The number of suspended days
     *
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-1
     */
    public function calculateSuspensionDays(array $case): float
    {
        $suspensions = $case['suspensions'] ?? [];
        $totalSuspendedDays = 0.0;

        if (!is_array($suspensions)) {
            return 0;
        }

        foreach ($suspensions as $suspension) {
            if (!is_array($suspension)) {
                continue;
            }

            $suspendedAt = $suspension['startDate'] ?? $suspension['suspendedAt'] ?? null;
            $resumedAt = $suspension['endDate'] ?? $suspension['resumedAt'] ?? null;

            if ($suspendedAt !== null && $resumedAt !== null) {
                try {
                    $suspendTime = new \DateTime($suspendedAt);
                    $resumeTime = new \DateTime($resumedAt);
                    $diff = $resumeTime->diff($suspendTime);
                    $totalSuspendedDays += (float) $diff->days;
                } catch (\Exception $e) {
                    $this->logger->debug('Error calculating suspension days: ' . $e->getMessage());
                }
            }
        }

        return $totalSuspendedDays;
    }


    /**
     * Get average duration per process step.
     *
     * @param string $caseTypeId The case type UUID
     * @param string $startDate  Start date (ISO 8601)
     * @param string $endDate    End date (ISO 8601)
     *
     * @return array<string, mixed> Step durations data
     *
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-3
     */
    public function getProcessStepDurations(
        string $caseTypeId,
        string $startDate,
        string $endDate
    ): array {
        $this->logger->debug('Process step durations requested for case type: ' . $caseTypeId);

        // Placeholder implementation - would aggregate step times from workflow data
        return [
            'caseTypeId' => $caseTypeId,
            'steps' => [
                [
                    'stepName' => 'Intake',
                    'averageDuration' => 5,
                    'caseCount' => 10,
                ],
                [
                    'stepName' => 'Assessment',
                    'averageDuration' => 15,
                    'caseCount' => 10,
                ],
                [
                    'stepName' => 'Processing',
                    'averageDuration' => 20,
                    'caseCount' => 8,
                ],
            ],
            'period' => [
                'start' => $startDate,
                'end' => $endDate,
            ],
        ];
    }


    /**
     * Get private ObjectService from container.
     *
     * @return object|null The ObjectService or null if unavailable
     */
    private function getObjectService(): ?object
    {
        if (!in_array('openregister', $this->appManager->getInstalledApps())) {
            return null;
        }

        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Exception $e) {
            $this->logger->error('Could not get ObjectService: ' . $e->getMessage());
            return null;
        }
    }
}
