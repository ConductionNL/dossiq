<?php

/**
 * Procest Case Status Store.
 *
 * Every OpenRegister read and write the status-transition engine performs.
 * Split out of StatusTransitionService so that service keeps only the
 * decision logic — which transition is available, whether its guards pass,
 * whether a concurrent write landed — while the mechanics of reaching the
 * object store live here: resolving the ObjectService bridge, reading the
 * register/schema ids out of configuration, coercing ObjectEntity results
 * to plain arrays, and translating a missing bridge or an unconfigured
 * schema into the engine's static error codes.
 *
 * The store covers four schemas the engine touches — `case`, `statusRecord`,
 * `caseType` and `statusType` — because they share exactly one concern:
 * they are the persistence surface of a single `case.status` transition.
 * Static error messages only; never bubble exception detail to callers.
 *
 * @category Service
 * @package  OCA\Procest\Service\Transitions
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Transitions;

use OCA\Procest\Service\SettingsService;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * OpenRegister persistence for the status-transition engine.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
class CaseStatusStore
{
    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Bridge to OpenRegister + config.
     * @param LoggerInterface $logger          The logger.
     *
     * @return void
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Load a case from OpenRegister.
     *
     * @param string $caseId Case UUID.
     *
     * @return array<string, mixed>|null The case, or null when unavailable.
     *
     * @spec openspec/specs/status-transition-engine/spec.md
     */
    public function loadCase(string $caseId): ?array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return null;
        }

        $register   = $this->settingsService->getConfigValue(key: 'register');
        $caseSchema = $this->settingsService->getConfigValue(key: 'case_schema');
        if ($register === '' || $caseSchema === '') {
            return null;
        }

        try {
            return $this->toArray(value: $objectService->find($caseId, register: $register, schema: $caseSchema));
        } catch (\Throwable $e) {
            $this->logger->error(
                'StatusTransitionService: loadCase failed',
                ['exception' => $e->getMessage(), 'caseId' => $caseId],
            );
            return null;
        }
    }//end loadCase()

    /**
     * Persist the (mutated) case via ObjectService.
     *
     * @param array<string, mixed> $case Case payload.
     *
     * @return array<string, mixed> The saved case.
     *
     * @throws RuntimeException When OpenRegister or the case schema is unavailable.
     *
     * @spec openspec/specs/status-transition-engine/spec.md
     */
    public function saveCase(array $case): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('storage_unavailable');
        }

        $register   = $this->settingsService->getConfigValue(key: 'register');
        $caseSchema = $this->settingsService->getConfigValue(key: 'case_schema');
        if ($register === '' || $caseSchema === '') {
            throw new RuntimeException('case_schema_not_configured');
        }

        return $this->toArray(value: $objectService->saveObject(object: $case, register: $register, schema: $caseSchema));
    }//end saveCase()

    /**
     * Write a statusRecord row for a transition.
     *
     * @param string                           $caseId             Case UUID.
     * @param string                           $toStatus           Target statusType UUID.
     * @param string                           $fromStatus         Prior statusType UUID.
     * @param string                           $label              Transition label.
     * @param string|null                      $comment            Free-form comment.
     * @param array<int, array<string, mixed>> $evaluatedGuards    Guard snapshots.
     * @param bool                             $noWorkflowTemplate Flag for free-form transitions.
     *
     * @return array<string, mixed> The written statusRecord.
     *
     * @throws RuntimeException When OpenRegister or the statusRecord schema is unavailable.
     *
     * @spec openspec/specs/status-transition-engine/spec.md
     */
    public function writeStatusRecord(
        string $caseId,
        string $toStatus,
        string $fromStatus,
        string $label,
        ?string $comment,
        array $evaluatedGuards,
        bool $noWorkflowTemplate,
    ): array {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('storage_unavailable');
        }

        $register     = $this->settingsService->getConfigValue(key: 'register');
        $recordSchema = $this->settingsService->getConfigValue(key: 'status_record_schema');
        if ($register === '' || $recordSchema === '') {
            throw new RuntimeException('status_record_schema_not_configured');
        }

        $payload = [
            'case'               => $caseId,
            'statusType'         => $toStatus,
            'transitionLabel'    => $label,
            'evaluatedGuards'    => $evaluatedGuards,
            'dispatchedActions'  => [],
            'noWorkflowTemplate' => $noWorkflowTemplate,
        ];
        if ($fromStatus !== '') {
            $payload['fromStatus'] = $fromStatus;
        }

        if ($comment !== null && $comment !== '') {
            $payload['description'] = $comment;
        }

        return $this->toArray(value: $objectService->saveObject(object: $payload, register: $register, schema: $recordSchema));
    }//end writeStatusRecord()

    /**
     * Persist an updated statusRecord.
     *
     * Returns the record untouched when OpenRegister is unavailable — the
     * caller treats a failed dispatched-action write-back as non-fatal.
     *
     * @param array<string, mixed> $record Current record payload.
     *
     * @return array<string, mixed> The saved (or unchanged) statusRecord.
     *
     * @spec openspec/specs/status-transition-engine/spec.md
     */
    public function updateStatusRecord(array $record): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return $record;
        }

        $register     = $this->settingsService->getConfigValue(key: 'register');
        $recordSchema = $this->settingsService->getConfigValue(key: 'status_record_schema');
        if ($register === '' || $recordSchema === '') {
            return $record;
        }

        return $this->toArray(value: $objectService->saveObject(object: $record, register: $register, schema: $recordSchema));
    }//end updateStatusRecord()

    /**
     * Fetch every statusRecord written for a case, unordered.
     *
     * OpenRegister's ObjectService exposes `searchObjects($query)` — there is
     * NO `findObjects()` method. Register/schema context lives under the
     * `@self` block; the `case` field filter sits at the top level as a
     * server-side equality match.
     *
     * @param string $caseId Case UUID.
     *
     * @return array<int, array<string, mixed>>|null The records, or null when
     *                                               the history cannot be read.
     *
     * @spec openspec/specs/status-transition-engine/spec.md
     */
    public function findStatusRecords(string $caseId): ?array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return null;
        }

        $register     = $this->settingsService->getConfigValue(key: 'register');
        $recordSchema = $this->settingsService->getConfigValue(key: 'status_record_schema');
        if ($register === '' || $recordSchema === '') {
            return null;
        }

        try {
            $records = $objectService->searchObjects(
                [
                    '@self' => [
                        'register' => (int) $register,
                        'schema'   => (int) $recordSchema,
                    ],
                    'case'  => $caseId,
                ],
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'StatusTransitionService: replay searchObjects failed',
                ['exception' => $e->getMessage(), 'caseId' => $caseId],
            );
            return null;
        }//end try

        $recordList = [];
        if (is_array($records) === true) {
            $recordList = $records;
        }

        $list = [];
        foreach ($recordList as $record) {
            $list[] = $this->toArray(value: $record);
        }

        return $list;
    }//end findStatusRecords()

    /**
     * Look up a human-readable status name for the case-detail panel header.
     *
     * @param string $statusTypeId StatusType UUID.
     *
     * @return string The status name, or the empty string when unresolvable.
     *
     * @spec openspec/specs/status-transition-engine/spec.md
     */
    public function lookupStatusName(string $statusTypeId): string
    {
        if ($statusTypeId === '') {
            return '';
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return '';
        }

        $register         = $this->settingsService->getConfigValue(key: 'register');
        $statusTypeSchema = $this->settingsService->getConfigValue(key: 'status_type_schema');
        if ($register === '' || $statusTypeSchema === '') {
            return '';
        }

        try {
            $statusType = $this->toArray(value: $objectService->find($statusTypeId, register: $register, schema: $statusTypeSchema));
        } catch (\Throwable $e) {
            return '';
        }

        return (string) ($statusType['name'] ?? ($statusType['title'] ?? ''));
    }//end lookupStatusName()

    /**
     * Validate that a statusType belongs to the case's caseType.
     *
     * @param string $caseTypeId   CaseType UUID.
     * @param string $statusTypeId StatusType UUID.
     *
     * @return void
     *
     * @throws RuntimeException When the statusType is not a child of the caseType.
     *
     * @spec openspec/specs/status-transition-engine/spec.md
     */
    public function assertStatusBelongsToCaseType(string $caseTypeId, string $statusTypeId): void
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('storage_unavailable');
        }

        $register       = $this->settingsService->getConfigValue(key: 'register');
        $caseTypeSchema = $this->settingsService->getConfigValue(key: 'case_type_schema');
        $unconfigured   = in_array('', [$register, $caseTypeSchema, $caseTypeId, $statusTypeId], true);
        if ($unconfigured === true) {
            throw new RuntimeException('case_type_not_configured');
        }

        try {
            $caseType = $this->toArray(value: $objectService->find($caseTypeId, register: $register, schema: $caseTypeSchema));
        } catch (\Throwable $e) {
            throw new RuntimeException('case_type_not_found');
        }

        $statuses = $caseType['statusTypes'] ?? ($caseType['statusses'] ?? []);
        if (is_array($statuses) === false) {
            $statuses = [];
        }

        foreach ($statuses as $entry) {
            $id = (string) $entry;
            if (is_array($entry) === true) {
                $id = (string) ($entry['id'] ?? ($entry['uuid'] ?? ''));
            }

            if ($id === $statusTypeId) {
                return;
            }
        }

        throw new RuntimeException('status_type_not_in_case_type');
    }//end assertStatusBelongsToCaseType()

    /**
     * Coerce ObjectService results to an array.
     *
     * @param mixed $value Raw result.
     *
     * @return array<string, mixed> The coerced array, empty when uncoercible.
     *
     * @spec openspec/specs/status-transition-engine/spec.md
     */
    private function toArray(mixed $value): array
    {
        if (is_array($value) === true) {
            return $value;
        }

        if (is_object($value) === true) {
            if (method_exists($value, 'jsonSerialize') === true) {
                $serialized = $value->jsonSerialize();
                if (is_array($serialized) === true) {
                    return $serialized;
                }
            }
        }

        return [];
    }//end toArray()
}//end class
