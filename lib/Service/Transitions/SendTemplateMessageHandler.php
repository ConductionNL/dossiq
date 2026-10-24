<?php

/**
 * Sends one of the case type's message templates to a role on the case.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

use OCA\Dossiq\Service\AssigneeResolver;
use OCA\Dossiq\Service\CaseEmailService;
use OCA\Dossiq\Service\Email\CaseContactDirectory;
use OCA\Dossiq\Service\Termijn\TermNoticeLedger;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Action `sendMessage`: `{emailTemplate, recipientRole?}`.
 *
 * The template is rendered and sent through CaseEmailService, which keeps its
 * own allowlist, opt-out and timeline entry. The recipients are the people
 * holding `recipientRole` on the case; without one, the applicant.
 *
 * With `onceKey` the message is sent once per case, or once per value of the
 * case field `oncePer` (a status rule passes `currentStatusEnteredAt`, so each
 * entry into the status sends once). The claim goes through the send-once
 * ledger term notices use, whose unique key refuses a second claim.
 *
 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
 */
class SendTemplateMessageHandler implements ActionHandlerInterface {

	/**
	 * Constructor.
	 *
	 * @param AssigneeResolver     $assignees The case's id.
	 * @param CaseContactDirectory $contacts  The addresses of a role on the case.
	 * @param CaseEmailService     $mail      Renders and sends a template.
	 * @param LoggerInterface      $logger    The logger.
	 * @param TermNoticeLedger     $ledger    Claims a send-once key.
	 */
	public function __construct(
		private readonly AssigneeResolver $assignees,
		private readonly CaseContactDirectory $contacts,
		private readonly CaseEmailService $mail,
		private readonly LoggerInterface $logger,
		private readonly TermNoticeLedger $ledger,
	) {
	}//end __construct()

	/**
	 * Send the template to every address of the role.
	 *
	 * @param array<string, mixed> $actionConfig      `{emailTemplate, recipientRole?}`.
	 * @param array<string, mixed> $case              The case.
	 * @param array<string, mixed> $transitionContext The run or transition context.
	 *
	 * @return ActionResult The result: the addresses written to, or why none.
	 *
	 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
	 */
	public function handle(array $actionConfig, array $case, array $transitionContext): ActionResult {
		$template = trim((string)($actionConfig['emailTemplate'] ?? ''));
		$caseId = $this->assignees->caseId(case: $case);
		if ($template === '' || $caseId === '') {
			return new ActionResult(succeeded: false, error: 'send_message_incomplete');
		}

		$recipients = $this->contacts->addressesForRole(caseData: $case, role: (string)($actionConfig['recipientRole'] ?? ''));
		if ($recipients === []) {
			return new ActionResult(succeeded: false, error: 'no_recipient');
		}

		$claim = $this->claimOf(actionConfig: $actionConfig, case: $case, caseId: $caseId);
		if ($claim !== '' && $this->ledger->claim(key: $claim, template: $template, instance: $caseId, now: time()) === false) {
			return new ActionResult(succeeded: true, data: ['skipped' => 'already sent']);
		}

		return $this->send(recipients: $recipients, template: $template, caseId: $caseId, claim: $claim);
	}//end handle()

	/**
	 * Send to every recipient, settling the claim, or giving it back on a failure.
	 *
	 * @param array<int, string> $recipients The addresses.
	 * @param string             $template   The template id.
	 * @param string             $caseId     The case id.
	 * @param string             $claim      The ledger key, or ''.
	 *
	 * @return ActionResult The result.
	 */
	private function send(array $recipients, string $template, string $caseId, string $claim): ActionResult {
		try {
			foreach ($recipients as $recipient) {
				$this->mail->sendFromTemplate(caseId: $caseId, templateId: $template, to: $recipient);
			}
		} catch (Throwable $e) {
			$this->logger->error('SendTemplateMessageHandler: the message was not sent', ['case' => $caseId, 'exception' => $e->getMessage()]);
			if ($claim !== '') {
				$this->ledger->release(key: $claim);
			}

			return new ActionResult(succeeded: false, error: 'send_message_failed');
		}

		if ($claim !== '') {
			$this->ledger->settle(key: $claim, outcome: 'sent', now: time());
		}

		return new ActionResult(succeeded: true, data: ['sentTo' => $recipients, 'template' => $template]);
	}//end send()

	/**
	 * The ledger key of a send-once message, or '' when it may repeat.
	 *
	 * @param array<string, mixed> $actionConfig `onceKey` and optionally `oncePer`.
	 * @param array<string, mixed> $case         The case.
	 * @param string               $caseId       The case id.
	 *
	 * @return string The 64-character key.
	 */
	private function claimOf(array $actionConfig, array $case, string $caseId): string {
		$once = trim((string)($actionConfig['onceKey'] ?? ''));
		if ($once === '') {
			return '';
		}

		$per = trim((string)($actionConfig['oncePer'] ?? ''));
		$version = '';
		if ($per !== '' && is_scalar($case[$per] ?? null) === true) {
			$version = (string)$case[$per];
		}

		return hash('sha256', 'dossiq:once:' . $once . ':' . $caseId . ':' . $version);
	}//end claimOf()
}//end class
