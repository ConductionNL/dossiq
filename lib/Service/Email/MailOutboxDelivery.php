<?php

/**
 * Dossiq MailOutboxDelivery.
 *
 * Puts one message through Nextcloud Mail's outbox for NextcloudMailGateway:
 * save it for the account, ask Mail to send it, and read back what became of
 * it. It names no `OCA\Mail` symbol (design D-1): the gateway resolves the
 * account and the outbox and builds Mail's outbox row; this class only calls
 * the two outbox verbs on what it is handed. The status values are Mail's
 * numbers for a row, not class names.
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
 * @spec openspec/specs/inbound-mail-filters/spec.md#requirement-outbound-mail-leaves-through-the-same-account-with-no-dossiq-credential-req-imf-11
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

use Closure;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One message through Mail's outbox, built and read by NextcloudMailGateway only.
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class MailOutboxDelivery {

	/**
	 * `LocalMessage::STATUS_IMAP_SENT_MAILBOX_FAIL`: the SMTP send went through, filing the copy did not.
	 */
	private const STATUS_SENT_NOT_FILED = 11;

	/**
	 * `LocalMessage::STATUS_PROCESSED`: sent and filed.
	 */
	private const STATUS_PROCESSED = 12;

	/**
	 * Constructor.
	 *
	 * @param MailEntityValues $values       Reads getters off Mail's rows.
	 * @param Closure          $localMessage Builds Mail's unsaved outbox row: fn (int $accountId, array $message): object; may throw.
	 * @param LoggerInterface  $logger       Logger.
	 */
	public function __construct(
		private readonly MailEntityValues $values,
		private readonly Closure $localMessage,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Save the message in the account's outbox and send it.
	 *
	 * @param array{0: int, 1: object, 2: object} $through The account id, Mail's account and Mail's outbox.
	 * @param array<string, mixed>               $message to, subject, html, plain, attachments.
	 *
	 * @return array{state: string, outboxId: int|null} What became of it; unavailable when Mail would not take it.
	 *
	 * @spec openspec/specs/inbound-mail-filters/spec.md#requirement-outbound-mail-leaves-through-the-same-account-with-no-dossiq-credential-req-imf-11
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function deliver(array $through, array $message): array {
		[$accountId, $account, $outbox] = $through;
		$send = new OutboxSend(account: $account, outbox: $outbox);
		$send->accountId = $accountId;
		if ($this->saveToOutbox(send: $send, message: $message) === false) {
			return ['state' => OutboundState::UNAVAILABLE, 'outboxId' => null];
		}

		return $this->sendFromOutbox(send: $send);
	}//end deliver()

	/**
	 * The message's recipients, as Mail's outbox takes them.
	 *
	 * @param array<string, mixed> $message The message.
	 *
	 * @return array<int, array{email: string}>
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public static function recipientsOf(array $message): array {
		$recipients = [];
		foreach ((array)($message['to'] ?? []) as $address) {
			$recipients[] = ['email' => (string)$address];
		}

		return $recipients;
	}//end recipientsOf()

	/**
	 * The message's attachments: paths in the sender's own files.
	 *
	 * @param array<string, mixed> $message The message.
	 *
	 * @return array<int, array{type: string, fileName: string}>
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public static function attachmentsOf(array $message): array {
		$attachments = [];
		foreach ((array)($message['attachments'] ?? []) as $path) {
			$attachments[] = ['type' => 'cloud', 'fileName' => (string)$path];
		}

		return $attachments;
	}//end attachmentsOf()

	/**
	 * Map the status Mail's send chain left on a message to a delivery state.
	 *
	 * @param integer $status The row's status number, or -1 when it had none.
	 *
	 * @return string SENT, SENT_NOT_FILED or QUEUED.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public static function stateOfStatus(int $status): string {
		if ($status === self::STATUS_PROCESSED) {
			return OutboundState::SENT;
		}

		if ($status === self::STATUS_SENT_NOT_FILED) {
			return OutboundState::SENT_NOT_FILED;
		}

		return OutboundState::QUEUED;
	}//end stateOfStatus()

	/**
	 * Save the message in the account's outbox; false (with a warning) when
	 * Mail would not take it.
	 *
	 * @param OutboxSend           $send    The send.
	 * @param array<string, mixed> $message The message.
	 *
	 * @return bool Whether Mail took it.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function saveToOutbox(OutboxSend $send, array $message): bool {
		$recipients = self::recipientsOf(message: $message);
		$attachments = self::attachmentsOf(message: $message);

		try {
			$send->saved = $send->outbox->saveMessage(
				$send->account,
				($this->localMessage)($send->accountId, $message),
				$recipients,
				[],
				[],
				$attachments
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: Nextcloud Mail did not take the message into its outbox',
				['account' => $send->accountId, 'error' => $e->getMessage()]
			);
			return false;
		}

		$send->outboxId = $this->outboxIdOf(saved: $send->saved);

		return true;
	}//end saveToOutbox()

	/**
	 * The saved outbox row's id, or null when Mail gave none.
	 *
	 * @param object $saved The saved outbox row.
	 *
	 * @return int|null
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function outboxIdOf(object $saved): ?int {
		$outboxId = (int)$this->values->call(entity: $saved, getter: ['getId', 0]);
		if ($outboxId <= 0) {
			return null;
		}

		return $outboxId;
	}//end outboxIdOf()

	/**
	 * Send the saved message; when Mail cannot send it now it stays queued.
	 *
	 * @param OutboxSend $send The send, with its saved row.
	 *
	 * @return array{state: string, outboxId: int|null}
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function sendFromOutbox(OutboxSend $send): array {
		try {
			$sent = $send->outbox->sendMessage($send->saved, $send->account);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: Nextcloud Mail could not send the message now; it stays in the outbox',
				['account' => $send->accountId, 'outboxId' => $send->outboxId, 'error' => $e->getMessage()]
			);
			return ['state' => OutboundState::QUEUED, 'outboxId' => $send->outboxId];
		}

		$status = (int)$this->values->call(entity: $sent, getter: ['getStatus', -1]);

		return ['state' => self::stateOfStatus(status: $status), 'outboxId' => $send->outboxId];
	}//end sendFromOutbox()
}//end class
