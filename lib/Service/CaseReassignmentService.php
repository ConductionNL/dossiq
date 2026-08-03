<?php

/**
 * Procest CaseReassignmentService.
 *
 * Permanent bulk transfer of a handler's open workload to another handler —
 * the coordinator-driven counterpart of temporary substitution. Preview is
 * non-mutating; execute reassigns each open case/task in a single previewed,
 * audited batch (per-item audit entry sharing a batch id, per-item
 * success/failure, a single digest notification to the receiving handler).
 * Closed and archived cases are never touched.
 *
 * The coordinator-role guard is enforced at the controller surface; this
 * service performs the data work.
 *
 * @category Service
 * @package  OCA\Procest\Service
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
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTime;
use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\Support\ReassignmentBatch;
use OCA\Procest\Service\Support\SearchesObjects;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Previews and executes bulk reassignment of a handler's open workload.
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */
class CaseReassignmentService
{
    use SearchesObjects;

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService     The settings/config + ObjectService bridge.
     * @param IManager        $notificationManager The Nextcloud notification manager.
     * @param LoggerInterface $logger              The logger.
     *
     * @return void
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly IManager $notificationManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Preview the open cases and tasks that a reassignment from a handler would
     * affect. Strictly read-only.
     *
     * @param string                    $fromUser The departing handler user id.
     * @param array<string, mixed>|null $filter   Optional filter, e.g. ['caseType' => 'uuid'].
     *
     * @return array{cases: array<int, array<string, mixed>>, tasks: array<int, array<string, mixed>>}
     *
     * @spec openspec/specs/handler-vervanging-waarneming/spec.md
     */
    public function preview(string $fromUser, ?array $filter=null): array
    {
        $fromUser = trim($fromUser);
        if ($fromUser === '') {
            throw new InvalidArgumentException('fromUser is required');
        }

        [$objectService, $register] = $this->context();
        $caseSchema = (string) $this->settingsService->getConfigValue('case_schema');
        $taskSchema = (string) $this->settingsService->getConfigValue('task_schema');
        $caseType   = '';
        if (isset($filter['caseType']) === true) {
            $caseType = (string) $filter['caseType'];
        }

        $finalIds = $this->finalStatusIds(objectService: $objectService, register: $register);

        $cases = [];
        if ($caseSchema !== '') {
            $caseResults = $this->searchObjectsAsArrays(
                objectService: $objectService,
                register: $register,
                schema: $caseSchema,
                filters: ['assignee' => $fromUser]
            );
            foreach ($caseResults as $case) {
                if (in_array((string) ($case['status'] ?? ''), $finalIds, true) === true) {
                    continue;
                }

                if ($caseType !== '' && (string) ($case['caseType'] ?? '') !== $caseType) {
                    continue;
                }

                $cases[] = $case;
            }
        }

        $tasks = [];
        if ($taskSchema !== '') {
            $caseIds = [];
            foreach ($cases as $c) {
                $caseIds[(string) ($c['id'] ?? ($c['uuid'] ?? ''))] = true;
            }

            $taskResults = $this->searchObjectsAsArrays(
                objectService: $objectService,
                register: $register,
                schema: $taskSchema,
                filters: ['assignee' => $fromUser]
            );
            foreach ($taskResults as $task) {
                if (in_array((string) ($task['status'] ?? ''), ['completed', 'terminated', 'disabled'], true) === true) {
                    continue;
                }

                // When narrowed by caseType, only tasks belonging to a previewed
                // case are in scope.
                if ($caseType !== '' && isset($caseIds[(string) ($task['case'] ?? '')]) === false) {
                    continue;
                }

                $tasks[] = $task;
            }
        }//end if

        return ['cases' => $cases, 'tasks' => $tasks];
    }//end preview()

    /**
     * Execute a bulk reassignment from one handler to another.
     *
     * Reassigns every previewed open case/task to the receiving handler, writing
     * a per-item audit entry sharing a single batch id and recording the
     * previous handler, new handler, and acting coordinator. Closed/archived
     * cases are untouched. A single digest notification summarising the transfer
     * is sent to the receiving handler. Returns a per-item success/failure
     * report; failed items remain on the original handler and are re-runnable.
     *
     * @param string                    $fromUser The departing handler user id.
     * @param string                    $toUser   The receiving handler user id.
     * @param array<string, mixed>|null $filter   Optional filter, e.g. ['caseType' => 'uuid'].
     * @param string                    $actorId  The acting coordinator user id.
     *
     * @return array{batchId: string, results: array<int, array<string, mixed>>, succeeded: int, failed: int}
     *
     * @spec openspec/specs/handler-vervanging-waarneming/spec.md
     */
    public function execute(string $fromUser, string $toUser, ?array $filter=null, string $actorId=''): array
    {
        $fromUser = trim($fromUser);
        $toUser   = trim($toUser);
        if ($fromUser === '' || $toUser === '') {
            throw new InvalidArgumentException('Both fromUser and toUser are required');
        }

        if ($fromUser === $toUser) {
            throw new InvalidArgumentException('Cannot reassign a handler to themselves');
        }

        [$objectService, $register] = $this->context();
        $caseSchema = (string) $this->settingsService->getConfigValue('case_schema');
        $taskSchema = (string) $this->settingsService->getConfigValue('task_schema');

        $preview = $this->preview(fromUser: $fromUser, filter: $filter);
        $batchId = $this->generateBatchId();
        $now     = (new DateTimeImmutable())->format('Y-m-d\TH:i:sP');

        $batch = new ReassignmentBatch(
            fromUser: $fromUser,
            toUser: $toUser,
            actorId: $actorId,
            batchId: $batchId,
            now: $now
        );

        $results   = [];
        $succeeded = 0;

        foreach ($preview['cases'] as $case) {
            $id        = (string) ($case['id'] ?? ($case['uuid'] ?? ''));
            $success   = $this->reassignItem(
                objectService: $objectService,
                register: $register,
                schema: $caseSchema,
                id: $id,
                item: $case,
                batch: $batch
            );
            $results[] = ['type' => 'case', 'id' => $id, 'title' => (string) ($case['title'] ?? ''), 'success' => $success];
            if ($success === true) {
                $succeeded += 1;
            }
        }

        foreach ($preview['tasks'] as $task) {
            $id        = (string) ($task['id'] ?? ($task['uuid'] ?? ''));
            $success   = $this->reassignItem(
                objectService: $objectService,
                register: $register,
                schema: $taskSchema,
                id: $id,
                item: $task,
                batch: $batch
            );
            $results[] = ['type' => 'task', 'id' => $id, 'title' => (string) ($task['title'] ?? ''), 'success' => $success];
            if ($success === true) {
                $succeeded += 1;
            }
        }

        $failed = (count($results) - $succeeded);

        // Single digest notification to the receiving handler.
        if ($succeeded > 0) {
            $this->notifyDigest(toUser: $toUser, fromUser: $fromUser, count: $succeeded, batchId: $batchId);
        }

        $this->logger->info(
            'Procest bulk reassignment executed',
            ['batchId' => $batchId, 'from' => $fromUser, 'to' => $toUser, 'actor' => $actorId, 'succeeded' => $succeeded, 'failed' => $failed]
        );

        return ['batchId' => $batchId, 'results' => $results, 'succeeded' => $succeeded, 'failed' => $failed];
    }//end execute()

    /**
     * Reassign a single item and append a batch audit entry.
     *
     * @param object               $objectService The ObjectService.
     * @param string               $register      Register id.
     * @param string               $schema        Schema id (case or task).
     * @param string               $id            Object id.
     * @param array<string, mixed> $item          The object payload.
     * @param ReassignmentBatch    $batch         The shared batch header.
     *
     * @return bool Whether the item was reassigned.
     */
    private function reassignItem(
        object $objectService,
        string $register,
        string $schema,
        string $id,
        array $item,
        ReassignmentBatch $batch
    ): bool {
        if ($id === '' || $schema === '') {
            return false;
        }

        try {
            $item['assignee'] = $batch->toUser;

            // Append a batch audit entry onto the activity log when present
            // (cases carry an activity property; tasks may not).
            $activity = [];
            $raw      = ($item['activity'] ?? null);
            if (is_array($raw) === true) {
                $activity = $raw;
            } else if (is_string($raw) === true && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) === true) {
                    $activity = $decoded;
                }
            }

            $activity[] = [
                'type'           => 'reassignment',
                'reassignedFrom' => $batch->fromUser,
                'reassignedTo'   => $batch->toUser,
                'reassignedBy'   => $batch->actorId,
                'batchId'        => $batch->batchId,
                'timestamp'      => $batch->now,
            ];
            if (array_key_exists('activity', $item) === true || $schema === (string) $this->settingsService->getConfigValue('case_schema')) {
                $item['activity'] = json_encode($activity);
            }

            $objectService->updateObject($register, $schema, $id, $item);
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Reassignment item failed',
                ['id' => $id, 'batchId' => $batch->batchId, 'error' => $e->getMessage()]
            );
            return false;
        }//end try
    }//end reassignItem()

    /**
     * Send a single digest notification to the receiving handler.
     *
     * @param string $toUser   Receiving handler.
     * @param string $fromUser Departing handler.
     * @param int    $count    Number of items transferred.
     * @param string $batchId  The batch id.
     *
     * @return void
     */
    private function notifyDigest(string $toUser, string $fromUser, int $count, string $batchId): void
    {
        try {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp(Application::APP_ID)
                ->setUser($toUser)
                ->setDateTime(new DateTime())
                ->setObject('reassignment', $batchId)
                ->setSubject(
                    'cases_reassigned',
                    ['fromUser' => $fromUser, 'count' => $count]
                );
            $this->notificationManager->notify($notification);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Reassignment digest notification failed',
                ['toUser' => $toUser, 'batchId' => $batchId, 'error' => $e->getMessage()]
            );
        }
    }//end notifyDigest()

    /**
     * Resolve the set of final statusType ids (closed/archived cases).
     *
     * @param object $objectService The ObjectService.
     * @param string $register      Register id.
     *
     * @return array<int, string>
     */
    private function finalStatusIds(object $objectService, string $register): array
    {
        $statusTypeSchema = (string) $this->settingsService->getConfigValue('status_type_schema');
        if ($statusTypeSchema === '') {
            return [];
        }

        try {
            $rows = $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $statusTypeSchema);
        } catch (\Throwable $e) {
            return [];
        }

        $ids = [];
        foreach ($rows as $row) {
            $isFinal = ($row['isFinal'] ?? false);
            if ($isFinal === true || $isFinal === 'true' || $isFinal === 1) {
                $ids[] = (string) ($row['id'] ?? ($row['uuid'] ?? ''));
            }
        }

        return array_values(array_filter($ids));
    }//end finalStatusIds()

    /**
     * Generate a unique batch id.
     *
     * @return string
     */
    private function generateBatchId(): string
    {
        try {
            return 'batch-'.bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            return 'batch-'.uniqid('', true);
        }
    }//end generateBatchId()

    /**
     * Resolve ObjectService + register, throwing when unavailable.
     *
     * @return array{0: object, 1: string}
     *
     * @throws \RuntimeException When OpenRegister is not available.
     */
    private function context(): array
    {
        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        if ($objectService === null || $register === '') {
            throw new RuntimeException('OpenRegister is not available');
        }

        return [$objectService, $register];
    }//end context()
}//end class
