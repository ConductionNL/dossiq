<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Flow
 * @package   OCA\Dossiq\Flow
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Flow;

use JsonSerializable;
use OCA\Dossiq\Service\Task\EngineTaskGateway;
use OCP\IUserSession;
use OCP\AppFramework\Db\DoesNotExistException;
use RuntimeException;

/**
 * The task rows an ask writes and reads back.
 *
 * WHY THIS IS ITS OWN CLASS. `dossiq.askPerson` became a two-way conversation
 * with storage when it learned to recover: it writes a task once and then reads
 * that row on every re-entry, and both halves have to agree about where the row
 * lives, what identifies it, and which shapes the duck-typed object service can
 * hand back. Keeping the pair together is what stops the write and the read
 * drifting apart; leaving them inline pushed the node past its complexity
 * budget, which was the measurement saying the same thing.
 *
 * The duck-typing that used to live here moved with the storage:
 * `EngineTaskGateway` resolves OpenRegister's task service by name, because
 * OpenRegister is an optional runtime dependency and its classes cannot be
 * type-hinted. What follows describes that seam, not this class.
 *
 * HISTORICAL. `SettingsService::getObjectService()` resolved
 * OpenRegister's service as `?object`, because dossiq stays installable without
 * it. Everything here therefore accepts what that service really returns rather
 * than what a caller assumes — the assumption that a save returned an array is
 * exactly what once left every created task orphaned.
 *
 * @spec openspec/changes/askperson-recovers-a-missed-answer/specs/case-flow-human-steps/spec.md
 */
class AskPersonTaskStore {


    /**
     * Constructor.
     *
     * @param EngineTaskGateway $engineTasks     The seam onto OpenRegister's task engine.
     * @param IUserSession      $userSession     The acting identity the engine records.
     *
     * @return void
     *
     * @spec openspec/changes/askperson-recovers-a-missed-answer/specs/case-flow-human-steps/spec.md
     */
    public function __construct(
        private readonly EngineTaskGateway $engineTasks,
        private readonly IUserSession $userSession,
    ) {

    }//end __construct()


    /**
     * Who the engine records as having raised this task.
     *
     * The session's user, and NOTHING when there is none. The engine is
     * fail-closed and refuses a verb with no acting identity, which is the
     * right outcome here: a task raised under a guessed identity is worse
     * than a run that stalls and says why, because the guess is what the
     * audit trail will show for ever.
     *
     * @return string|null The uid, or null.
     *
     * @spec openspec/changes/remove-casetask/tasks.md
     */
    private function actor(): ?string {
        $uid = $this->userSession->getUser()?->getUID();

        if ($uid === null || trim($uid) === '') {
            return null;
        }

        return $uid;
    }//end actor()


    /**
     * Write the task and return the id the run must remember.
     *
     * The write runs under the flow run's `runAs` identity because the
     * engine's RegistryStepDispatcher executes every contributed node inside
     * `ObjectService::runAs()` (openregister#3332) — the seam that fixed the
     * 'Anonymous' refusal which stopped the seeded case flow live.
     *
     * @param array $task The task to persist.
     *
     * @return string The created task's id.
     *
     * @throws RuntimeException When storage is unavailable, unconfigured, or the
     *                          written task cannot be identified.
     *
     * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
     */
    public function create(array $task): string {
        $caseId = trim((string) ($task['case'] ?? ''));

        // The engine, not the register. `dossiq.askPerson` asks a person a
        // question and waits for the answer; the answer now arrives as
        // `TaskTerminalEvent` from the engine, so the task it waits on has
        // to be an engine task or nothing will ever wake the run.
        //
        // `trusted: true` because this is an in-process caller with no
        // session of its own: the flow engine runs it as the run's acting
        // identity, and `create()` (the HTTP path) would pin the requester
        // to whoever happened to trigger the transition.
        $taskId = $this->engineTasks->mirrorImport(
            task: $task,
            caseId: $caseId,
            actor: $this->actor()
        );
        if ($taskId === '') {
            // A task that was written but cannot be identified is worse than
            // none: the slot would stay empty, so the next heartbeat writes
            // another, and the run accumulates duplicates nobody asked for.
            throw new RuntimeException('dossiq.askPerson could not identify the task it created');
        }

        return $taskId;

    }//end create()


    /**
     * The task carrying this id, or null when that row is gone.
     *
     * A MISSING row and an UNREADABLE store are different answers and the
     * caller treats them differently, so only the miss is turned into null;
     * everything else propagates and buys the run another heartbeat.
     *
     * @param string $taskId The task id held in the resume slot.
     *
     * @return array|null The task, or null when no row carries that id.
     *
     * @throws RuntimeException When storage is unavailable, unconfigured, or
     *                          answers with something that is not an object.
     *
     * @spec openspec/changes/askperson-recovers-a-missed-answer/specs/case-flow-human-steps/spec.md
     */
    public function find(string $taskId): ?array {
        try {
            $found = $this->engineTasks->find(taskId: $taskId);
        } catch (DoesNotExistException) {
            return null;
        }

        if ($found === null) {
            return null;
        }

        if ($found === null) {
            return null;
        }

        return $this->asTask(found: $found, taskId: $taskId);

    }//end find()






    /**
     * The task the object service answered with, as an array.
     *
     * @param mixed  $found  Whatever the read returned.
     * @param string $taskId The id that was asked for, for the message.
     *
     * @return array The task.
     *
     * @throws RuntimeException When the answer is not something a task can be read from.
     */
    private function asTask(mixed $found, string $taskId): array {
        if (is_object($found) === true && method_exists($found, 'getObject') === true) {
            $found = $found->getObject();
        } else if (is_object($found) === true && ($found instanceof JsonSerializable) === true) {
            $found = $found->jsonSerialize();
        }

        if (is_array($found) === false) {
            throw new RuntimeException(
                sprintf('dossiq.askPerson could not read task %s as an object', $taskId)
            );
        }

        return $found;

    }//end asTask()




}//end class
