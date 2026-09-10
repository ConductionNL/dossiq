<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Backfill existing `caseTask` rows into OpenRegister's task engine.
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
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Copy the `caseTask` rows that already exist into the engine.
 *
 * WHY A BACKFILL AND NOT JUST THE MIRROR
 * --------------------------------------
 * {@see EngineTaskGateway} mirrors tasks as they are CREATED. Every task that
 * already exists would stay invisible to the engine, so the two stores could
 * never be compared and the read side could never be switched: an inbox that
 * shows only tasks made since Tuesday is worse than one that shows none,
 * because it looks like it works.
 *
 * IDEMPOTENT, BY STAMPING THE SOURCE ROW'S ID
 * -------------------------------------------
 * Re-running must not double every task. It did: the first cut claimed
 * idempotency in this very docblock and had none, and a second run took the
 * engine from 33 dossiq tasks to 66.
 *
 * Every mirrored task now carries `taskKey = dossiq:caseTask:<register uuid>`,
 * the engine's own external-reference field. Before writing, the engine is
 * asked which keys it already holds for that CASE (one query per case, not
 * per task, since the inbox criteria filter on `objectUuid`) and a key it
 * already has is skipped.
 *
 * 🔴 `occ` HAS NO SESSION, so every ObjectService call here runs as Anonymous
 * unless RBAC and multitenancy are switched off explicitly. Without the flags
 * the read returns nothing, the backfill reports "0 tasks found" and exits 0,
 * and that reads exactly like a clean run on an empty instance.
 *
 * @spec openspec/changes/dossiq-duplication-to-abstractions/tasks.md
 */
class TaskBackfillService {

    /**
     * How many register tasks to read per page.
     *
     * @var integer
     */
    private const PAGE_SIZE = 100;

    /**
     * Constructor.
     *
     * @param SettingsService   $settings The dossiq settings seam.
     * @param EngineTaskGateway $gateway  The engine seam.
     * @param LoggerInterface   $logger   The logger.
     */
    public function __construct(
        private readonly SettingsService $settings,
        private readonly EngineTaskGateway $gateway,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Copy every register task into the engine.
     *
     * @param boolean $dryRun When true, nothing is written.
     * @param string  $actor  The user id the migrated tasks are attributed to.
     *
     * @return array{read: int, written: int, skipped: int, present: int, failed: int, error: string}
     *
     * @spec openspec/changes/dossiq-duplication-to-abstractions/tasks.md
     */
    public function run(bool $dryRun, string $actor = ''): array {
        $result = ['read' => 0, 'written' => 0, 'skipped' => 0, 'present' => 0, 'failed' => 0, 'error' => ''];

        $reason = $this->gateway->unavailableReason();
        if ($reason !== '') {
            $result['error'] = $reason;
            return $result;
        }

        $objectService = $this->settings->getObjectService();
        if ($objectService === null) {
            $result['error'] = 'openregister ObjectService is unavailable';
            return $result;
        }

        $register = $this->settings->getConfigValue(key: 'register');
        $schema = $this->settings->getConfigValue(key: 'task_schema');
        if ($register === '' || $schema === '') {
            $result['error'] = 'register or task_schema is not configured';
            return $result;
        }

        // Existing engine keys, read once per CASE and cached. Without this
        // a second run doubles every task: measured, 33 rows became 66.
        $seenByCase = [];

        // Resolved once. The engine is fail-closed and refuses a verb with no
        // acting identity, so a blank actor could never write anything.
        $readActor = 'admin';
        if ($actor !== '') {
            $readActor = $actor;
        }

        $writeActor = null;
        if ($actor !== '') {
            $writeActor = $actor;
        }

        foreach ($this->readTasks(objectService: $objectService, register: $register, schema: $schema) as $task) {
            $result['read']++;

            $caseId = $this->caseIdOf(task: $task);
            if ($caseId === '') {
                // A task with no case cannot be placed against an object, and
                // the engine keys everything on the object triple. Counted as
                // skipped rather than failed: the row is not broken, it is
                // simply not migratable on its own.
                $result['skipped']++;
                continue;
            }

            if (array_key_exists($caseId, $seenByCase) === false) {
                $seenByCase[$caseId] = $this->gateway->existingKeysFor(
                    caseId: $caseId,
                    actor: $readActor
                );
            }

            $sourceId = trim((string)($task['id'] ?? $task['uuid'] ?? ''));
            if ($sourceId !== '') {
                $key = EngineTaskGateway::sourceKey(registerTaskId: $sourceId);
                if (isset($seenByCase[$caseId][$key]) === true) {
                    // Already mirrored. Counted separately from `skipped`,
                    // which means "could not be placed": reporting a clean
                    // re-run as 33 skipped-no-case reads like a defect.
                    $result['present']++;
                    continue;
                }

                // Remember it within this run too, so a register store that
                // returns the same row on two pages cannot write it twice.
                $seenByCase[$caseId][$key] = true;
            }

            if ($dryRun === true) {
                $result['written']++;
                continue;
            }

            $uuid = $this->gateway->mirrorCreate(task: $task, caseId: $caseId, actor: $writeActor, trusted: true);
            if ($uuid === '') {
                $result['failed']++;
                if ($result['error'] === '') {
                    // The FIRST reason, reported by the command. 33 identical
                    // failures with "see the log" is not a diagnosis.
                    $result['error'] = $this->gateway->lastError();
                }

                continue;
            }

            $result['written']++;
        }//end foreach

        return $result;
    }//end run()

    /**
     * Read every register task, one page at a time.
     *
     * 🔴 `_rbac: false` and `_multitenancy: false` are load-bearing. `occ`
     * carries no session, so every ObjectService call runs as Anonymous and a
     * scoped read returns an empty set. The command would then report a clean
     * run over zero tasks, which is indistinguishable from success.
     *
     * @param object $objectService The OpenRegister object service.
     * @param string $register      The register slug.
     * @param string $schema        The task schema slug.
     *
     * @return iterable<array<string, mixed>> The tasks.
     *
     * @spec openspec/changes/dossiq-duplication-to-abstractions/tasks.md
     */
    private function readTasks(object $objectService, string $register, string $schema): iterable {
        $page = 1;

        while (true) {
            try {
                // 🔴 setRegister/setSchema BEFORE findAll, and the pair also
                // goes inside `filters`. `findAll()` overwrites the service's
                // register/schema context as a side effect, so a read issued
                // after any other read silently queries the WRONG schema and
                // answers zero. KpiAggregationService carries the measurement:
                // a task count returns 23 alone and 0 straight after a findAll
                // over cases.
                $objectService->setRegister($register);
                $objectService->setSchema($schema);

                // 🔴 `_rbac` and `_multitenancy` are POSITIONAL parameters of
                // findAll(), not config keys. Passed inside the array they are
                // silently ignored, the read runs as Anonymous, and the command
                // reports "Read 0 task(s)" over an instance holding 34 of them.
                // That is measured, not hypothetical: it is what the first cut
                // of this command did.
                $response = $objectService->findAll(
                    [
                        'filters' => [
                            'register' => $register,
                            'schema'   => $schema,
                        ],
                        'limit'   => self::PAGE_SIZE,
                        'offset'  => (($page - 1) * self::PAGE_SIZE),
                    ],
                    false,
                    false
                );
            } catch (Throwable $e) {
                $this->logger->error(
                    'Dossiq: could not read tasks for the engine backfill',
                    ['exception' => $e->getMessage(), 'page' => $page]
                );

                return;
            }

            $rows = $this->rowsOf(response: $response);
            if ($rows === []) {
                return;
            }

            foreach ($rows as $row) {
                $task = $this->toArray(row: $row);
                if ($task !== []) {
                    yield $task;
                }
            }

            if (count($rows) < self::PAGE_SIZE) {
                return;
            }

            $page++;
        }//end while
    }//end readTasks()

    /**
     * Pull the rows out of whichever envelope the service returned.
     *
     * @param mixed $response The service response.
     *
     * @return array<int, mixed> The rows.
     *
     * @spec openspec/changes/dossiq-duplication-to-abstractions/tasks.md
     */
    private function rowsOf(mixed $response): array {
        if (is_array($response) === false) {
            return [];
        }

        foreach (['results', 'items', 'objects'] as $key) {
            if (isset($response[$key]) === true && is_array($response[$key]) === true) {
                return array_values($response[$key]);
            }
        }

        return array_values($response);
    }//end rowsOf()

    /**
     * Normalise a row to a plain array.
     *
     * `findAll()` returns RENDERED ENTITIES, not arrays. The first cut of
     * this service filtered on `is_array()` and therefore dropped every row,
     * reporting "Read 0 task(s)" against an instance holding 34 of them, with
     * no exception and nothing in the log. An empty result is the one failure
     * shape that reads exactly like success, which is why this is a named
     * method with its own test rather than an inline cast.
     *
     * @param mixed $row The row as the service returned it.
     *
     * @return array<string, mixed> The row as an array, or [] when unusable.
     *
     * @spec openspec/changes/dossiq-duplication-to-abstractions/tasks.md
     */
    public function toArray(mixed $row): array {
        if (is_array($row) === true) {
            return $row;
        }

        if (is_object($row) === false) {
            return [];
        }

        foreach (['jsonSerialize', 'getObject', 'toArray'] as $method) {
            if (method_exists($row, $method) === false) {
                continue;
            }

            $value = $row->$method();
            if (is_array($value) === true) {
                return $value;
            }
        }

        return (array)$row;
    }//end toArray()

    /**
     * The case this task is on, in either shape the store returns.
     *
     * `case` is a $ref and comes back as a bare id or as an expanded object.
     * A (string) cast on the expanded shape yields the literal "Array", which
     * would write a task against an object uuid that resolves to nothing.
     *
     * @param array<string, mixed> $task The task row.
     *
     * @return string The case id, or ''.
     *
     * @spec openspec/changes/dossiq-duplication-to-abstractions/tasks.md
     */
    public function caseIdOf(array $task): string {
        $ref = ($task['case'] ?? '');

        if (is_array($ref) === true) {
            return trim((string)($ref['id'] ?? $ref['uuid'] ?? ''));
        }

        return trim((string)$ref);
    }//end caseIdOf()
}//end class
