<?php

/**
 * Procest besluitvormingPublish action handler.
 *
 * Wires the DROP/LVBB publication dispatcher into the workflow engine. When a
 * case enters the "Bekendmaking" status step, this auto-action invokes
 * PublicationService::dispatch(). Per the spec a failed dispatch MUST NOT roll
 * back the status change — the handler always returns a static ActionResult and
 * the failure is logged on the case for manual retry.
 *
 * Action config shape: `{type: 'besluitvormingPublish'}`.
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
 * @spec openspec/changes/besluitvorming-workflow/specs/besluitvorming-workflow/spec.md#req-bvw-006
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Transitions;

use OCA\Procest\Service\PublicationService;
use Psr\Log\LoggerInterface;

/**
 * Auto-action handler that dispatches a besluit to DROP/LVBB.
 *
 * @spec openspec/changes/besluitvorming-workflow/specs/besluitvorming-workflow/spec.md#req-bvw-006
 */
class BesluitvormingPublishHandler implements ActionHandlerInterface
{
    /**
     * Constructor.
     *
     * @param PublicationService $publicationService The DROP/LVBB dispatcher.
     * @param LoggerInterface    $logger             Logger.
     *
     * @return void
     */
    public function __construct(
        private readonly PublicationService $publicationService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle the besluitvormingPublish action.
     *
     * @param array<string, mixed> $actionConfig      Action configuration.
     * @param array<string, mixed> $case              Case object.
     * @param array<string, mixed> $transitionContext Transition context.
     *
     * @return ActionResult
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec openspec/changes/besluitvorming-workflow/specs/besluitvorming-workflow/spec.md#req-bvw-006
     */
    public function handle(array $actionConfig, array $case, array $transitionContext): ActionResult
    {
        try {
            $caseId = (string) ($case['id'] ?? $case['uuid'] ?? '');
            if ($caseId === '') {
                return ActionResult::failure(error: 'no_case_id');
            }

            $result = $this->publicationService->dispatch($caseId);
            if (($result['ok'] ?? false) === true) {
                return ActionResult::success(data: $result);
            }

            // Failure does not block the transition; surface for manual retry.
            return ActionResult::failure(error: (string) ($result['error'] ?? 'publication_failed'), data: $result);
        } catch (\Throwable $e) {
            $this->logger->error(
                'BesluitvormingPublishHandler failed',
                ['exception' => $e->getMessage(), 'context' => $transitionContext],
            );
            return ActionResult::failure(error: 'publication_failed');
        }//end try
    }//end handle()
}//end class
