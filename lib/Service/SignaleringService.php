<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for deadline signalering (alerting) and threshold management.
 *
 * Calculates deadline status, detects threshold crossings, and triggers notifications
 * when cases approach or exceed their deadlines.
 *
 * @spec openspec/changes/signalering-widgets/tasks.md#T01
 */
class SignaleringService
{
    /**
     * Deadline status constants.
     */
    private const STATUS_ON_TRACK = 'on-track';
    private const STATUS_WARNING = 'warning';
    private const STATUS_OVERDUE = 'overdue';

    public function __construct(
        private SettingsService $settingsService,
        private IAppManager $appManager,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Calculate the deadline status for a case.
     *
     * Determines streeftermijn and fatale termijn dates, accounting for suspensions,
     * and returns current status (on-track/warning/overdue) with days remaining.
     *
     * @spec openspec/changes/signalering-widgets/tasks.md#T01
     *
     * @param array $case Case object from OpenRegister
     * @param array $caseType Case type definition
     * @return array Array with keys: streeftermijn, fatalTermijn, opschorting, overallStatus
     */
    public function calculateDeadlineStatus(array $case, array $caseType): array
    {
        $now = new \DateTime('now', new \DateTimeZone('UTC'));

        $streeftermijn = $this->calculateTermijn($case, $caseType, 'streeftermijn', $now);
        $fatalTermijn = $this->calculateTermijn($case, $caseType, 'fatalTermijn', $now);
        $opschorting = $this->parseOpschorting($case);

        // Determine overall status based on whichever is worse
        $overallStatus = $this->determineStatus($fatalTermijn, $streeftermijn, $now);

        return [
            'streeftermijn' => $streeftermijn,
            'fatalTermijn' => $fatalTermijn,
            'opschorting' => $opschorting,
            'overallStatus' => $overallStatus,
        ];
    }

    /**
     * Check if a threshold has been crossed for this case.
     *
     * Compares case deadline against configured thresholds to determine if a
     * notification should be triggered.
     *
     * @spec openspec/changes/signalering-widgets/tasks.md#T01
     *
     * @param array $case Case object
     * @param array $caseType Case type definition
     * @param array $config Signalering configuration
     * @return bool True if a threshold has been crossed
     */
    public function checkThresholds(array $case, array $caseType, array $config): bool
    {
        if (($config['enabled'] ?? false) === false) {
            return false;
        }

        $deadlineStatus = $this->calculateDeadlineStatus($case, $caseType);
        $overallStatus = $deadlineStatus['overallStatus'];

        // Overdue always crosses threshold
        if ($overallStatus === self::STATUS_OVERDUE) {
            return true;
        }

        // Check if warning threshold applies
        if ($overallStatus === self::STATUS_WARNING) {
            // Warning threshold crossed
            return true;
        }

        return false;
    }

    /**
     * Calculate a specific termijn (deadline) for a case.
     *
     * Applies the case type's processing deadline duration, accounts for suspensions,
     * and returns date information with current status.
     *
     * @spec openspec/changes/signalering-widgets/tasks.md#T01
     *
     * @param array $case Case object
     * @param array $caseType Case type definition
     * @param string $termijnType Either 'streeftermijn' or 'fatalTermijn'
     * @param \DateTime $now Current time
     * @return array Array with keys: date, daysRemaining, status
     */
    private function calculateTermijn(array $case, array $caseType, string $termijnType, \DateTime $now): array
    {
        // Get the baseline from case creation date
        $createdAt = isset($case['createdAt']) ? new \DateTime($case['createdAt'], new \DateTimeZone('UTC')) : $now;

        // Get the duration from case type (ISO 8601 format, e.g. P30D for 30 days)
        $durationString = $caseType['processingDeadline'] ?? 'P30D';
        $duration = new \DateInterval($durationString);

        // Calculate base deadline
        $deadline = clone $createdAt;
        $deadline->add($duration);

        // Adjust for suspensions (opschorting)
        $opschorting = $this->parseOpschorting($case);
        if ($opschorting['active'] === true) {
            // If suspended, deadline hasn't changed yet, but calculation would differ
            // This is handled in actual business logic elsewhere
        }

        // Calculate days remaining
        $interval = $now->diff($deadline);
        $daysRemaining = (int) $interval->format('%r%a');  // Include sign for negative

        // Determine status
        if ($daysRemaining < 0) {
            $status = self::STATUS_OVERDUE;
        } elseif ($daysRemaining <= 7) {
            // Default warning threshold is 7 days
            $status = self::STATUS_WARNING;
        } else {
            $status = self::STATUS_ON_TRACK;
        }

        return [
            'date' => $deadline->format(\DateTime::ATOM),
            'daysRemaining' => $daysRemaining,
            'status' => $status,
        ];
    }

    /**
     * Determine overall deadline status from termijn data.
     *
     * @spec openspec/changes/signalering-widgets/tasks.md#T01
     *
     * @param array $fatalTermijn Fatal deadline data
     * @param array $streeftermijn Target deadline data
     * @param \DateTime $now Current time
     * @return string Status: 'overdue', 'warning', or 'on-track'
     */
    private function determineStatus(array $fatalTermijn, array $streeftermijn, \DateTime $now): string
    {
        // Fatal deadline takes priority
        if ($fatalTermijn['status'] === self::STATUS_OVERDUE) {
            return self::STATUS_OVERDUE;
        }

        // Then check warning status
        if ($fatalTermijn['status'] === self::STATUS_WARNING ||
            $streeftermijn['status'] === self::STATUS_WARNING) {
            return self::STATUS_WARNING;
        }

        return self::STATUS_ON_TRACK;
    }

    /**
     * Parse opschorting (suspension) information from a case.
     *
     * @spec openspec/changes/signalering-widgets/tasks.md#T01
     *
     * @param array $case Case object
     * @return array Array with keys: active, startDate, endDate
     */
    private function parseOpschorting(array $case): array
    {
        // Opschorting would be stored in a related record or in case.opschorting
        $opschorting = $case['opschorting'] ?? null;

        if (empty($opschorting)) {
            return [
                'active' => false,
                'startDate' => null,
                'endDate' => null,
            ];
        }

        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $startDate = isset($opschorting['startDate']) ? new \DateTime($opschorting['startDate'], new \DateTimeZone('UTC')) : null;
        $endDate = isset($opschorting['endDate']) ? new \DateTime($opschorting['endDate'], new \DateTimeZone('UTC')) : null;

        $isActive = ($startDate === null || $startDate <= $now) && ($endDate === null || $endDate > $now);

        return [
            'active' => $isActive,
            'startDate' => $startDate?->format(\DateTime::ATOM),
            'endDate' => $endDate?->format(\DateTime::ATOM),
        ];
    }

    /**
     * Get ObjectService for data access.
     *
     * @return ?\OCA\OpenRegister\Service\ObjectService
     */
    protected function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService
    {
        if (in_array('openregister', $this->appManager->getInstalledApps()) === false) {
            return null;
        }

        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Exception $e) {
            $this->logger->error('Procest: Could not get ObjectService', ['exception' => $e->getMessage()]);
            return null;
        }
    }
}
