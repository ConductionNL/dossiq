<?php

/**
 * The engine task inbox, read-only.
 *
 * 🔴 SPLIT OUT OF EngineTaskGateway BECAUSE THAT CLASS SAT AT PHPMD'S
 * ExcessiveClassComplexity THRESHOLD OF EXACTLY 50, so any branch added
 * anywhere in it reddened `development` — a class you cannot safely edit is
 * debt regardless of how it reads.
 *
 * The seam was already there. EngineTaskGateway does two things: it WRITES
 * dossiq tasks into the engine, and it READS back which ones the engine
 * already holds so a re-run does not double them. Only the second half needs
 * the inbox service, the row shapes and the key format; the first half never
 * touches them.
 *
 * `EngineTaskGateway::existingKeysFor()` still exists and still answers the
 * same question — it delegates here. That is deliberate: every caller and
 * every test keeps working, so this split carries no blast radius.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dossiq-duplication-to-abstractions/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Task;

use OCA\Dossiq\Service\SettingsService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads the engine's task inbox, for one case or for one person.
 *
 * @spec openspec/specs/add-work-queue/spec.md#requirement-three-surfaces-one-rule
 */
class EngineTaskInbox {

    /**
     * The plumbing, built on first use.
     *
     * @var EngineInboxQuery|null
     */
    private ?EngineInboxQuery $query = null;

    /**
     * Constructor.
     *
     * @param SettingsService    $settings  Bridge to OpenRegister plus app config.
     * @param ContainerInterface $container The app container, for the optional inbox service.
     * @param LoggerInterface    $logger    Records a dedup read that could not be made.
     *
     * @return void
     */
    public function __construct(
        private readonly SettingsService $settings,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * The tasks assigned to one person, as plain arrays.
     *
     * Serves the PHP readers that used to query `caseTask` by assignee:
     * the work queue, the reassignment service and the KPI counts. One
     * method rather than one per caller, because the shape they each want
     * is the same and a second copy is the thing that drifts.
     *
     * `isTerminal: false` is passed to the ENGINE rather than filtered here.
     * Filtering a paged window client-side silently drops every open task
     * past the boundary, which is how a queue comes to look empty on a day
     * somebody has a hundred things to do.
     *
     * @param string  $actor The person whose work this is.
     * @param integer $limit How many rows at most.
     *
     * @return array<int, array<string, mixed>> The tasks.
     *
     * @spec openspec/changes/remove-casetask/tasks.md
     */
    public function openForAssignee(string $actor, int $limit = 200): array {
        if (trim($actor) === '') {
            return [];
        }

        return $this->mapped(
            criteria: [
                'uid' => $actor,
                'isAdmin' => true,
                'scope' => $this->query()->scope('SCOPE_ASSIGNED'),
                'isTerminal' => false,
            ],
            limit: $limit,
            failure: ['Dossiq: could not read the engine inbox for a person', ['actor' => $actor]]
        );
    }//end openForAssignee()

    /**
     * How many open tasks the instance holds, optionally due before an instant.
     *
     * `SCOPE_ALL` with `isAdmin`, unlike `countOpenForAssignee()`: the demo
     * caseload report asks what landed on the INSTANCE, not what landed on
     * one person, and the seed command turns a zero here into a failure.
     * Narrowing it to the caller would make the command's verdict depend on
     * whose account ran it.
     *
     * @param string      $actor     The acting identity.
     * @param string|null $dueBefore Only tasks due strictly before this instant.
     *
     * @return integer How many, or 0 when the read could not be made.
     *
     * @spec openspec/specs/dossiq-app-scaffold/spec.md
     */
    public function countOpenEverywhere(string $actor, ?string $dueBefore = null): int {
        return $this->query()->total(
            criteria: ([
                'uid' => $actor,
                'isAdmin' => true,
                'scope' => $this->query()->scope('SCOPE_ALL'),
                'isTerminal' => false,
            ] + $this->query()->dueWindow(after: null, before: $dueBefore)),
            failure: ['Dossiq: could not count the engine tasks on this instance', []]
        );
    }//end countOpenEverywhere()

    /**
     * Every task the engine holds against one case, as plain arrays.
     *
     * `SCOPE_ALL` and no terminality filter, unlike `openForAssignee()`:
     * the question a case surface asks is "what work has this case", and a
     * checklist that was ticked on a task somebody already completed is
     * still ticked. Narrowing to the caller's own tasks would make a guard
     * pass or fail depending on who triggered the transition.
     *
     * @param string  $caseId The case (object) uuid.
     * @param string  $actor  The acting identity.
     * @param integer $limit  How many rows at most.
     *
     * @return array<int, array<string, mixed>> The tasks.
     *
     * @spec openspec/specs/add-work-queue/spec.md#requirement-three-surfaces-one-rule
     */
    public function forCase(string $caseId, string $actor, int $limit = 200): array {
        if (trim($caseId) === '') {
            return [];
        }

        return $this->mapped(
            criteria: [
                'uid' => $actor,
                'isAdmin' => true,
                'scope' => $this->query()->scope('SCOPE_ALL'),
                'objectUuid' => $caseId,
            ],
            limit: $limit,
            failure: ['Dossiq: could not read the engine tasks for a case', ['case' => $caseId]]
        );
    }//end forCase()

    /**
     * How many open tasks one person holds, optionally inside a due window.
     *
     * Serves the dashboard's two task tiles. Both the terminality split and
     * the window are asked of the ENGINE: `caseTask` had no server-side
     * answer for "due today" at all, so dossiq read a page and derived it,
     * which is a count of the page rather than of the work.
     *
     * @param string      $actor     The person whose work this is.
     * @param string|null $dueAfter  Only tasks due at or after this instant.
     * @param string|null $dueBefore Only tasks due strictly before this instant.
     *
     * @return integer How many, or 0 when the read could not be made.
     *
     * @spec openspec/specs/dashboard/spec.md#REQ-DASH-001
     */
    public function countOpenForAssignee(
        string $actor,
        ?string $dueAfter = null,
        ?string $dueBefore = null,
    ): int {
        if (trim($actor) === '') {
            return 0;
        }

        return $this->query()->total(
            criteria: ([
                'uid' => $actor,
                'isAdmin' => true,
                'scope' => $this->query()->scope('SCOPE_ASSIGNED'),
                'isTerminal' => false,
            ] + $this->query()->dueWindow(after: $dueAfter, before: $dueBefore)),
            failure: ['Dossiq: could not count the engine inbox for a person', ['actor' => $actor]]
        );
    }//end countOpenForAssignee()


    /**
     * Why the last read on this instance answered nothing.
     *
     * @return string The engine's message, or '' when the read succeeded.
     *
     * @spec openspec/specs/status-transition-engine/spec.md
     */
    public function lastError(): string {
        return $this->query()->lastError();
    }//end lastError()

    /**
     * The plumbing this class asks its questions through.
     *
     * PROTECTED and lazy, so the constructor keeps the three arguments
     * every caller already passes and a test can substitute a query whose
     * `resolveInbox()` answers. OpenRegister is not installed in this
     * suite, so without a seam every read short-circuits to `[]` before it
     * does any work and a test would pass whatever the body did.
     *
     * @return EngineInboxQuery The query.
     *
     * @spec openspec/specs/add-work-queue/spec.md#requirement-three-surfaces-one-rule
     */
    protected function query(): EngineInboxQuery {
        if ($this->query === null) {
            $this->query = new EngineInboxQuery($this->settings, $this->container, $this->logger);
        }

        return $this->query;
    }//end query()

    /**
     * One inbox read, mapped into the register's vocabulary.
     *
     * @param array<string, mixed> $criteria Named arguments for the criteria.
     * @param integer              $limit    How many rows at most.
     * @param array{0: string, 1: array<string, mixed>} $failure Log message and context.
     *
     * @return array<int, array<string, mixed>> The tasks.
     *
     * @spec openspec/specs/add-work-queue/spec.md#requirement-three-surfaces-one-rule
     */
    private function mapped(array $criteria, int $limit, array $failure): array {
        $tasks = [];
        foreach ($this->query()->rows(criteria: $criteria, limit: $limit, failure: $failure) as $row) {
            $task = $this->asArray(row: $row);
            if ($task !== []) {
                $tasks[] = $task;
            }
        }

        return $tasks;
    }//end mapped()





    /**
     * One inbox row as a plain array, in the register's vocabulary.
     *
     * The callers read `status`, `dueDate` and `title`, which is what
     * `caseTask` called them. The VALUES need no translation: `Task::STATES`
     * is the same CMMN set. Translating names in one place beats teaching
     * four readers the engine's.
     *
     * @param mixed $row The inbox row.
     *
     * @return array<string, mixed> The task, or [] when unusable.
     *
     * @spec openspec/changes/remove-casetask/tasks.md
     */
    private function asArray(mixed $row): array {
        $get = static function (mixed $r, string $key, string $method): string {
            if (is_array($r) === true) {
                return (string) ($r[$key] ?? '');
            }

            if (is_object($r) === true && method_exists($r, $method) === true) {
                return (string) ($r->$method() ?? '');
            }

            return '';
        };

        $id = $get($row, 'uuid', 'getUuid');
        if ($id === '') {
            return [];
        }

        return [
            'id' => $id,
            'title' => $get($row, 'title', 'getTitle'),
            'status' => $get($row, 'state', 'getState'),
            'priority' => $get($row, 'priority', 'getPriority'),
            'dueDate' => $get($row, 'dueAt', 'getDueAt'),
            'case' => $get($row, 'objectUuid', 'getObjectUuid'),
            'assignee' => $get($row, 'assignee', 'getAssignee'),
            // Which status asked for this task. `toEnginePayload()` has always
            // written it and `Task` has always stored it, but this mapper
            // dropped it on the way back, so `StatusChecklist` could not tell
            // one status's tasks from another's and read none at all.
            'workflowStepId' => $get($row, 'workflowStepId', 'getWorkflowStepId'),
            // NOT through `$get`: the engine stores a typed list of
            // {id, label, description, checked} and casting that to a
            // string gives "Array". `caseTask` held JSON in a string,
            // which is the shape the entity exists to remove.
            'checklist' => $this->checklistOf(row: $row),
        ];
    }//end asArray()

    /**
     * The checklist on one row, as the typed list the engine stores.
     *
     * @param mixed $row The inbox row.
     *
     * @return array<int, mixed> The items, or [] when there are none.
     *
     * @spec openspec/specs/status-transition-engine/spec.md
     */
    private function checklistOf(mixed $row): array {
        $items = null;
        if (is_array($row) === true) {
            $items = ($row['checklist'] ?? null);
        } else if (is_object($row) === true && method_exists($row, 'getChecklist') === true) {
            $items = $row->getChecklist();
        }

        if (is_array($items) === false) {
            return [];
        }

        return $items;
    }//end checklistOf()

    /**
     * The source keys the engine already holds for one case.
     *
     * This is what makes re-running the backfill safe. Without it a second
     * run doubles every task: measured, 33 rows became 66.
     *
     * Queried per CASE rather than per task, because the engine's inbox
     * criteria filter on `objectUuid` and one query per case is a great deal
     * cheaper than one per task.
     *
     * @param string $caseId The case (object) uuid.
     * @param string $actor  The acting identity.
     *
     * @return array<string, true> The keys already present, as a set.
     *
     * @spec openspec/changes/dossiq-duplication-to-abstractions/tasks.md
     */
    public function existingKeysFor(string $caseId, string $actor): array {
        if (trim($caseId) === '') {
            return [];
        }

        // A failed dedup read must not stop the backfill: worst case the
        // operator sees duplicates and is told, which is better than a
        // migration that refuses to run.
        $rows = $this->query()->rows(
            criteria: [
                'uid' => $actor,
                'isAdmin' => true,
                'scope' => $this->query()->scope('SCOPE_ALL'),
                'objectUuid' => $caseId,
            ],
            limit: 500,
            failure: [
                'Dossiq: could not read existing engine tasks for a case; duplicates are possible',
                ['case' => $caseId],
            ]
        );

        $keys = [];
        foreach ($rows as $row) {
            $key = $this->keyOf(row: $row);
            if ($key !== '') {
                $keys[$key] = true;
            }
        }

        return $keys;
    }//end existingKeysFor()


    /**
     * The external key on one inbox row, in whichever shape it arrives.
     *
     * @param mixed $row The row.
     *
     * @return string The key, or ''.
     *
     * @spec openspec/changes/dossiq-duplication-to-abstractions/tasks.md
     */
    private function keyOf(mixed $row): string {
        if (is_array($row) === true) {
            return trim((string)($row['key'] ?? $row['taskKey'] ?? ''));
        }

        if (is_object($row) === true && method_exists($row, 'getTaskKey') === true) {
            return trim((string)$row->getTaskKey());
        }

        return '';
    }//end keyOf()

}//end class
