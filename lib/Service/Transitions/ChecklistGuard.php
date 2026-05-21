<?php

/**
 * Procest Checklist Guard evaluator.
 *
 * Guard config shape: `{type: 'checklist', taskId: <uuid>, requiredItems?: [<itemLabel>, ...]}`.
 * Loads the referenced task and verifies that every required checklist item
 * is marked `checked: true`. If `requiredItems` is omitted, all items on the
 * task must be checked.
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

use OCA\Procest\Service\SettingsService;
use Psr\Log\LoggerInterface;

/**
 * Guard: verifies checklist items on a linked task are complete.
 *
 * @spec openspec/changes/status-transition-engine/tasks.md#T05
 */
class ChecklistGuard implements GuardEvaluatorInterface
{
    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Bridge to OpenRegister + config
     * @param LoggerInterface $logger          Logger
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Evaluate the checklist guard.
     *
     * @param array<string, mixed> $guardConfig Guard configuration
     * @param array<string, mixed> $case        Case object (unused here, included for interface parity)
     * @param string               $userId      Current user UID (unused)
     *
     * @return GuardResult
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function evaluate(array $guardConfig, array $case, string $userId): GuardResult
    {
        $taskId = (string) ($guardConfig['taskId'] ?? '');
        if ($taskId === '') {
            return GuardResult::fail(message: 'Checklist guard missing taskId');
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return GuardResult::fail(message: 'Opslag niet beschikbaar');
        }

        $register   = $this->settingsService->getConfigValue(key: 'register');
        $taskSchema = $this->settingsService->getConfigValue(key: 'task_schema');
        if ($register === '' || $taskSchema === '') {
            return GuardResult::fail(message: 'Taak-register niet geconfigureerd');
        }

        try {
            $task = $objectService->findObject($register, $taskSchema, $taskId);
            $task = $this->toArray(value: $task);
        } catch (\Throwable $e) {
            $this->logger->error('ChecklistGuard: task load failed', ['exception' => $e->getMessage()]);
            return GuardResult::fail(message: 'Gekoppelde taak niet gevonden');
        }

        $items = $task['checklist'] ?? ($task['items'] ?? []);
        if (is_array($items) === false) {
            $items = [];
        }

        $required = $guardConfig['requiredItems'] ?? null;
        $missing  = [];
        foreach ($items as $item) {
            if (is_array($item) === false) {
                continue;
            }

            $label   = (string) ($item['label'] ?? ($item['name'] ?? ''));
            $checked = (bool) ($item['checked'] ?? false);

            $hasRequired = (is_array($required) === true && $required !== []);
            if ($hasRequired === true && in_array($label, $required, true) === true && $checked === false) {
                $missing[] = $label;
            }

            if ($hasRequired === false && $checked === false && $label !== '') {
                $missing[] = $label;
            }
        }

        if (count($missing) === 0) {
            return GuardResult::pass();
        }

        $first = $missing[0];
        return GuardResult::fail(
            message: sprintf("%d checklistitem niet afgevinkt: '%s'", count($missing), $first),
            details: ['missing' => $missing],
        );
    }//end evaluate()

    /**
     * Coerce ObjectService results to array.
     *
     * @param mixed $value Mixed result from ObjectService
     *
     * @return array<string, mixed>
     */
    private function toArray(mixed $value): array
    {
        if (is_array($value) === true) {
            return $value;
        }

        if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
            $serialized = $value->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }
        }

        return [];
    }//end toArray()
}//end class
