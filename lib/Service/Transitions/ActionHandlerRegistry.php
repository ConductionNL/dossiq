<?php

/**
 * Procest Action Handler Registry.
 *
 * Strategy-pattern registry mapping action `type` strings to the corresponding
 * ActionHandlerInterface implementations. Built-in handlers are injected via
 * DI; downstream specs (`bezwaar-lifecycle`, `parafeerroute-engine`) MAY add
 * additional types via `registerHandler()` in their bootstrap so the engine
 * itself does not need to know about them at compile time.
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
 * Registry of action handlers keyed by action type.
 *
 * @spec openspec/changes/status-transition-engine/tasks.md#T09
 */
class ActionHandlerRegistry
{

    /**
     * Registered handlers keyed by action type.
     *
     * @var array<string, ActionHandlerInterface>
     */
    private array $handlers = [];

    /**
     * Constructor — wires the built-in handlers.
     *
     * @param SendEmailHandler     $sendEmail     Built-in email handler
     * @param CreateTaskHandler    $createTask    Built-in task handler
     * @param CreateSubCaseHandler $createSubCase Built-in sub-case handler
     * @param WebhookHandler       $webhook       Built-in webhook handler
     * @param SetFieldHandler      $setField      Built-in field-set handler
     * @param NotifyHandler        $notify        Built-in notification handler
     */
    public function __construct(
        SendEmailHandler $sendEmail,
        CreateTaskHandler $createTask,
        CreateSubCaseHandler $createSubCase,
        WebhookHandler $webhook,
        SetFieldHandler $setField,
        NotifyHandler $notify,
    ) {
        $this->handlers = [
            'sendEmail'     => $sendEmail,
            'createTask'    => $createTask,
            'createSubCase' => $createSubCase,
            'webhook'       => $webhook,
            'setField'      => $setField,
            'notify'        => $notify,
        ];
    }//end __construct()

    /**
     * Register an additional handler (DI extension point).
     *
     * @param string                 $type    Action type identifier
     * @param ActionHandlerInterface $handler Handler implementation
     *
     * @return void
     */
    public function registerHandler(string $type, ActionHandlerInterface $handler): void
    {
        $this->handlers[$type] = $handler;
    }//end registerHandler()

    /**
     * Look up a handler by type.
     *
     * @param string $type Action type
     *
     * @return ActionHandlerInterface|null
     */
    public function getHandler(string $type): ?ActionHandlerInterface
    {
        return ($this->handlers[$type] ?? null);
    }//end getHandler()

    /**
     * Get all registered action types.
     *
     * @return array<int, string>
     */
    public function getRegisteredTypes(): array
    {
        return array_keys($this->handlers);
    }//end getRegisteredTypes()
}//end class
