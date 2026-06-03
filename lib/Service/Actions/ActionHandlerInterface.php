<?php

/**
 * Procest Automatic Action Handler Interface
 *
 * Strategy interface for every built-in or third-party automatic-action
 * handler. Implementations are registered via the
 * `procest.transition_side_effect_handler` DI tag and resolved by
 * SideEffectDispatcher.
 *
 * @category Service
 * @package  OCA\Procest\Service\Actions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-automatic-actions/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Actions;

/**
 * Contract for an automatic-action handler.
 *
 * Implementations MUST:
 *  - Return the value of {@see type()} matching the `type` field on
 *    `automaticAction` objects they handle (one of `sendEmail`,
 *    `createDocument`, `notifyRole`, `callWebhook`, `mergeTemplate`,
 *    `scheduleReminder`).
 *  - Catch `\Throwable` inside {@see handle()}, log via LoggerInterface, and
 *    return {@see ActionResult::failure()} with a static error code. Handlers
 *    MUST NEVER bubble exceptions or include `$e->getMessage()` in
 *    ActionResult.error.
 *  - Honour `$transitionContext['dryRun'] === true`: compute the projected
 *    effect, return it in `ActionResult.data`, and NOT mutate live state
 *    (no email send, no document write, no outbound HTTP, no scheduled job).
 */
interface ActionHandlerInterface
{
    /**
     * Return the action `type` slug this handler implements.
     *
     * @return string One of the six built-in handler types, or a custom slug
     *                for third-party extensions.

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function type(): string;

    /**
     * Execute the action against a case in a given transition context.
     *
     * @param array $actionConfig      Resolved `automaticAction.config` array
     *                                 (handler-specific shape) plus the
     *                                 `slug`, `type`, `tenantId` envelope.
     * @param array $case              The full case object (id, identifier,
     *                                 indiener, etc.) used for template
     *                                 rendering and recipient resolution.
     * @param array $transitionContext Context from
     *                                 StatusTransitionService::execute:
     *                                 `fromStatus`, `toStatus`,
     *                                 `transitionLabel`, `userId`,
     *                                 `statusRecordUuid`, `tenantId`, and the
     *                                 boolean `dryRun` flag.
     *
     * @return ActionResult

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function handle(array $actionConfig, array $case, array $transitionContext): ActionResult;
}//end interface
