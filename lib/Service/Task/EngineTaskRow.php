<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * One task of the engine, read back in the register's vocabulary.
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
 * Read an engine task entity into the array dossiq's callers speak.
 *
 * Moved out of {@see EngineTaskGateway} unchanged (method-decomposition slice
 * 8k): it reads only the entity it is handed, asking for each optional column
 * before calling its getter, so an older engine without the column reads as
 * empty instead of failing.
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class EngineTaskRow {

    /**
     * One engine task, in the register's vocabulary, or null when there is none.
     *
     * @param object|null $task The engine's task entity, or null.
     *
     * @return array<string, mixed>|null The task, or null.
     *
     * @spec openspec/changes/method-decomposition/tasks.md
     */
    public function toArray(?object $task): ?array {
        if ($task === null) {
            return null;
        }

        return [
            'id' => (string) $task->getUuid(),
            'title' => (string) $task->getTitle(),
            // The register's vocabulary, because the node speaks it. The
            // VALUES need no translation: Task::STATES is the same CMMN set
            // caseTask declared.
            'status' => (string) $task->getState(),
            'flowRun' => (string) ($task->getRunUuid() ?? ''),
            'flowNode' => (string) ($task->getNodeId() ?? ''),
            'assignee' => (string) ($task->getAssignee() ?? ''),
            // A typed list of {id, label, description, checked}, NOT a
            // string. `caseTask` held JSON in a string and the checklist
            // guard had to decode it; the entity removed that shape, so
            // this arrives ready to read.
            'checklist' => (($task->getChecklist() ?? [])),
        ] + self::engineColumnsOf(task: $task);
    }//end toArray()

    /**
     * The task's columns that an older engine may not have, each read only when it exists.
     *
     * @param object $task The engine's task entity.
     *
     * @return array<string, mixed> The case, due date, candidates and declaration.
     */
    private static function engineColumnsOf(object $task): array {
        return [
            // The case IS the object, and a completion has to know which
            // case it is on to run the effects the task declared against it
            // and to publish the files it was holding.
            //
            // 🔴 EACH GETTER IS ASKED FOR BEFORE IT IS CALLED. The engine's
            // task is resolved by string from an app that need not be
            // installed, and an OLDER openregister has fewer of these
            // columns: calling one it does not have is a fatal
            // `Call to undefined method`, in the middle of a read that was
            // working. Absent reads as "the engine does not answer that",
            // which is exactly what the surfaces already state.
            'objectUuid' => self::stringFrom(task: $task, getter: 'getObjectUuid'),
            'dueDate' => self::dateFrom(task: $task, getter: 'getDueAt'),
            // Who the task is OFFERED to, which is a different question from
            // who has it. An empty assignee with a candidate list is a task
            // waiting to be claimed; an empty assignee with no candidates is
            // a task waiting for somebody to notice it.
            'candidateGroups' => self::listFrom(task: $task, getter: 'getCandidateGroups'),
            'candidateUsers' => self::listFrom(task: $task, getter: 'getCandidateUsers'),
            // What dossiq declared on this task: `form` for the engine to
            // render, `dossiq.effects` for dossiq to run on completion.
            'metadata' => self::listFrom(task: $task, getter: 'getMetadata'),
        ];
    }//end engineColumnsOf()

    /**
     * Whether an engine task has the column a getter reads. OpenRegister's
     * Task is a Nextcloud Entity, whose column getters are magic (`__call`),
     * so a declared method and a declared property both count.
     *
     * @param object $task   The engine task.
     * @param string $getter The accessor, `get<Column>`.
     *
     * @return bool
     *
     * @spec openspec/specs/task-management/spec.md
     */
    private static function answers(object $task, string $getter): bool {
        if (method_exists($task, $getter) === true) {
            return true;
        }

        return str_starts_with($getter, 'get') === true && property_exists($task, lcfirst(substr($getter, 3))) === true;
    }//end answers()

    /**
     * One string column of an engine task, or '' when the engine has none.
     *
     * @param object $task   The engine task.
     * @param string $getter The accessor.
     *
     * @return string The value.
     *
     * @spec openspec/specs/task-management/spec.md
     */
    private static function stringFrom(object $task, string $getter): string {
        if (self::answers(task: $task, getter: $getter) === false) {
            return '';
        }

        return (string) ($task->{$getter}() ?? '');
    }//end stringFrom()

    /**
     * One date column of an engine task, ISO-formatted, or ''.
     *
     * @param object $task   The engine task.
     * @param string $getter The accessor.
     *
     * @return string The value.
     *
     * @spec openspec/specs/task-management/spec.md
     */
    private static function dateFrom(object $task, string $getter): string {
        if (self::answers(task: $task, getter: $getter) === false) {
            return '';
        }

        $value = $task->{$getter}();
        if (($value instanceof \DateTimeInterface) === false) {
            return (string) ($value ?? '');
        }

        return $value->format('c');
    }//end dateFrom()

    /**
     * One array column of an engine task, or [] when the engine has none.
     *
     * @param object $task   The engine task.
     * @param string $getter The accessor.
     *
     * @return array<mixed> The value.
     *
     * @spec openspec/specs/task-management/spec.md
     */
    private static function listFrom(object $task, string $getter): array {
        if (self::answers(task: $task, getter: $getter) === false) {
            return [];
        }

        $value = $task->{$getter}();
        if (is_array($value) === false) {
            return [];
        }

        return $value;
    }//end listFrom()
}//end class
