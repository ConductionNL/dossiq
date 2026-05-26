<?php

/**
 * Procest Milestone Service
 *
 * Service for managing milestones: configurable progress markers on cases
 * that translate technical workflow states into business-friendly indicators.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-milestone-tracking/tasks.md#task-2
 * @spec openspec/changes/retrofit-2026-05-24-milestone-tracking/tasks.md#task-3
 * @spec openspec/changes/retrofit-2026-05-24-milestone-tracking/tasks.md#task-4
 * @spec openspec/changes/retrofit-2026-05-24-milestone-tracking/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use Psr\Log\LoggerInterface;

/**
 * Service for milestone tracking and progress calculation.
 */
class MilestoneService
{
    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Settings service
     * @param LoggerInterface $logger          Logger
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get milestone definitions for a case type.
     *
     * @param string $caseTypeId The case type UUID
     *
     * @return array<int, array<string, mixed>> Ordered milestone definitions
     *
     * @throws \RuntimeException If OpenRegister unavailable
     */
    /** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
    public function getMilestones(string $caseTypeId): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('milestone_definition_schema');

        if (empty($register) === true || empty($schema) === true) {
            return [];
        }

        $results = $objectService->findObjects(
            $register,
            $schema,
            ['caseType' => $caseTypeId],
            ['order' => 'asc'],
            100,
        );

        if (is_array($results) === true) {
            return $results;
        }

        return [];
    }//end getMilestones()

    /**
     * Get milestone progress for a specific case.
     *
     * @param string $caseId     The case UUID
     * @param string $caseTypeId The case type UUID
     *
     * @return array<string, mixed> Progress data with milestones, reached count, total, percentage
     */
    /** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
    public function getCaseProgress(string $caseId, string $caseTypeId): array
    {
        $definitions = $this->getMilestones(caseTypeId: $caseTypeId);
        if (count($definitions) === 0) {
            return [
                'milestones' => [],
                'reached'    => 0,
                'total'      => 0,
                'percentage' => 0,
            ];
        }

        $records   = $this->getMilestoneRecords(caseId: $caseId);
        $recordMap = [];
        foreach ($records as $record) {
            $recordMap[$record['milestoneDefinition'] ?? ''] = $record;
        }

        $milestones = [];
        $reached    = 0;
        foreach ($definitions as $def) {
            $defId     = $def['id'] ?? $def['uuid'] ?? '';
            $record    = $recordMap[$defId] ?? null;
            $isReached = $record !== null;

            if ($isReached === true) {
                $reached++;
            }

            if ($isReached === true) {
                $reachedAt = $record['reachedAt'] ?? null;
                $reachedBy = $record['reachedBy'] ?? null;
            } else {
                $reachedAt = null;
                $reachedBy = null;
            }

            $milestones[] = [
                'identifier'  => $def['identifier'] ?? '',
                'label'       => $def['label'] ?? $def['name'] ?? '',
                'order'       => $def['order'] ?? 0,
                'description' => $def['description'] ?? '',
                'reached'     => $isReached,
                'reachedAt'   => $reachedAt,
                'reachedBy'   => $reachedBy,
            ];
        }//end foreach

        // $definitions is guaranteed non-empty here (early return above when count === 0).
        $total      = count($definitions);
        $percentage = (int) round(($reached / $total) * 100);

        return [
            'milestones' => $milestones,
            'reached'    => $reached,
            'total'      => $total,
            'percentage' => $percentage,
        ];
    }//end getCaseProgress()

    /**
     * Mark a milestone as reached for a case.
     *
     * @param string $caseId                The case UUID
     * @param string $milestoneDefinitionId The milestone definition UUID
     * @param string $userId                The user marking the milestone
     * @param string $trigger               How it was triggered (manual, workflow, auto)
     *
     * @return array<string, mixed> The created milestone record
     *
     * @throws \RuntimeException If OpenRegister unavailable
     */
    /** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
    public function markMilestone(
        string $caseId,
        string $milestoneDefinitionId,
        string $userId,
        string $trigger='manual',
    ): array {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('milestone_record_schema');

        if (empty($register) === true || empty($schema) === true) {
            throw new \RuntimeException('Milestone record schema not configured');
        }

        $recordData = [
            'case'                => $caseId,
            'milestoneDefinition' => $milestoneDefinitionId,
            'reachedAt'           => date('Y-m-d\TH:i:s'),
            'reachedBy'           => $userId,
            'trigger'             => $trigger,
        ];

        $record = $objectService->saveObject($register, $schema, $recordData);

        $this->logger->info(
            'Milestone marked: '.$milestoneDefinitionId.' on case '.$caseId,
            ['app' => Application::APP_ID],
        );

        return [
            'id'        => $record->getUuid(),
            'reachedAt' => $recordData['reachedAt'],
            'reachedBy' => $userId,
        ];
    }//end markMilestone()

    /**
     * Reverse a milestone (with reason for audit trail).
     *
     * @param string $caseId                The case UUID
     * @param string $milestoneDefinitionId The milestone definition UUID
     * @param string $userId                The user reversing
     * @param string $reason                Reason for reversal
     *
     * @return bool True if reversed
     *
     * @throws \RuntimeException If OpenRegister unavailable
     */
    /** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
    public function reverseMilestone(
        string $caseId,
        string $milestoneDefinitionId,
        string $userId,
        string $reason,
    ): bool {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('milestone_record_schema');

        $records = $objectService->findObjects(
            $register,
            $schema,
            [
                'case'                => $caseId,
                'milestoneDefinition' => $milestoneDefinitionId,
            ],
        );

        if (empty($records) === true) {
            return false;
        }

        // Delete the milestone record.
        foreach ($records as $record) {
            $recordId = $record['id'] ?? $record['uuid'] ?? '';
            if ($recordId !== '') {
                $objectService->deleteObject($register, $schema, $recordId);
            }
        }

        $this->logger->info(
            'Milestone reversed: '.$milestoneDefinitionId.' on case '.$caseId
            .' by '.$userId.' reason: '.$reason,
            ['app' => Application::APP_ID],
        );

        return true;
    }//end reverseMilestone()

    /**
     * Calculate average duration between milestones for a case type.
     *
     * @param string $caseTypeId The case type UUID
     *
     * @return array<string, mixed> Duration analytics per milestone pair
     */
    /** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
    public function getDurationAnalytics(string $caseTypeId): array
    {
        // Placeholder: in production, this would aggregate milestone records
        // across all cases of this type and calculate averages.
        $this->logger->debug(
            'Duration analytics requested for case type: '.$caseTypeId,
            ['app' => Application::APP_ID],
        );

        return [
            'caseTypeId' => $caseTypeId,
            'phases'     => [],
            'message'    => 'Duration analytics requires sufficient historical data',
        ];
    }//end getDurationAnalytics()

    /**
     * Get milestone records for a case.
     *
     * @param string $caseId The case UUID
     *
     * @return array<int, array<string, mixed>> Milestone records
     */
    private function getMilestoneRecords(string $caseId): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('milestone_record_schema');

        if (empty($register) === true || empty($schema) === true) {
            return [];
        }

        $results = $objectService->findObjects(
            $register,
            $schema,
            ['case' => $caseId],
            [],
            100,
        );

        if (is_array($results) === true) {
            return $results;
        }

        return [];
    }//end getMilestoneRecords()
}//end class
