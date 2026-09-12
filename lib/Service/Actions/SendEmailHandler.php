<?php

/**
 * Dossiq SendEmailHandler
 *
 * Renders subject + body templates against the case and (in live mode)
 * dispatches the email via CaseEmailService, the app's only outbound mail
 * path: it owns the IMailer message, the from-address, the recipient policy
 * and the record of the sent mail on the case. In dry-run mode it returns the
 * rendered preview without contacting the mail subsystem.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Actions
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
 * @link https://conduction.nl
 *
 * @spec openspec/specs/automatic-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Actions;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\CaseEmailService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Handler for `sendEmail` automatic actions.
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class SendEmailHandler implements ActionHandlerInterface {
	use HandlesTemplates;

	/**
	 * Constructor for SendEmailHandler.
	 *
	 * @param ContainerInterface $container DI container, used to resolve
	 *                                      CaseEmailService lazily. This
	 *                                      handler is built whenever the Flow
	 *                                      node catalogue is read, and a
	 *                                      constructor dependency would drag
	 *                                      the mailer and its repositories
	 *                                      into every catalogue read.
	 * @param LoggerInterface $logger PSR-3 logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The action type slug handled by this handler.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function type(): string {
		return 'sendEmail';
	}//end type()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $actionConfig Resolved action config array.
	 * @param array $case The full case object.
	 * @param array $transitionContext Transition context (carries dryRun).
	 *
	 * @return ActionResult The outcome of sending the email.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function handle(array $actionConfig, array $case, array $transitionContext): ActionResult {
		try {
			$subject = $this->renderTemplate(
				template: (string)($actionConfig['subjectTemplate'] ?? ''),
				case: $case
			);
			$body = $this->renderTemplate(
				template: (string)($actionConfig['bodyTemplate'] ?? ''),
				case: $case
			);
			$recipient = $this->resolveRecipient(
				recipientRef: (string)($actionConfig['recipientRef'] ?? ''),
				case: $case
			);

			$preview = [
				'recipient' => $recipient,
				'subject' => $subject,
				'body' => $body,
			];

			if (($transitionContext['dryRun'] ?? false) === true) {
				return new ActionResult(succeeded: true, data: $preview);
			}

			if ($recipient === '') {
				return new ActionResult(succeeded: false, error: 'missing_recipient', data: $preview);
			}

			$caseId = (string)($case['id'] ?? ($case['uuid'] ?? ''));
			if ($caseId === '') {
				// CaseEmailService needs the case to resolve the from-address,
				// the recipient policy and the record of the send. Without an
				// id there is nothing to send from.
				return new ActionResult(succeeded: false, error: 'missing_case_id', data: $preview);
			}

			$emailService = $this->resolveEmailService();
			if ($emailService === null) {
				return new ActionResult(succeeded: false, error: 'email_service_unavailable', data: $preview);
			}

			$sent = $emailService->sendEmail(
				caseId: $caseId,
				to: $recipient,
				subject: $subject,
				body: $body,
			);

			$preview['messageId'] = (string)($sent['messageId'] ?? '');

			return new ActionResult(succeeded: true, data: $preview);
		} catch (\Throwable $e) {
			$this->logger->error(
				'SendEmailHandler: failed to dispatch email',
				[
					'app' => Application::APP_ID,
					'slug' => (string)($actionConfig['slug'] ?? ''),
					'exception' => $e->getMessage(),
				]
			);
			return new ActionResult(succeeded: false, error: 'email_dispatch_failed');
		}//end try
	}//end handle()

	/**
	 * Resolve CaseEmailService lazily, and typed.
	 *
	 * Typed on purpose. The soft binding this replaced called
	 * `NotificatieService::sendEmail()`, a method that has never existed, and
	 * a container that answers `object` let that survive every static check.
	 * An `instanceof` narrows the return so the call below is analysed against
	 * the real signature.
	 *
	 * @return CaseEmailService|null The service, or null when unavailable.
	 */
	private function resolveEmailService(): ?CaseEmailService {
		try {
			$service = $this->container->get(CaseEmailService::class);
		} catch (\Throwable $e) {
			return null;
		}

		if ($service instanceof CaseEmailService) {
			return $service;
		}

		return null;
	}//end resolveEmailService()
}//end class
