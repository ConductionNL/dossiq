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
 * IDEMPOTENT, BY READING THE ENGINE FIRST
 * ---------------------------------------
 * Re-running must not double every task. The engine is asked what it already
 * holds for this app before anything is written, and a task whose title and
 * object are already there is skipped. That is a weaker key than a uuid, and
 * deliberately so: the register task's uuid is NOT the engine task's uuid, and
 * inventing a mapping table for a store that is about to be deleted would
 * outlive the migration.
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
     *
     * @return array{read: int, written: int, skipped: int, failed: int, error: string}
     *
     * @spec openspec/changes/dossiq-duplication-to-abstractions/tasks.md
     */
    public function run(bool $dryRun): array {
        $result = ['read' => 0, 'written' => 0, 'skipped' => 0, 'failed' => 0, 'error' => ''];

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

            if ($dryRun === true) {
                $result['written']++;
                continue;
            }

            $uuid = $this->gateway->mirrorCreate(task: $task, caseId: $caseId, actor: null);
            if ($uuid === '') {
                $result['failed']++;
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
                $response = $objectService->findAll(
                    [
                        'register'       => $register,
                        'schema'         => $schema,
                        'limit'          => self::PAGE_SIZE,
                        'page'           => $page,
                        '_rbac'          => false,
                        '_multitenancy'  => false,
                    ]
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
                if (is_array($row) === true) {
                    yield $row;
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
