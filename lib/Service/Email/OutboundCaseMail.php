<?php

/**
 * Dossiq Outbound Case Mail
 *
 * Sends one outbound message about a case through the transport its kind is configured for.
 *
 * THE TRANSPORT IS CONFIGURATION (decisions 165 and 182). {@see MailTransportPolicy}
 * says per kind of mail whether it leaves through the Nextcloud Mail account
 * (filed in its sent folder, no custom headers, unsubscribe link in the body)
 * or through Nextcloud's IMailer (RFC 8058 `List-Unsubscribe` headers, not
 * filed). The default sends a handler's case mail through the Mail account and
 * notices and service mail through IMailer. When Nextcloud Mail accepts custom
 * headers, every kind moves to the Mail account by configuration (decision
 * 147, change case-mail-through-the-mail-account-with-rfc-8058).
 *
 * dossiq stores no SMTP password, runs no OAuth flow and opens no SMTP
 * connection: Nextcloud Mail sends on the account's own authentication.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Email
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md#requirement-outbound-mail-leaves-through-the-same-account-with-no-dossiq-credential-req-imf-11
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Exception\RefusedException;
use OCP\IAppConfig;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One send of a case message, through the transport its kind is configured for.
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md#requirement-outbound-mail-leaves-through-the-same-account-with-no-dossiq-credential-req-imf-11
 */
class OutboundCaseMail {

	/**
	 * Constructor.
	 *
	 * @param MailGatewayInterface $gateway   The mail app.
	 * @param SenderIdentity       $identity  Which account a case's mail leaves from.
	 * @param MailTransportPolicy  $policy    Which transport each kind leaves through.
	 * @param CaseMailOptOut       $optOut    Places the unsubscribe link and headers.
	 * @param IMailer              $mailer    Nextcloud's mailer, for kinds configured to it.
	 * @param IAppConfig           $appConfig The sender address IMailer sends from.
	 * @param LoggerInterface      $logger    Logger.
	 */
	public function __construct(
		private readonly MailGatewayInterface $gateway,
		private readonly SenderIdentity $identity,
		private readonly MailTransportPolicy $policy,
		private readonly CaseMailOptOut $optOut,
		private readonly IMailer $mailer,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Who a message of this kind about this case would leave from.
	 *
	 * Asked before sending, so the recipient policy reads the real sender.
	 *
	 * @param array<string, mixed> $caseData The raw case record.
	 * @param string               $kind     The kind of mail, a MailTransportPolicy KIND_ value.
	 *
	 * @return array{transport: string, from: string, account: array{id: int, name: string, email: string}|null}
	 *         The sender.
	 *
	 * @throws RefusedException sender-not-held, mail-account-unavailable or mail-sender-not-configured.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md#requirement-a-teams-mail-carries-that-teams-sender-identity-req-imf-12
	 */
	public function senderFor(array $caseData, string $kind): array {
		$transport = $this->policy->transportFor(kind: $kind);
		if ($transport === MailTransportPolicy::TRANSPORT_MAIL_ACCOUNT) {
			$account = $this->identity->accountForCase(caseData: $caseData);

			return ['transport' => $transport, 'from' => (string)$account['email'], 'account' => $account];
		}

		$from = trim($this->appConfig->getValueString(Application::APP_ID, 'email_from_address', ''));
		if ($from === '' || str_ends_with($from, '@example.nl') === true) {
			throw new RefusedException(
				rule: 'mail-sender-not-configured',
				sentence: 'No sender address is set for mail that leaves through Nextcloud\'s mailer. Set it in the dossiq mail settings.',
				status: RefusedException::STATUS_INDETERMINATE
			);
		}

		return ['transport' => $transport, 'from' => $from, 'account' => null];
	}//end senderFor()

	/**
	 * Send one message from a sender senderFor() answered.
	 *
	 * @param array<string, mixed>     $sender      From senderFor().
	 * @param string                   $to          The recipient.
	 * @param string                   $subject     The subject.
	 * @param string                   $body        The HTML body as written.
	 * @param array<string,mixed>|null $unsubscribe integriq's link material, or null for an exempt mail.
	 * @param array<int, string>       $attachments Paths in the sending user's own files.
	 *
	 * @return array{state: string, outboxId: int|null} SENT, SENT_NOT_FILED or QUEUED.
	 *
	 * @throws RefusedException mail-account-unavailable, mail-transport-unavailable or attachments-need-mail-account.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md#requirement-outbound-mail-leaves-through-the-same-account-with-no-dossiq-credential-req-imf-11
	 */
	public function send(
		array $sender,
		string $to,
		string $subject,
		string $body,
		?array $unsubscribe,
		array $attachments = [],
	): array {
		if ($sender['transport'] === MailTransportPolicy::TRANSPORT_MAIL_ACCOUNT) {
			return $this->throughAccount(
				account: (array)$sender['account'],
				to: $to,
				subject: $subject,
				bodies: $this->optOut->bodies(body: $body, unsubscribe: $unsubscribe),
				attachments: $attachments
			);
		}

		if ($attachments !== []) {
			throw new RefusedException(
				rule: 'attachments-need-mail-account',
				sentence: 'Attachments can only be sent through a Nextcloud Mail account, and this kind of mail is set to leave through Nextcloud\'s mailer.',
				status: RefusedException::STATUS_UNPROCESSABLE
			);
		}

		$message = $this->mailer->createMessage();
		$message->setFrom(
			[(string)$sender['from'] => $this->appConfig->getValueString(Application::APP_ID, 'email_from_name', 'Dossiq')]
		);
		$message->setTo([$to]);
		$message->setSubject($subject);
		$this->optOut->dress(message: $message, body: $body, unsubscribe: $unsubscribe);

		try {
			$this->mailer->send($message);
		} catch (Throwable $e) {
			// M4: the transport's text can carry the SMTP host; it stays in the log.
			$this->logger->error(
				'Dossiq: Nextcloud\'s mailer refused a case message',
				['app' => Application::APP_ID, 'exception' => $e]
			);
			throw new RefusedException(
				rule: 'mail-transport-unavailable',
				sentence: 'The mail server did not take the message, so it was not sent. Try again later.',
				status: RefusedException::STATUS_INDETERMINATE,
				previous: $e
			);
		}

		return ['state' => OutboundState::SENT, 'outboxId' => null];
	}//end send()

	/**
	 * Hand one message to the Mail account.
	 *
	 * @param array<string, mixed>               $account     The account.
	 * @param string                             $to          The recipient.
	 * @param string                             $subject     The subject.
	 * @param array{html: string, plain: string} $bodies      The bodies, link included.
	 * @param array<int, string>                 $attachments Paths in the sending user's own files.
	 *
	 * @return array{state: string, outboxId: int|null} SENT, SENT_NOT_FILED or QUEUED.
	 *
	 * @throws RefusedException mail-account-unavailable when the mail app took nothing.
	 */
	private function throughAccount(array $account, string $to, string $subject, array $bodies, array $attachments): array {
		$result = $this->gateway->sendMessage(
			accountId: (int)($account['id'] ?? 0),
			message: [
				'to' => [$to],
				'subject' => $subject,
				'html' => $bodies['html'],
				'plain' => $bodies['plain'],
				'attachments' => array_values(array_map('strval', $attachments)),
			]
		);

		if ($result['state'] === OutboundState::UNAVAILABLE) {
			throw new RefusedException(
				rule: 'mail-account-unavailable',
				sentence: 'The mail account ' . (string)($account['email'] ?? '')
					. ' cannot be reached, so the message was not sent. Nothing was lost: try again later.',
				status: RefusedException::STATUS_INDETERMINATE
			);
		}

		return $result;
	}//end throughAccount()
}//end class
