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
 * @spec openspec/specs/milestone-tracking/spec.md
 * @spec openspec/specs/milestone-tracking/spec.md
 * @spec openspec/specs/milestone-tracking/spec.md
 * @spec openspec/specs/milestone-tracking/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for milestone tracking and progress calculation.
 */
class MilestoneService
{

    use SearchesObjects;

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

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function getMilestones(string $caseTypeId): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('milestone_definition_schema');

        if (empty($register) === true || empty($schema) === true) {
            return [];
        }

        return $this->searchObjectsAsArrays(
            objectService: $objectService,
            register: $register,
            schema: $schema,
            filters: ['caseType' => $caseTypeId, '_limit' => 100],
        );
    }//end getMilestones()

    /**
     * Get milestone progress for a specific case.
     *
     * @param string $caseId     The case UUID
     * @param string $caseTypeId The case type UUID
     *
     * @return array<string, mixed> Progress data with milestones, reached count, total, percentage

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
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

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function markMilestone(
        string $caseId,
        string $milestoneDefinitionId,
        string $userId,
        string $trigger='manual',
    ): array {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('milestone_record_schema');

        if (empty($register) === true || empty($schema) === true) {
            throw new RuntimeException('Milestone record schema not configured');
        }

        $recordData = [
            'case'                => $caseId,
            'milestoneDefinition' => $milestoneDefinitionId,
            'reachedAt'           => date('Y-m-d\TH:i:s'),
            'reachedBy'           => $userId,
            'trigger'             => $trigger,
        ];

        $record = $objectService->saveObject(object: $recordData, register: $register, schema: $schema);

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

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function reverseMilestone(
        string $caseId,
        string $milestoneDefinitionId,
        string $userId,
        string $reason,
    ): bool {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('milestone_record_schema');

        $records = $this->searchObjectsAsArrays(
            objectService: $objectService,
            register: $register,
            schema: $schema,
            filters: [
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

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
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
     * Find active cases that have stalled past a milestone deadline.
     *
     * A case is considered stalled when its earliest unreached milestone has
     * an expected deadline (case start + cumulative expectedDurationWorkingDays)
     * that lies more than `$thresholdDays` calendar days in the past. Closed
     * cases (status containing "afgesloten"/"afgehandeld"/"geweigerd") are
     * skipped. The earliest unreached milestone — ordered by `order` — is the
     * one a case is "waiting on", so it is the one reported.
     *
     * @param int $thresholdDays Grace days past the computed deadline before a
     *                           case is flagged (default 0 = flag on overdue).
     *
     * @return array<int, array<string, mixed>> One entry per stalled case:
     *                                           caseId, caseTitle, caseType,
     *                                           assignee, milestoneIdentifier,
     *                                           milestoneLabel, deadline,
     *                                           daysOverdue.
     *
     * @spec openspec/specs/milestone-tracking/spec.md
     */
    public function findStalledCases(int $thresholdDays=0): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $register   = $this->settingsService->getConfigValue('register');
        $caseSchema = $this->settingsService->getConfigValue('case_schema');
        if ($register === '' || $caseSchema === '') {
            return [];
        }

        $cases = $this->searchObjectsAsArrays(
            objectService: $objectService,
            register: $register,
            schema: $caseSchema,
            filters: ['_limit' => 1000],
        );

        $today   = new DateTimeImmutable('today');
        $stalled = [];

        foreach ($cases as $case) {
            $caseId = (string) ($case['id'] ?? ($case['uuid'] ?? ''));
            $status = strtolower((string) ($case['status'] ?? ''));
            if ($caseId === '' || $this->isClosedStatus(status: $status) === true) {
                continue;
            }

            $caseTypeId = (string) ($case['caseType'] ?? '');
            if ($caseTypeId === '') {
                continue;
            }

            $startDate = $this->parseCaseStart(case: $case);
            if ($startDate === null) {
                continue;
            }

            $stall = $this->evaluateStall(
                caseId: $caseId,
                caseTypeId: $caseTypeId,
                startDate: $startDate,
                today: $today,
                thresholdDays: $thresholdDays,
            );

            if ($stall !== null) {
                $stall['caseTitle'] = (string) ($case['title'] ?? '');
                $stall['caseType']  = $caseTypeId;
                $stall['assignee']  = (string) ($case['assignee'] ?? '');
                $stalled[]          = $stall;
            }
        }//end foreach

        return $stalled;
    }//end findStalledCases()

    /**
     * Evaluate whether a single case has stalled on its earliest unreached
     * milestone and, if so, build the report row.
     *
     * @param string             $caseId        The case UUID.
     * @param string             $caseTypeId    The case type UUID.
     * @param \DateTimeImmutable $startDate     The case start date.
     * @param \DateTimeImmutable $today         Today (date only).
     * @param int                $thresholdDays Grace days past the deadline.
     *
     * @return array<string, mixed>|null Stall row, or null when on track.
     */
    private function evaluateStall(
        string $caseId,
        string $caseTypeId,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $today,
        int $thresholdDays,
    ): ?array {
        $definitions = $this->getMilestones(caseTypeId: $caseTypeId);
        if (count($definitions) === 0) {
            return null;
        }

        // Order definitions by their numeric `order`.
        usort(
            $definitions,
            static fn(array $a, array $b): int => ((int) ($a['order'] ?? 0) <=> (int) ($b['order'] ?? 0))
        );

        $records   = $this->getMilestoneRecords(caseId: $caseId);
        $reachedBy = [];
        foreach ($records as $record) {
            if ((bool) ($record['reached'] ?? true) === true) {
                $reachedBy[(string) ($record['milestoneDefinition'] ?? '')] = true;
            }
        }

        foreach ($definitions as $def) {
            $defId = (string) ($def['id'] ?? ($def['uuid'] ?? ''));
            if (isset($reachedBy[$defId]) === true) {
                continue;
            }

            // First unreached milestone — this is what the case waits on.
            $expectedDays = (int) ($def['expectedDurationWorkingDays'] ?? 0);
            $deadline     = $this->addWorkingDays(start: $startDate, workingDays: $expectedDays);
            $daysOverdue  = (int) $deadline->diff($today)->format('%r%a');

            if ($daysOverdue > $thresholdDays) {
                return [
                    'caseId'              => $caseId,
                    'milestoneIdentifier' => (string) ($def['identifier'] ?? ''),
                    'milestoneLabel'      => (string) ($def['label'] ?? ($def['name'] ?? '')),
                    'deadline'            => $deadline->format('Y-m-d'),
                    'daysOverdue'         => $daysOverdue,
                ];
            }

            // Earliest unreached milestone is within deadline -> on track.
            return null;
        }//end foreach

        // All milestones reached -> case complete, not stalled.
        return null;
    }//end evaluateStall()

    /**
     * Parse a case's start date into a date-only immutable value.
     *
     * @param array<string, mixed> $case The case object.
     *
     * @return \DateTimeImmutable|null The start date, or null when absent/invalid.
     */
    private function parseCaseStart(array $case): ?\DateTimeImmutable
    {
        $raw = (string) ($case['startDate'] ?? ($case['created'] ?? ''));
        if ($raw === '') {
            return null;
        }

        try {
            return new DateTimeImmutable(substr($raw, 0, 10));
        } catch (\Throwable $e) {
            return null;
        }
    }//end parseCaseStart()

    /**
     * Determine whether a (lower-cased) case status represents a closed case.
     *
     * @param string $status Lower-cased status string.
     *
     * @return bool True when the case is closed and should be skipped.
     */
    private function isClosedStatus(string $status): bool
    {
        foreach (['afgesloten', 'afgehandeld', 'geweigerd', 'ingetrokken', 'gearchiveerd'] as $needle) {
            if (str_contains($status, $needle) === true) {
                return true;
            }
        }

        return false;
    }//end isClosedStatus()

    /**
     * Add a number of working days (Mon-Fri) to a start date.
     *
     * Weekends are skipped. Dutch public holidays are not subtracted here; the
     * milestone layer's deadlines are advisory (per the proposal's out-of-scope
     * note on contractual SLA enforcement).
     *
     * @param \DateTimeImmutable $start       The start date.
     * @param int                $workingDays Working days to add (>= 0).
     *
     * @return \DateTimeImmutable The resulting deadline date.
     */
    private function addWorkingDays(\DateTimeImmutable $start, int $workingDays): \DateTimeImmutable
    {
        if ($workingDays <= 0) {
            return $start;
        }

        $date  = $start;
        $added = 0;
        while ($added < $workingDays) {
            $date = $date->modify('+1 day');
            $dow  = (int) $date->format('N');
            if ($dow < 6) {
                $added++;
            }
        }

        return $date;
    }//end addWorkingDays()

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

        return $this->searchObjectsAsArrays(
            objectService: $objectService,
            register: $register,
            schema: $schema,
            filters: ['case' => $caseId, '_limit' => 100],
        );
    }//end getMilestoneRecords()
}//end class
