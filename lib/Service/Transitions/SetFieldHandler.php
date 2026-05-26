<?php

/**
 * Procest setField action handler.
 *
 * Action config shape: `{type: 'setField', field: 'einddatum', value: '<value-or-now>'}`.
 * Writes the named field on the case via OpenRegister ObjectService. Special
 * `value` macros: `__now__` becomes the current ISO-8601 timestamp.
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

use DateTimeImmutable;
use OCA\Procest\Service\SettingsService;
use Psr\Log\LoggerInterface;

/**
 * Built-in handler for `setField` automatic actions.
 *
 * @spec openspec/changes/status-transition-engine/tasks.md#T08
 */
class SetFieldHandler implements ActionHandlerInterface
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
     * Handle the setField action.
     *
     * @param array<string, mixed> $actionConfig      Action configuration
     * @param array<string, mixed> $case              Case object
     * @param array<string, mixed> $transitionContext Transition context
     *
     * @return ActionResult
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    /** @spec openspec/specs/status-transition-engine/spec.md */
    public function handle(array $actionConfig, array $case, array $transitionContext): ActionResult
    {
        try {
            $field = (string) ($actionConfig['field'] ?? '');
            if ($field === '') {
                return ActionResult::failure(error: 'set_field_missing_field');
            }

            $value = $actionConfig['value'] ?? null;
            if ($value === '__now__') {
                $value = (new DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM);
            }

            $objectService = $this->settingsService->getObjectService();
            if ($objectService === null) {
                return ActionResult::failure(error: 'storage_unavailable');
            }

            $register   = $this->settingsService->getConfigValue(key: 'register');
            $caseSchema = $this->settingsService->getConfigValue(key: 'case_schema');
            if ($register === '' || $caseSchema === '') {
                return ActionResult::failure(error: 'case_schema_not_configured');
            }

            $case[$field] = $value;
            $objectService->saveObject($register, $caseSchema, $case);

            return ActionResult::success(data: ['field' => $field]);
        } catch (\Throwable $e) {
            $this->logger->error(
                'SetFieldHandler failed',
                ['exception' => $e->getMessage(), 'context' => $transitionContext],
            );
            return ActionResult::failure(error: 'set_field_failed');
        }//end try
    }//end handle()
}//end class
