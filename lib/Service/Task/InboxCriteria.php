<?php

/**
 * The engine's inbox criteria for a flat set of named filters.
 *
 * Every filter is a NAMED argument on one of OpenRegister's TaskInbox value
 * classes: who asks (audience), which tasks (filter), when they fall due
 * (window), what they hang off (anchor) and in what order. A filter none of
 * those knows is passed on to the criteria, so the engine names it as an
 * unknown parameter instead of this app dropping it. Split out of
 * {@see EngineInboxQuery}, which keeps resolving the inbox, making the read
 * and telling a failed read apart from an empty one.
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
 * @spec openspec/specs/add-work-queue/spec.md#requirement-three-surfaces-one-rule
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Task;

use OCA\OpenRegister\Db\TaskInboxAnchor;
use OCA\OpenRegister\Db\TaskInboxAudience;
use OCA\OpenRegister\Db\TaskInboxCriteria;
use OCA\OpenRegister\Db\TaskInboxDueWindow;
use OCA\OpenRegister\Db\TaskInboxFilter;
use OCA\OpenRegister\Db\TaskInboxOrder;
use OCA\OpenRegister\Db\TaskInboxSelection;

/**
 * Builds the engine's inbox criteria from named filters.
 *
 * @spec openspec/specs/add-work-queue/spec.md#requirement-three-surfaces-one-rule
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class InboxCriteria {

    /**
     * The engine's inbox criteria for a flat set of named filters.
     *
     * @param array<string, mixed> $criteria Filters keyed by name: uid, groupIds, isAdmin, scope,
     *                                       states, isTerminal, priority, kind, overdueAt, dueAfter,
     *                                       dueBefore, objectUuid, runUuid, includeBlocked, sort,
     *                                       sortDescending, openFirst.
     *
     * @return TaskInboxCriteria The criteria.
     *
     * @spec openspec/specs/add-work-queue/spec.md#requirement-three-surfaces-one-rule
     * @spec openspec/changes/method-decomposition/tasks.md
     */
    public function forFilters(array $criteria): TaskInboxCriteria {
        $parts = [
            'audience' => ['uid', 'groupIds', 'isAdmin', 'scope'],
            'filter' => ['states', 'isTerminal', 'priority', 'kind'],
            'window' => ['overdueAt', 'dueAfter', 'dueBefore'],
            'anchor' => ['objectUuid', 'runUuid', 'includeBlocked'],
            'order' => ['sort', 'sortDescending', 'openFirst'],
        ];
        $pick = static fn (string $part): array => array_intersect_key($criteria, array_flip($parts[$part]));
        // A filter none of the parts knows is passed on, so the engine names it as an unknown parameter.
        $unknown = array_diff_key($criteria, array_flip(array_merge(...array_values($parts))));

        $arguments = [
            'audience' => new TaskInboxAudience(...$pick('audience')),
            'selection' => new TaskInboxSelection(
                filter: new TaskInboxFilter(...$pick('filter')),
                window: new TaskInboxDueWindow(...$pick('window')),
                anchor: new TaskInboxAnchor(...$pick('anchor')),
                order: new TaskInboxOrder(...$pick('order'))
            ),
        ];

        return new TaskInboxCriteria(...($arguments + $unknown));
    }//end forFilters()
}//end class
