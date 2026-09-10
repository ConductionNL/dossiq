<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The one seam between dossiq's tasks and OpenRegister's task engine.
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

use OCA\Dossiq\Service\SettingsService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Write dossiq's tasks into OpenRegister's task engine.
 *
 * WHY THIS EXISTS
 * ---------------
 * Dossiq's `caseTask` is one of the 23 task shapes OpenRegister's 2026-08-22
 * inventory found across the fleet, and it is named in that inventory by
 * file. The engine's `Task` carries everything `caseTask` carries and forty
 * columns more, including the `responses` and `template_snapshot` that give a
 * task a FORM. Dossiq cannot record what somebody answered today; the engine
 * has been able to for weeks.
 *
 * This class is the whole seam. Nothing else in dossiq talks to the engine's
 * task API, so when the register-backed `caseTask` is finally removed there is
 * one file to change and not fifty-seven.
 *
 * DUAL-RUN, NOT A SWITCH
 * ----------------------
 * Migration step 3 of the programme's D-1: write to both stores, read from
 * one, behind a flag. This class owns the ENGINE half only. The caller keeps
 * writing the register object exactly as before, and a failure here must never
 * fail the caller: a case transition that already created its register task
 * has done its job, and refusing it because a shadow write failed would turn a
 * migration into an outage.
 *
 * So every method here returns a result rather than throwing, and logs.
 *
 * WHY THE SERVICE IS RESOLVED LAZILY AND BY STRING
 * ------------------------------------------------
 * OpenRegister is an optional runtime dependency, so its classes cannot be
 * type-hinted in a constructor: dossiq must load on an instance where it is
 * absent. This mirrors `SettingsService::getObjectService()` exactly, which is
 * the pattern every other OpenRegister seam in this app already uses.
 *
 * 🔴 The string is a duck-typed lookup, which fails SILENTLY when wrong. If
 * OpenRegister's namespace ever moves, `class_exists` returns false, this
 * gateway reports unavailable, and every task quietly stops reaching the
 * engine with nothing in the log to say a rename happened. That is why
 * {@see unavailableReason()} distinguishes "app not installed" from "class not
 * found": the second is a rename, not a configuration.
 *
 * @spec openspec/changes/dossiq-duplication-to-abstractions/tasks.md
 */
class EngineTaskGateway {

    /**
     * OpenRegister's task service, by name.
     *
     * @var string
     */
    private const TASK_SERVICE = 'OCA\OpenRegister\Service\Task\TaskService';

    /**
     * The external key an engine task carries for a dossiq register task.
     *
     * Namespaced, because `task_key` is a shared external-reference column
     * and a bare uuid would collide with whatever another app stamps there.
     *
     * @param string $registerTaskId The register task's uuid.
     *
     * @return string The key.
     *
     * @spec openspec/changes/dossiq-duplication-to-abstractions/tasks.md
     */
    public static function sourceKey(string $registerTaskId): string {
        return 'dossiq:caseTask:' . $registerTaskId;
    }//end sourceKey()





    /**
     * The inbox reader, or null until first use.
     *
     * @var EngineTaskInbox|null
     */
    private ?EngineTaskInbox $inboxReader = null;

    /**
     * The most recent engine failure, for callers that report rather than log.
     *
     * @var string
     */
    private string $lastError = '';

    /**
     * The most recent engine failure message, or ''.
     *
     * @return string The message.
     *
     * @spec openspec/changes/dossiq-duplication-to-abstractions/tasks.md
     */
    public function lastError(): string {
        return $this->lastError;
    }//end lastError()

    /**
     * Constructor.
     *
     * The container is injected here rather than another resolver being added
     * to SettingsService. That class sits at PHPMD's complexity ceiling: a
     * fourth `get()` wrapper took it from 50 to 52 and failed the gate. It is
     * also the wrong home. This seam is TEMPORARY and is deleted with the
     * caseTask migration, so it owns its own resolution and leaves nothing
     * behind in a class that outlives it.
     *
     * @param SettingsService    $settings  The dossiq settings seam.
     * @param ContainerInterface $container The DI container.
     * @param LoggerInterface    $logger    The logger.
     */
    public function __construct(
        private readonly SettingsService $settings,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * The source keys the engine already holds for one case.
     *
     * Delegates to {@see EngineTaskInbox}, which is where the inbox service,
     * the row shapes and the key extraction now live. Kept on this class so
     * every existing caller and test keeps working — the split was made to get
     * this class off phpmd's complexity threshold, not to move its API.
     *
     * @param string $caseId The case (object) uuid.
     * @param string $actor  The acting identity.
     *
     * @return array<string, true> The keys already present, as a set.
     *
     * @spec openspec/changes/dossiq-duplication-to-abstractions/tasks.md
     */
    public function existingKeysFor(string $caseId, string $actor): array {
        return $this->inbox()->existingKeysFor(caseId: $caseId, actor: $actor);
    }//end existingKeysFor()

    /**
     * The inbox reader, built on first use.
     *
     * Constructed rather than injected so this class's constructor signature —
     * and therefore every test that builds it — is unchanged by the split.
     *
     * @return EngineTaskInbox The reader.
     *
     * @spec openspec/changes/dossiq-duplication-to-abstractions/tasks.md
     */
    private function inbox(): EngineTaskInbox {
        if ($this->inboxReader === null) {
            $this->inboxReader = new EngineTaskInbox($this->settings, $this->container, $this->logger);
        }

        return $this->inboxReader;
    }//end inbox()

    /**
     * Whether a task written here will reach the engine.
     *
     * 🔴 THE `task_engine_write` FLAG IS GONE, AND LEAVING IT COST A FEATURE.
     *
     * It existed for the dual-run, when a task was written to BOTH the
     * `caseTask` register object and the engine, and the flag decided whether
     * the engine half also happened. dossiq#2363 removed the register write,
     * and `remove-casetask/tasks.md` 3.1 says in as many words that "the
     * `task_engine_write` flag goes with the dual-run". The write was removed
     * and the flag was not, which left the engine as the ONLY store behind a
     * switch that nothing ever set: it appeared three times in the whole repo,
     * as this constant and two test docblocks. No migration, repair step,
     * admin setting or CI command wrote it, and `getConfigValue` defaults to
     * `''`.
     *
     * So every write was refused while the reads had already moved. Measured
     * on development 2026-09-10: the six read surfaces #2357 moved all showed
     * "No open tasks on this case" on cases that had them, and, once #2363
     * made the engine the only store, EVERY status transition carrying a
     * createTask action failed with `create_task_failed`. An e2e spec drove a
     * real transition, saw the register rows appear, and read the pane's empty
     * state in the same test.
     *
     * Reachability is still a real question and is still asked. That half was
     * never the problem: a missing service is a defect and says so through
     * {@see unavailableReason()}.
     *
     * @return boolean True when a task written here will reach the engine.
     *
     * @spec openspec/changes/remove-casetask/tasks.md
     */
    public function isEnabled(): bool {
        return $this->resolveService() !== null;
    }//end isEnabled()

    /**
     * Why the engine is not reachable, for the log. Empty when it is.
     *
     * Distinguishes the three cases deliberately. "not installed" is an
     * instance without OpenRegister and is not a defect. "class not found" on
     * an instance that HAS OpenRegister means the namespace moved and every
     * task is silently missing the engine, which is the failure mode a
     * duck-typed lookup hides.
     *
     * @return string The reason, or '' when reachable.
     *
     * @spec openspec/changes/dossiq-duplication-to-abstractions/tasks.md
     */
    public function unavailableReason(): string {
        if ($this->settings->isOpenRegisterAvailable() === false) {
            return 'openregister is not installed or not enabled';
        }

        if (class_exists(self::TASK_SERVICE) === false) {
            return sprintf(
                'openregister is installed but %s does not exist: the namespace moved and dossiq tasks are NOT reaching the engine',
                self::TASK_SERVICE
            );
        }

        if ($this->resolveService() === null) {
            return 'the container could not construct the task service';
        }

        return '';
    }//end unavailableReason()

    /**
     * The engine verb for a trusted import.
     *
     * @var string
     */
    private const VERB_IMPORT = 'import';

    /**
     * Mirror a task the engine should VALIDATE, the ordinary path.
     *
     * @param array<string, mixed> $task   The dossiq task, in `caseTask` shape.
     * @param string               $caseId The case this task is on.
     * @param string|null          $actor  The acting user, or null.
     *
     * @return string The engine task uuid, or '' when not written.
     *
     * @spec openspec/changes/dossiq-duplication-to-abstractions/tasks.md
     */
    public function mirrorCreate(array $task, string $caseId, ?string $actor): string {
        return $this->mirror(task: $task, caseId: $caseId, actor: $actor, verb: 'create');
    }//end mirrorCreate()

    /**
     * Mirror a task through the engine's TRUSTED import path.
     *
     * 🔴 A BACKFILL IS THE EXCEPTION THE ENGINE ALREADY ACCOUNTS FOR. Most of
     * dossiq's existing tasks are `completed`, and `create()` refuses them:
     * measured, 33 of 33 failed with "A task cannot be created in terminal
     * state 'completed'". `import()` is the engine's own documented path for
     * "a completed approval carried over from a legacy shape".
     *
     * A separate method rather than a `$trusted` flag, because the two are not
     * one operation with a switch: one asks the engine to validate and the
     * other asks it not to. A caller should have to name which it wants.
     *
     * @param array<string, mixed> $task   The dossiq task, in `caseTask` shape.
     * @param string               $caseId The case this task is on.
     * @param string|null          $actor  The acting user, or null.
     *
     * @return string The engine task uuid, or '' when not written.
     *
     * @spec openspec/changes/dossiq-duplication-to-abstractions/tasks.md
     */
    public function mirrorImport(array $task, string $caseId, ?string $actor): string {
        return $this->mirror(task: $task, caseId: $caseId, actor: $actor, verb: self::VERB_IMPORT);
    }//end mirrorImport()

    /**
     * Mirror a dossiq task into the engine.
     *
     * Returns the engine task's uuid, or '' when nothing was written. Since the
     * dual-run ended, '' means the engine could not be REACHED, which is a
     * defect rather than a setting, and `CreateTaskHandler` is right to fail
     * the transition on it. It used to also mean "the flag is off", which is
     * why that handler's failure path fired on every instance. Failures are
     * logged here as well as returned.
     *
     * @param array<string, mixed> $task     The dossiq task, in `caseTask` shape.
     * @param string               $caseId   The case this task is on.
     * @param string|null          $actor    The acting user, or null.
     * @param string               $verb     `create` (the engine validates) or `import` (trusted).
     *
     * @return string The engine task uuid, or '' when not written.
     *
     * @spec openspec/changes/dossiq-duplication-to-abstractions/tasks.md
     */
    private function mirror(array $task, string $caseId, ?string $actor, string $verb): string {
        if ($this->isEnabled() === false) {
            $reason = $this->unavailableReason();
            if ($reason !== '') {
                // Asked for, and could not be done. This is the line that
                // makes a silent namespace rename loud.
                //
                // It used to be guarded by `task_engine_write === '1'` as well,
                // which meant it never fired: the flag was never set. With the
                // flag gone there is no such thing as a write that was not
                // asked for, so an unreachable engine is always worth saying.
                $this->logger->warning('Dossiq: the engine task write is unavailable', ['reason' => $reason]);
            }

            return '';
        }

        try {
            $service = $this->resolveService();
            $payload = $this->toEnginePayload(task: $task, caseId: $caseId);

            // `create()` is the HTTP path and refuses a task born in a
            // terminal state, which is right: a task reaches `completed`
            // through a lifecycle verb, not by being asserted into it.
            //
            // A BACKFILL is the exception the engine already accounts for.
            // Most of dossiq's existing tasks are `completed`, and refusing
            // them would migrate only the open ones: measured, 33 of 33
            // failed with "A task cannot be created in terminal state
            // 'completed'". `import()` is the engine's own trusted path,
            // documented for "a completed approval carried over from a legacy
            // shape", which is exactly this.
            $created = match ($verb) {
                self::VERB_IMPORT => $service->import(data: $payload, actor: $actor),
                default => $service->create(data: $payload, actor: $actor),
            };

            return (string)$created->getUuid();
        } catch (Throwable $e) {
            // Swallowed on purpose. The register task already exists and the
            // transition succeeded; a failed shadow write must not undo that.
            //
            // The message is also KEPT, because "see the log" is not a usable
            // instruction on an instance whose nextcloud.log is approaching a
            // gigabyte. The backfill command reports the first one it sees.
            $this->lastError = $e->getMessage();

            $this->logger->error(
                'Dossiq: could not mirror a task into the engine',
                ['exception' => $e->getMessage(), 'case' => $caseId]
            );

            return '';
        }//end try
    }//end mirror()

    /**
     * Hand one task to a different person.
     *
     * 🔑 A VERB, NOT A FIELD WRITE. `caseTask` was reassigned by setting
     * `assignee` and appending a hand-rolled entry to an `activity` array,
     * and that entry was the only record the transfer ever left. The engine
     * has `reassign` as a first-class lifecycle verb: it writes the
     * assignee, stamps the acting identity, and appends to `oc_openregister_
     * task_audit` itself, so the audit is the engine's rather than a JSON
     * blob a reader has to know how to decode.
     *
     * @param string      $taskId   The task to hand over.
     * @param string      $assignee Who receives it.
     * @param string|null $actor    The acting identity the engine records.
     *
     * @return boolean Whether the engine accepted it.
     *
     * @spec openspec/specs/handler-vervanging-waarneming/spec.md
     */
    public function reassign(string $taskId, string $assignee, ?string $actor): bool {
        $id = trim($taskId);
        if ($id === '' || trim($assignee) === '' || $this->isEnabled() === false) {
            return false;
        }

        try {
            $this->resolveService()?->reassign($id, trim($assignee), $actor);

            return true;
        } catch (Throwable $e) {
            // Per ITEM, not per batch. A bulk reassignment reports one row
            // per task and stays re-runnable, so one refusal must not take
            // the other ninety-nine with it.
            $this->lastError = $e->getMessage();

            $this->logger->warning(
                'Dossiq: the engine refused a task reassignment',
                ['exception' => $e->getMessage(), 'task' => $id]
            );

            return false;
        }
    }//end reassign()

    /**
     * Translate a `caseTask` into the engine's create payload.
     *
     * The map is 1:1 for almost everything, which is the finding that made
     * this migration cheap: `state` takes the SAME CMMN vocabulary dossiq uses
     * (available / active / completed / terminated / disabled) and `priority`
     * takes the same four words. Only three properties change shape:
     *
     *   - `case` becomes the object triple. The case IS the object: the engine
     *     stores `object_uuid` and never a typed case reference, because
     *     OpenRegister has no case entity by design.
     *   - `assigneeGroup` is one value; `candidate_groups` is a list.
     *   - `blocksCase` has no column at all. It becomes a typed relation row,
     *     which this method cannot write, so it is deliberately dropped here
     *     and handled by the caller when that step lands.
     *
     * `checklist` widens rather than narrows: dossiq stores a JSON-encoded
     * STRING, the engine stores real JSON, so it is decoded on the way in. A
     * string that does not decode is dropped rather than passed through, since
     * the engine validates the checklist as a typed array and would refuse the
     * whole create over it.
     *
     * @param array<string, mixed> $task   The dossiq task.
     * @param string               $caseId The case this task is on.
     *
     * @return array<string, mixed> The engine payload.
     *
     * @spec openspec/changes/dossiq-duplication-to-abstractions/tasks.md
     */
    public function toEnginePayload(array $task, string $caseId): array {
        $payload = [
            'title'   => (string)($task['title'] ?? ''),
            'appId'   => 'dossiq',
            'state'   => (string)($task['status'] ?? 'available'),
        ];

        // The engine's external-reference field, stamped with the register
        // row this task came from. It is what makes the backfill idempotent
        // and what lets the two stores be reconciled while both exist.
        $sourceId = trim((string)($task['id'] ?? $task['uuid'] ?? ''));
        if ($sourceId !== '') {
            $payload['key'] = self::sourceKey(registerTaskId: $sourceId);
        }

        // The case IS the object. No typed case reference exists engine-side.
        if ($caseId !== '') {
            $payload['objectUuid'] = $caseId;
        }

        foreach (
            [
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
            ] as $from => $to
        ) {
            $value = trim((string)($task[$from] ?? ''));
            if ($value !== '') {
                $payload[$to] = $value;
            }
        }

        // One value into a list.
        $group = trim((string)($task['assigneeGroup'] ?? ''));
        if ($group !== '') {
            $payload['candidateGroups'] = [$group];
        }

        $checklist = $this->decodeChecklist(value: ($task['checklist'] ?? null));
        if ($checklist !== null) {
            $payload['checklist'] = $checklist;
        }

        return $payload;
    }//end toEnginePayload()

    /**
     * Read one engine task, as a plain array, or null when it is gone.
     *
     * The array shape is deliberate: `AskPersonTaskStore` hands what it
     * reads to `DossiqAskPersonNode`, which asks about `status` and
     * `flowRun` in the register's vocabulary. Translating here keeps that
     * vocabulary in ONE place rather than spreading the engine's names
     * through the node and its two heartbeat-recovery tests.
     *
     * @param string $taskId The engine task uuid.
     *
     * @return array<string, mixed>|null The task, or null.
     *
     * @spec openspec/changes/remove-casetask/tasks.md
     */
    public function find(string $taskId): ?array {
        $id = trim($taskId);
        if ($id === '' || $this->isEnabled() === false) {
            return null;
        }

        try {
            $task = $this->resolveService()?->get($id);
        } catch (Throwable $e) {
            // A MISSING task and an UNREADABLE engine are different answers
            // and the caller treats them differently, so only the miss
            // becomes null here; the caller turns an exception into another
            // heartbeat rather than into a lost run.
            $this->lastError = $e->getMessage();

            return null;
        }

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
        ];
    }//end find()

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
            if ($value === []) {
                return null;
            }

            return $value;
        }

        $raw = trim((string)($value ?? ''));
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded) === false || $decoded === []) {
            return null;
        }

        return $decoded;
    }//end decodeChecklist()

    /**
     * Resolve OpenRegister's task service, or null.
     *
     * PROTECTED, not private, and that is a testing seam rather than an
     * accident. OpenRegister is not autoloadable in dossiq's unit suite, so
     * `class_exists()` below is always false there and every path that needs
     * a live service short-circuits before reaching it. A test that mocks the
     * service and asserts on `mirrorCreate()` would therefore pass whatever
     * the body did: it was mutation-checked, the rethrow mutation did NOT
     * redden it, and this seam is the fix.
     *
     * @return object|null The service, or null when unavailable.
     *
     * @psalm-suppress MixedReturnStatement
     * @psalm-suppress MixedInferredReturnType
     *
     * @spec openspec/changes/dossiq-duplication-to-abstractions/tasks.md
     */
    protected function resolveService(): ?object {
        if ($this->settings->isOpenRegisterAvailable() === false) {
            return null;
        }

        if (class_exists(self::TASK_SERVICE) === false) {
            return null;
        }

        try {
            return $this->container->get(self::TASK_SERVICE);
        } catch (Throwable $e) {
            $this->logger->error(
                'Dossiq: could not resolve the OpenRegister task service',
                ['exception' => $e->getMessage()]
            );

            return null;
        }
    }//end resolveService()
}//end class
