<?php

/**
 * Dossiq sendEmail action handler.
 *
 * Action config shape: `{type: 'sendEmail', to: '<address>', template?: '<id>', subject?, body?}`.
 * Delegates to CaseEmailService, which is the app's only outbound mail path:
 * it owns the IMailer message, the from-address, the recipient policy and the
 * record of the sent mail on the case.
 *
 * REQ-STE-5-002 says a failed side effect never rolls back the status change.
 * It does not say a failed side effect reports success. A send that did not
 * happen returns `succeeded: false`, and SideEffectDispatcher records that row
 * without touching the transition.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

use OCA\Dossiq\Service\CaseEmailService;
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
	 * @param CaseEmailService $emailService Case-scoped outbound mail
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly CaseEmailService $emailService,
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
		$recipient = (string)($actionConfig['to'] ?? '');
		if ($recipient === '') {
			return new ActionResult(succeeded: false, error: 'send_email_missing_recipient');
		}

		$caseId = (string)($case['id'] ?? ($case['uuid'] ?? ''));
		if ($caseId === '') {
			// CaseEmailService needs the case to resolve the from-address, the
			// template variables and the recipient policy. Without an id there
			// is nothing to send from.
			return new ActionResult(succeeded: false, error: 'send_email_missing_case');
		}

		$template = (string)($actionConfig['template'] ?? '');

		try {
			if ($template !== '') {
				$this->emailService->sendFromTemplate(
					caseId: $caseId,
					templateId: $template,
					to: $recipient,
				);

				return new ActionResult(
					succeeded: true,
					data: ['to' => $recipient, 'template' => $template],
				);
			}

			$this->emailService->sendEmail(
				caseId: $caseId,
				to: $recipient,
				subject: (string)($actionConfig['subject'] ?? ($transitionContext['transitionLabel'] ?? '')),
				body: (string)($actionConfig['body'] ?? ''),
			);

			return new ActionResult(succeeded: true, data: ['to' => $recipient]);
		} catch (\Throwable $e) {
			$this->logger->error(
				'SendEmailHandler failed',
				['exception' => $e->getMessage(), 'context' => $transitionContext],
			);

			return new ActionResult(succeeded: false, error: 'send_email_failed');
		}//end try
	}//end handle()
}//end class
