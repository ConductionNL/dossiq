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
use Throwable;

/**
 * Reads the engine's task inbox for one case.
 */
class EngineTaskInbox {

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
    private function resolveInbox(): ?object {
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
}//end class
