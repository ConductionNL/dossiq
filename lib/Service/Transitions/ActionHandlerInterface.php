<?php

/**
 * Procest Action Handler interface.
 *
 * Implementations execute one automatic-action type configured on a workflow
 * transition. Handlers MUST catch \Throwable internally and return a static
 * ActionResult — they SHALL NOT propagate exceptions, since per the spec
 * failed side-effects MUST NOT roll back the status change.
 *
 * @category Service
 * @package  OCA\Procest\Service\Transitions
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Transitions;

/**
 * Strategy interface for automatic-action handling.
 *
 * @spec openspec/changes/status-transition-engine/tasks.md#T07
 */
interface ActionHandlerInterface
{
    /**
     * Handle a single automatic action.
     *
     * @param array<string, mixed> $actionConfig      The action block (`{type, ...config}`)
     * @param array<string, mixed> $case              The case object as an array
     * @param array<string, mixed> $transitionContext Snapshot of the transition: fromStatus, toStatus, transitionLabel, userId, statusRecordUuid
     *
     * @return ActionResult
     */
    public function handle(array $actionConfig, array $case, array $transitionContext): ActionResult;
}//end interface
