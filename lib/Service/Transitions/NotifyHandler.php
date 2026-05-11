<?php

/**
 * Procest notify action handler.
 *
 * Action config shape: `{type: 'notify', userId?: '<uid>', message?: '<text>'}`.
 * Dispatches an in-app Nextcloud notification via NotificatieService.
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

use OCA\Procest\Service\NotificatieService;
use Psr\Log\LoggerInterface;

/**
 * Built-in handler for `notify` automatic actions.
 *
 * @spec openspec/changes/status-transition-engine/tasks.md#T08
 */
class NotifyHandler implements ActionHandlerInterface
{

    /**
     * Constructor.
     *
     * @param NotificatieService $notificatieService Notification dispatcher
     * @param LoggerInterface    $logger             Logger
     */
    public function __construct(
        private readonly NotificatieService $notificatieService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle the notify action.
     *
     * @param array<string, mixed> $actionConfig      Action configuration
     * @param array<string, mixed> $case              Case object
     * @param array<string, mixed> $transitionContext Transition context
     *
     * @return ActionResult
     */
    public function handle(array $actionConfig, array $case, array $transitionContext): ActionResult
    {
        try {
            $caseId    = (string) ($case['id'] ?? ($case['uuid'] ?? ''));
            $recipient = (string) ($actionConfig['userId'] ?? ($case['assignee'] ?? ''));
            $message   = (string) ($actionConfig['message'] ?? sprintf('Status gewijzigd: %s', $transitionContext['transitionLabel'] ?? ''));

            if (method_exists($this->notificatieService, 'notifyUser') === true && $recipient !== '') {
                $this->notificatieService->notifyUser($recipient, $message, ['caseId' => $caseId]);
                return ActionResult::success(data: ['userId' => $recipient]);
            }

            // Fall back to logging — non-fatal.
            $this->logger->warning('NotifyHandler: NotificatieService::notifyUser missing or recipient empty');
            return ActionResult::success(data: ['skipped' => true]);
        } catch (\Throwable $e) {
            $this->logger->error(
                'NotifyHandler failed',
                ['exception' => $e->getMessage(), 'context' => $transitionContext],
            );
            return ActionResult::failure(error: 'notify_failed');
        }
    }//end handle()
}//end class
