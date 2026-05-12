<?php

/**
 * Procest createSubCase action handler.
 *
 * Action config shape: `{type: 'createSubCase', caseType: '<uuid>', title?, hoofdzaakField?: 'hoofdzaak'}`.
 * Creates a deelzaak linked to the parent case via `hoofdzaak`.
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
 * Built-in handler for `createSubCase` automatic actions.
 *
 * @spec openspec/changes/status-transition-engine/tasks.md#T08
 */
class CreateSubCaseHandler implements ActionHandlerInterface
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
     * Handle the createSubCase action.
     *
     * @param array<string, mixed> $actionConfig      Action configuration
     * @param array<string, mixed> $case              Parent case object
     * @param array<string, mixed> $transitionContext Transition context
     *
     * @return ActionResult
     */
    public function handle(array $actionConfig, array $case, array $transitionContext): ActionResult
    {
        try {
            $objectService = $this->settingsService->getObjectService();
            if ($objectService === null) {
                return ActionResult::failure(error: 'storage_unavailable');
            }

            $register   = $this->settingsService->getConfigValue(key: 'register');
            $caseSchema = $this->settingsService->getConfigValue(key: 'case_schema');
            if ($register === '' || $caseSchema === '') {
                return ActionResult::failure(error: 'case_schema_not_configured');
            }

            $parentId = (string) ($case['id'] ?? ($case['uuid'] ?? ''));
            $subCase  = [
                'title'     => (string) ($actionConfig['title'] ?? sprintf('Deelzaak na %s', $transitionContext['transitionLabel'] ?? '')),
                'caseType'  => (string) ($actionConfig['caseType'] ?? ''),
                'hoofdzaak' => $parentId,
            ];

            $created = $objectService->saveObject($register, $caseSchema, $subCase);
            $subId   = '';
            if (is_array($created) === true) {
                $subId = (string) ($created['id'] ?? '');
            }

            return ActionResult::success(data: ['subCaseId' => $subId]);
        } catch (\Throwable $e) {
            $this->logger->error(
                'CreateSubCaseHandler failed',
                ['exception' => $e->getMessage(), 'context' => $transitionContext],
            );
            return ActionResult::failure(error: 'create_sub_case_failed');
        }//end try
    }//end handle()
}//end class
