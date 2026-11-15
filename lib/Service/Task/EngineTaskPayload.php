<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What dossiq sends the task engine for one of its tasks.
 *
 * @category  Service
 * @package   OCA\Dossiq\Service\Task
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Task;


/**
 * Build the engine payload for one dossiq task.
 *
 * Moved out of {@see EngineTaskGateway} unchanged (method-decomposition slice
 * 8k): it reads only its arguments and calls nothing, so the gateway keeps
 * every call to the engine and this class keeps the field mapping. The
 * reasons for each field are on {@see EngineTaskGateway::toEnginePayload()}.
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class EngineTaskPayload {

    /**
     * The dossiq task fields copied as they are, by the engine field they go into.
     *
     * @var array<string, string>
     */
    private const COPIED_FIELDS = [
        'description'    => 'description',
        'assignee'       => 'assignee',
        'dueDate'        => 'dueAt',
        // NOTE: `completedDate` is deliberately absent. TaskBuilder
        // reads no `completedAt`, because the engine sets it through
        // the complete verb rather than accepting it as an assertion.
        // Mapping it would be a silent no-op, which is worse than a
        // stated gap: a backfilled completed task keeps its state but
        // loses the date it was completed on, and reconciliation has
        // to read that from the register row while both stores exist.
        'priority'       => 'priority',
        'workflowStepId' => 'workflowStepId',
        'flowRun'        => 'runUuid',
        'flowNode'       => 'nodeId',
    ];


    /**
     * The engine payload for one dossiq task.
     *
     * @param array<string, mixed> $task   The dossiq task.
     * @param string               $caseId The case this task is on.
     *
     * @return array<string, mixed> The engine payload.
     *
     * @spec openspec/changes/method-decomposition/tasks.md
     */
    public function build(array $task, string $caseId): array {
        $payload = [
            'title'   => (string)($task['title'] ?? ''),
            'appId'   => 'dossiq',
            'state'   => (string)($task['status'] ?? 'available'),
        ];
        $payload = $this->withKey(payload: $payload, task: $task);

        // The case IS the object. No typed case reference exists engine-side.
        if ($caseId !== '') {
            $payload['objectUuid'] = $caseId;
        }

        $payload = $this->withCandidates(payload: $this->withCopiedFields(payload: $payload, task: $task), task: $task);

        return $this->withChecklist(payload: $payload, task: $task);
    }//end build()

    /**
     * Stamp the payload with its engine key: the register row it came from, or its once-key.
     *
     * @param array<string, mixed> $payload The payload so far.
     * @param array<string, mixed> $task    The dossiq task.
     *
     * @return array<string, mixed> The payload, keyed when the task names a key.
     */
    private function withKey(array $payload, array $task): array {
        // The engine's external-reference field, stamped with the register
        // row this task came from. It is what makes the backfill idempotent
        // and what lets the two stores be reconciled while both exist.
        $sourceId = trim((string)($task['id'] ?? $task['uuid'] ?? ''));
        if ($sourceId !== '') {
            $payload['key'] = EngineTaskGateway::SOURCE_KEY_PREFIX . $sourceId;
        }

        // A task that may exist once per case carries its once-key instead.
        $onceKey = trim((string)($task['onceKey'] ?? ''));
        if ($onceKey !== '') {
            $payload['key'] = $onceKey;
        }

        return $payload;
    }//end withKey()

    /**
     * Copy every non-empty field of {@see self::COPIED_FIELDS} into the payload, trimmed.
     *
     * @param array<string, mixed> $payload The payload so far.
     * @param array<string, mixed> $task    The dossiq task.
     *
     * @return array<string, mixed> The payload, with the copied fields.
     */
    private function withCopiedFields(array $payload, array $task): array {
        foreach (self::COPIED_FIELDS as $from => $to) {
            $value = trim((string)($task[$from] ?? ''));
            if ($value !== '') {
                $payload[$to] = $value;
            }
        }

        return $payload;
    }//end withCopiedFields()

    /**
     * Offer the task to the case's team, then to whoever the case type declared.
     *
     * @param array<string, mixed> $payload The payload so far.
     * @param array<string, mixed> $task    The dossiq task.
     *
     * @return array<string, mixed> The payload, with its candidates.
     */
    private function withCandidates(array $payload, array $task): array {
        // One value into a list.
        $group = trim((string)($task['assigneeGroup'] ?? ''));
        if ($group !== '') {
            $payload['candidateGroups'] = [$group];
        }

        return $this->withDeclaration(payload: $payload, task: $task);
    }//end withCandidates()

    /**
     * Add the decoded checklist, when the task has a usable one.
     *
     * @param array<string, mixed> $payload The payload so far.
     * @param array<string, mixed> $task    The dossiq task.
     *
     * @return array<string, mixed> The payload, with its checklist.
     */
    private function withChecklist(array $payload, array $task): array {
        $checklist = $this->decodeChecklist(value: ($task['checklist'] ?? null));
        if ($checklist !== null) {
            $payload['checklist'] = $checklist;
        }

        return $payload;
    }//end withChecklist()

    /**
     * Carry the case type's per-task declaration into the engine payload.
     *
     * The DECLARED candidates are a different thing from the case's team: the
     * team is where work falls back to, the candidates are who the task is
     * offered to before anybody has it. A declared list wins, because an
     * administrator who named a team for this task meant that team and not
     * the case's.
     *
     * `metadata.form` is the engine's own room for a run-less task's form
     * declaration, read by its TaskFormResolver. `metadata.dossiq` is
     * dossiq's, read back when the task completes. Both are passed through
     * whole: translating them here would be a second description of the same
     * declaration, and the two would drift.
     *
     * @param array<string, mixed> $payload The payload so far.
     * @param array<string, mixed> $task    The dossiq task.
     *
     * @return array<string, mixed> The payload, with whatever was declared.
     *
     * @spec openspec/specs/process-step-configuration/spec.md
     */
    private function withDeclaration(array $payload, array $task): array {
        foreach (['candidateGroups', 'candidateUsers'] as $key) {
            $declared = ($task[$key] ?? null);
            if (self::isFilledList(value: $declared) === true) {
                $payload[$key] = array_values($declared);
            }
        }

        $metadata = ($task['metadata'] ?? null);
        if (self::isFilledList(value: $metadata) === true) {
            $payload['metadata'] = $metadata;
        }

        return $payload;
    }//end withDeclaration()

    /**
     * Whether a value is an array with at least one entry.
     *
     * @param mixed $value The value.
     *
     * @return bool True for a non-empty array.
     */
    private static function isFilledList(mixed $value): bool {
        return is_array($value) === true && $value !== [];
    }//end isFilledList()

    /**
     * Decode dossiq's JSON-string checklist into the typed array the engine
     * validates, or null when there is nothing usable.
     *
     * Null rather than an empty array: the engine treats an explicitly empty
     * checklist as a real value, and a task that never had one should not
     * arrive carrying one.
     *
     * @param mixed $value The stored checklist.
     *
     * @return array<int, mixed>|null The decoded checklist, or null.
     *
     * @spec openspec/changes/dossiq-duplication-to-abstractions/tasks.md
     */
    private function decodeChecklist(mixed $value): ?array {
        if (is_array($value) === true) {
            return self::filledOrNull(list: $value);
        }

        $raw = trim((string)($value ?? ''));
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded) === false) {
            return null;
        }

        return self::filledOrNull(list: $decoded);
    }//end decodeChecklist()

    /**
     * The list, or null when it is empty.
     *
     * @param array<int|string, mixed> $list The list.
     *
     * @return array<int|string, mixed>|null The list, or null.
     */
    private static function filledOrNull(array $list): ?array {
        if ($list === []) {
            return null;
        }

        return $list;
    }//end filledOrNull()
}//end class
