<?php

/**
 * Dossiq MailMessageCalls.
 *
 * The calls NextcloudMailGateway makes on Nextcloud Mail's message manager for
 * one account: read a message's source, look a message id up, move a message
 * and list its attachments. Each call is wrapped, so a Mail release that throws
 * degrades to the documented empty answer instead of failing a cron run. It
 * names no `OCA\Mail` symbol (design D-1): the gateway resolves the account
 * and the manager and hands them in as one pair.
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
 * @spec openspec/specs/inbound-mail-filters/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

use Closure;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Calls on Mail's message manager, built and read by NextcloudMailGateway only.
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class MailMessageCalls {

	/**
	 * Constructor.
	 *
	 * @param MailEntityValues $values Reads the attachment rows Mail answers.
	 * @param LoggerInterface  $logger Logger.
	 */
	public function __construct(
		private readonly MailEntityValues $values,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The raw source of a message, headers included.
	 *
	 * @param ThreadingLookup $call          The account and Mail's manager.
	 * @param Closure         $clientFactory Resolves Mail's IMAP client factory: fn (): ?object.
	 * @param array{0: int, 1: string, 2: int} $message The account id, the folder and the uid.
	 *
	 * @return string The source, or '' when it cannot be read.
	 *
	 * @spec openspec/specs/inbound-mail-filters/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function source(ThreadingLookup $call, Closure $clientFactory, array $message): string {
		[$accountId, $mailbox, $uid] = $message;
		try {
			$source = (new MailSourceReader())->read(
				manager: $call->manager,
				clientFactory: $clientFactory,
				account: $call->account,
				mailbox: $mailbox,
				uid: $uid
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: reading a message source failed',
				['account' => $accountId, 'uid' => $uid, 'error' => $e->getMessage()]
			);
			return '';
		}

		if (is_string($source) === false) {
			return '';
		}

		return $source;
	}//end source()

	/**
	 * Whether Mail finds a message with this id in the account.
	 *
	 * 🔴 A LOOKUP THAT COULD NOT RUN IS "NO". This is the check that stops a
	 * forged `In-Reply-To` reaching somebody else's case.
	 *
	 * @param ThreadingLookup $call      The account and Mail's manager.
	 * @param integer         $accountId The account, for the log line.
	 * @param string          $messageId The bare message id.
	 *
	 * @return boolean True only when Mail answered a non-empty list.
	 *
	 * @spec openspec/specs/inbound-mail-filters/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function holds(ThreadingLookup $call, int $accountId, string $messageId): bool {
		try {
			$found = $call->manager->getByMessageId($call->account, $messageId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: the threading lookup failed, so the claim stays unproven',
				['account' => $accountId, 'error' => $e->getMessage()]
			);
			return false;
		}

		return (is_array($found) === true && $found !== []);
	}//end holds()

	/**
	 * File a message in another folder of the same account.
	 *
	 * @param ThreadingLookup $call The account and Mail's manager.
	 * @param array{0: int, 1: string, 2: int, 3: string} $move The account id, the folder it is in, the uid and the folder it goes to.
	 *
	 * @return boolean True when the mail server confirmed the move.
	 *
	 * @spec openspec/specs/inbound-mail-filters/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function move(ThreadingLookup $call, array $move): bool {
		[$accountId, $mailbox, $uid, $target] = $move;
		try {
			$call->manager->moveMessage($call->account, $mailbox, $uid, $call->account, $target);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: moving a message between folders failed',
				['account' => $accountId, 'uid' => $uid, 'target' => $target, 'error' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end move()

	/**
	 * The attachments of a message.
	 *
	 * @param ThreadingLookup $call The account and Mail's manager.
	 * @param array{0: int, 1: string, 2: int} $message The account id, the folder and the uid.
	 *
	 * @return array<int, array{name: string, mimeType: string, size: int}> The attachments.
	 *
	 * @spec openspec/specs/inbound-mail-filters/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function attachments(ThreadingLookup $call, array $message): array {
		[$accountId, $mailbox, $uid] = $message;
		try {
			$found = $call->manager->getMailAttachments($call->account, $mailbox, $uid);
		} catch (Throwable $e) {
			$context = ['account' => $accountId, 'uid' => $uid, 'error' => $e->getMessage()];
			$this->logger->warning('Dossiq: reading attachments failed', $context);
			return [];
		}

		return $this->values->attachmentRows(found: $found);
	}//end attachments()
}//end class
