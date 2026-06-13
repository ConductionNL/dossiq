<?php

/**
 * Procest besluitvormingActivate action handler.
 *
 * Wires the besluitvorming parafering chain into the workflow engine. When a
 * case enters the "Parafering" status step, this auto-action resolves the
 * case's active voorstel and invokes BesluitvormingParafeerService::activate()
 * to snapshot the parafeerroute and open the first paraaf task.
 *
 * Action config shape: `{type: 'besluitvormingActivate'}`. The voorstel is
 * resolved from the case rather than caller-supplied data.
 *
 * @category Service
 * @package  OCA\Procest\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/besluitvorming-workflow/specs/besluitvorming-workflow/spec.md#req-bvw-002
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Transitions;

use OCA\Procest\Service\BesluitvormingParafeerService;
use OCA\Procest\Service\SettingsService;
use Psr\Log\LoggerInterface;

/**
 * Auto-action handler that activates the besluitvorming parafering chain.
 *
 * @spec openspec/changes/besluitvorming-workflow/specs/besluitvorming-workflow/spec.md#req-bvw-002
 */
class BesluitvormingActivateHandler implements ActionHandlerInterface
{
    /**
     * Constructor.
     *
     * @param BesluitvormingParafeerService $parafeerService The parafering chain orchestrator.
     * @param SettingsService               $settingsService Bridge to OpenRegister + config.
     * @param LoggerInterface               $logger          Logger.
     *
     * @return void
     */
    public function __construct(
        private readonly BesluitvormingParafeerService $parafeerService,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle the besluitvormingActivate action.
     *
     * @param array<string, mixed> $actionConfig      Action configuration.
     * @param array<string, mixed> $case              Case object.
     * @param array<string, mixed> $transitionContext Transition context.
     *
     * @return ActionResult
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec openspec/changes/besluitvorming-workflow/specs/besluitvorming-workflow/spec.md#req-bvw-002
     */
    public function handle(array $actionConfig, array $case, array $transitionContext): ActionResult
    {
        try {
            $voorstelId = $this->resolveVoorstelId(case: $case);
            if ($voorstelId === '') {
                return ActionResult::failure(error: 'no_active_voorstel');
            }

            $this->parafeerService->activate($voorstelId);

            return ActionResult::success(data: ['voorstel' => $voorstelId]);
        } catch (\Throwable $e) {
            $this->logger->error(
                'BesluitvormingActivateHandler failed',
                ['exception' => $e->getMessage(), 'context' => $transitionContext],
            );
            return ActionResult::failure(error: 'besluitvorming_activate_failed');
        }//end try
    }//end handle()

    /**
     * Resolve the active voorstel id for a case.
     *
     * @param array<string, mixed> $case The case payload.
     *
     * @return string The voorstel id, or empty string.
     */
    private function resolveVoorstelId(array $case): string
    {
        // A voorstel may be linked directly on the case.
        $direct = (string) ($case['voorstel'] ?? '');
        if ($direct !== '') {
            return $direct;
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return '';
        }

        $register       = $this->settingsService->getConfigValue(key: 'register');
        $voorstelSchema = $this->settingsService->getConfigValue(key: 'voorstel_schema');
        $caseId         = (string) ($case['id'] ?? $case['uuid'] ?? '');
        if ($register === '' || $voorstelSchema === '' || $caseId === '') {
            return '';
        }

        try {
            $results = $objectService->findAll(
                [
                    'filters' => ['register' => $register, 'schema' => $voorstelSchema, 'case' => $caseId],
                    'limit'   => 1,
                ],
            );

            if (is_array($results) === true && isset($results['results']) === true) {
                $results = $results['results'];
            }

            if (is_array($results) === true && count($results) > 0) {
                $first = $results[0];
                if (is_object($first) === true && method_exists($first, 'jsonSerialize') === true) {
                    $first = $first->jsonSerialize();
                }

                if (is_array($first) === true) {
                    return (string) ($first['id'] ?? $first['uuid'] ?? '');
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'BesluitvormingActivateHandler could not resolve voorstel',
                ['exception' => $e->getMessage()],
            );
        }//end try

        return '';
    }//end resolveVoorstelId()
}//end class
