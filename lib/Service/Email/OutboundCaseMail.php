<?php

/**
 * Dossiq Outbound Case Mail
 *
 * Sends a message a handler wrote about a case through a Nextcloud Mail account.
 *
 * 🔴 ONLY HANDLER-WRITTEN CASE MAIL COMES HERE (decision 165). Term notices and
 * other service mail keep going through Nextcloud's IMailer, because they must
 * carry the RFC 8058 List-Unsubscribe headers and Nextcloud Mail builds its own
 * headers and takes no custom one. A case mail a handler wrote is
 * correspondence: it belongs in the account's sent folder, next to the
 * replies, which only a send through the account itself gives. Its unsubscribe
 * link stays in the body. When Nextcloud Mail accepts custom headers, all mail
 * moves here (decision 147, change
 * case-mail-through-the-mail-account-with-rfc-8058).
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

use OCA\Dossiq\Exception\RefusedException;

/**
 * One send of a case message through the case's Mail account.
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md#requirement-outbound-mail-leaves-through-the-same-account-with-no-dossiq-credential-req-imf-11
 */
class OutboundCaseMail {

	/**
	 * Constructor.
	 *
	 * @param MailGatewayInterface $gateway  The mail app.
	 * @param SenderIdentity       $identity Which account a case's mail leaves from.
	 */
	public function __construct(
		private readonly MailGatewayInterface $gateway,
		private readonly SenderIdentity $identity,
	) {
	}//end __construct()

	/**
	 * The address a message about this case would leave from.
	 *
	 * Asked before sending, so the recipient policy reads the real sender.
	 *
	 * @param array<string, mixed> $caseData The raw case record.
	 *
	 * @return array{id: int, name: string, email: string} The account.
	 *
	 * @throws RefusedException sender-not-held or mail-account-unavailable.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md#requirement-a-teams-mail-carries-that-teams-sender-identity-req-imf-12
	 */
	public function accountFor(array $caseData): array {
		return $this->identity->accountForCase(caseData: $caseData);
	}//end accountFor()

	/**
	 * Send one message through an account.
	 *
	 * @param array{id: int, name: string, email: string} $account     The account, from accountFor().
	 * @param string                                      $to          The recipient.
	 * @param string                                      $subject     The subject.
	 * @param string                                      $html        The HTML body, unsubscribe link included.
	 * @param string                                      $plain       The plain body, unsubscribe link included.
	 * @param array<int, string>                          $attachments Paths in the sending user's own files.
	 *
	 * @return array{state: string, outboxId: int|null} SENT, SENT_NOT_FILED or QUEUED.
	 *
	 * @throws RefusedException mail-account-unavailable when the mail app took nothing.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md#requirement-outbound-mail-leaves-through-the-same-account-with-no-dossiq-credential-req-imf-11
	 */
	public function send(
		array $account,
		string $to,
		string $subject,
		string $html,
		string $plain,
		array $attachments = [],
	): array {
		$result = $this->gateway->sendMessage(
			accountId: (int)$account['id'],
			message: [
				'to' => [$to],
				'subject' => $subject,
				'html' => $html,
				'plain' => $plain,
				'attachments' => array_values(array_map('strval', $attachments)),
			]
		);

		if ($result['state'] === OutboundState::UNAVAILABLE) {
			throw new RefusedException(
				rule: 'mail-account-unavailable',
				sentence: 'The mail account ' . $account['email'] . ' cannot be reached, so the message was not sent. Nothing was lost: try again later.',
				status: RefusedException::STATUS_INDETERMINATE
			);
		}

		return $result;
	}//end send()
}//end class
