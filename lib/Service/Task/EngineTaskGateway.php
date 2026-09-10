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
     * The app config key that turns the engine write on.
     *
     * Absent or false means dossiq writes only its register object, which is
     * exactly today's behaviour. The flag exists so a bad engine write is one
     * setting away from off rather than one deploy.
     *
     * @var string
     */
    public const FLAG_ENGINE_WRITE = 'task_engine_write';

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
     * @psalm-suppress UndefinedClass `OCA\OpenRegister\Db\TaskInboxCriteria`
     *   is another APP's class, reached by name. It exists, in
     *   openregister/lib/Db/TaskInboxCriteria.php, but dossiq's psalm run has
     *   openregister nowhere on its include path and never will -- OpenRegister
     *   is a separate app that need not be installed at all. `class_exists()`
     *   and `container->get()` further down take the same kind of name as a
     *   plain string and psalm never resolves them; `new $criteriaClass(...)`
     *   is different, because psalm folds the literal back into a class
     *   reference and demands it at ANALYSIS time. That is the tool being
     *   wrong about a deliberately runtime-resolved binding, not a finding.
     *   The catch below is what actually handles the class being absent.
     *
     * @spec openspec/changes/dossiq-duplication-to-abstractions/tasks.md
     */
    public function existingKeysFor(string $caseId, string $actor): array {
        // NOTE: no `class_exists` guard here, deliberately. The catch below
        // already handles an absent class -- a missing class throws `Error`,
        // which is a `Throwable` -- and it LOGS, where a guard would return
        // silently. It also cost a decision point this class cannot afford:
        // phpmd already scores it at the ExcessiveClassComplexity threshold
        // of 50, so any added branch reddens `development`.
        $inbox = $this->resolveInbox();
        if ($inbox === null || $caseId === '') {
            return [];
        }

        $criteriaClass = 'OCA\OpenRegister\Db\TaskInboxCriteria';

        try {
            $criteria = new $criteriaClass(
                uid: $actor,
                isAdmin: true,
                scope: $criteriaClass::SCOPE_ALL,
                objectUuid: $caseId,
            );

            $rows = $inbox->inbox($criteria, 500, 0);
        } catch (Throwable $e) {
            // A failed dedup read must not stop the backfill: worst case the
            // operator sees duplicates and is told, which is better than a
            // migration that refuses to run.
            $this->logger->warning(
                'Dossiq: could not read existing engine tasks for a case; duplicates are possible',
                ['exception' => $e->getMessage(), 'case' => $caseId]
            );

            return [];
        }//end try

        $keys = [];
        foreach ($this->rowsOf(value: $rows) as $row) {
            $key = $this->keyOf(row: $row);
            if ($key !== '') {
                $keys[$key] = true;
            }
        }

        return $keys;
    }//end existingKeysFor()

    /**
     * Pull the rows out of whichever envelope the inbox returned.
     *
     * @param mixed $value The inbox response.
     *
     * @return array<int, mixed> The rows.
     *
     * @spec openspec/changes/dossiq-duplication-to-abstractions/tasks.md
     */
    private function rowsOf(mixed $value): array {
        if (is_array($value) === false) {
            return [];
        }

        foreach (['results', 'tasks', 'items'] as $envelope) {
            if (isset($value[$envelope]) === true && is_array($value[$envelope]) === true) {
                return array_values($value[$envelope]);
            }
        }

        return array_values($value);
    }//end rowsOf()

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

    /**
     * Resolve OpenRegister's task inbox service, or null.
     *
     * @return object|null The service, or null when unavailable.
     *
     * @psalm-suppress MixedReturnStatement
     * @psalm-suppress MixedInferredReturnType
     *
     * @spec openspec/changes/dossiq-duplication-to-abstractions/tasks.md
     */
    protected function resolveInbox(): ?object {
        $className = 'OCA\OpenRegister\Service\Task\TaskInboxService';
        if ($this->settings->isOpenRegisterAvailable() === false || class_exists($className) === false) {
            return null;
        }

        try {
            return $this->container->get($className);
        } catch (Throwable $e) {
            return null;
        }
    }//end resolveInbox()

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
     * Whether the engine write is switched on AND reachable.
     *
     * Both halves matter and they fail differently: the flag being off is a
     * decision, the service being absent is a defect. Callers only need the
     * boolean; {@see unavailableReason()} is what the log gets.
     *
     * @return boolean True when a task written here will reach the engine.
     *
     * @spec openspec/changes/dossiq-duplication-to-abstractions/tasks.md
     */
    public function isEnabled(): bool {
        if ($this->settings->getConfigValue(key: self::FLAG_ENGINE_WRITE) !== '1') {
            return false;
        }

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
     * Mirror a dossiq task into the engine.
     *
     * Returns the engine task's uuid, or '' when nothing was written. An empty
     * return is NOT an error the caller should act on: the flag may simply be
     * off. Failures are logged here and swallowed, per the dual-run rule.
     *
     * @param array<string, mixed> $task     The dossiq task, in `caseTask` shape.
     * @param string               $caseId   The case this task is on.
     * @param string|null          $actor    The acting user, or null.
     * @param boolean              $trusted  Use the engine's trusted import path.
     *
     * @return string The engine task uuid, or '' when not written.
     *
     * @spec openspec/changes/dossiq-duplication-to-abstractions/tasks.md
     */
    public function mirrorCreate(array $task, string $caseId, ?string $actor, bool $trusted = false): string {
        if ($this->isEnabled() === false) {
            $reason = $this->unavailableReason();
            if ($reason !== '' && $this->settings->getConfigValue(key: self::FLAG_ENGINE_WRITE) === '1') {
                // Asked for, and could not be done. This is the line that
                // makes a silent namespace rename loud.
                $this->logger->warning('Dossiq: engine task write is ON but unavailable', ['reason' => $reason]);
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
            $created = match ($trusted) {
                true => $service->import(data: $payload, actor: $actor),
                false => $service->create(data: $payload, actor: $actor),
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
    }//end mirrorCreate()

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
