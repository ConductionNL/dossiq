<?php

/**
 * Procest sendEmail action handler.
 *
 * Action config shape: `{type: 'sendEmail', to: '<address-or-userId>', template?: '<id>', subject?, body?}`.
 * Delegates to NotificatieService::sendEmail (where available). Failures are
 * logged with full context but returned as static error messages.
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
 * Built-in handler for `sendEmail` automatic actions.
 *
 * @spec openspec/changes/status-transition-engine/tasks.md#T08
 */
class SendEmailHandler implements ActionHandlerInterface {
	/**
	 * Constructor.
	 *
	 * @param NotificatieService $notificationService Notification dispatcher
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly NotificatieService $notificationService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle the sendEmail action.
	 *
	 * @param array<string, mixed> $actionConfig Action configuration
	 * @param array<string, mixed> $case Case object
	 * @param array<string, mixed> $transitionContext Transition context (fromStatus/toStatus/etc.)
	 *
	 * @return ActionResult
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function handle(array $actionConfig, array $case, array $transitionContext): ActionResult {
		try {
			$recipient = (string)($actionConfig['to'] ?? '');
			if ($recipient === '') {
				return new ActionResult(succeeded: false, error: 'send_email_missing_recipient');
			}

			$payload = [
				'caseId' => (string)($case['id'] ?? ($case['uuid'] ?? '')),
				'subject' => (string)($actionConfig['subject'] ?? ($transitionContext['transitionLabel'] ?? '')),
				'body' => (string)($actionConfig['body'] ?? ''),
				'template' => (string)($actionConfig['template'] ?? ''),
				'transition' => $transitionContext,
			];

			if (method_exists($this->notificationService, 'sendEmail') === true) {
				$this->notificationService->sendEmail($recipient, $payload);
				return new ActionResult(succeeded: true, data: ['to' => $recipient]);
			}

			// No mail delivery available — record as success since notification
			// dispatch is best-effort per spec REQ-STE-5-002 (failures do not
			// block transitions). Log a warning so the gap is visible.
			$this->logger->warning('SendEmailHandler: NotificatieService::sendEmail missing — skipping');
			return new ActionResult(succeeded: true, data: ['to' => $recipient, 'skipped' => true]);
		} catch (\Throwable $e) {
			$this->logger->error(
				'SendEmailHandler failed',
				['exception' => $e->getMessage(), 'context' => $transitionContext],
			);
			return new ActionResult(succeeded: false, error: 'send_email_failed');
		}//end try
	}//end handle()
}//end class
