<?php

/**
 * A mail gateway the tests own.
 *
 * Nextcloud Mail is an optional runtime dependency and is not installed in the
 * build environment, so nothing in the unit suite may reach a real `OCA\Mail`
 * symbol. It must also not STUB one: a stub named `OCA\Mail\Service\MailManager`
 * would be a second file in the tree naming the app dossiq is supposed to touch
 * in exactly one place, and `MailCouplingTest` would have to carve out an
 * exception for the very thing it exists to count.
 *
 * So the fake implements dossiq's own {@see MailGatewayInterface} and the
 * coupling stays at one file.
 *
 * IT IS A FAKE, NOT A MOCK: it holds messages, folders and a set of message ids
 * the account really sent, and answers consistently from them. That matters for
 * the threading check in particular, where the interesting case is a message id
 * this account never held, and a mock configured to return `false` proves only
 * that `false` was configured.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Support
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
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Support;

use OCA\Dossiq\Service\Email\AuthenticationResult;
use OCA\Dossiq\Service\Email\MailGatewayInterface;

/**
 * An in-memory mail account the intake tests read.
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */
class FakeMailGateway implements MailGatewayInterface {

	/**
	 * Whether this gateway pretends a mail app is installed.
	 *
	 * @var boolean
	 */
	public bool $available = true;

	/**
	 * The accounts.
	 *
	 * @var array<int, array{id: int, name: string, email: string}>
	 */
	public array $accounts = [
		['id' => 7, 'name' => 'Zaken', 'email' => 'zaken@gemeente.nl'],
	];

	/**
	 * The folders, by account id.
	 *
	 * @var array<int, array<int, string>>
	 */
	public array $mailboxes = [7 => ['INBOX', 'Archief', 'Team B']];

	/**
	 * The message rows, by "accountId|mailbox".
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	public array $messages = [];

	/**
	 * The raw sources, by "accountId|mailbox|uid".
	 *
	 * @var array<string, string>
	 */
	public array $sources = [];

	/**
	 * The message ids this account really holds.
	 *
	 * @var array<int, string>
	 */
	public array $heldMessageIds = [];

	/**
	 * The addresses the trusted-sender list carries.
	 *
	 * @var array<int, string>
	 */
	public array $trustedSenders = [];

	/**
	 * The DKIM verdict to answer for any source that carries a signature.
	 *
	 * @var string
	 */
	public string $dkim = AuthenticationResult::NONE;

	/**
	 * Every move that was asked for, in order.
	 *
	 * @var array<int, array{accountId: int, mailbox: string, uid: int, target: string}>
	 */
	public array $moves = [];

	/**
	 * The event to unpack, or null.
	 *
	 * @var array{accountId: int, mailbox: string, messages: array<int, array<string, mixed>>}|null
	 */
	public ?array $synchronisation = null;

	/**
	 * Whether a mail app is installed.
	 *
	 * @return boolean True when it is.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function isAvailable(): bool {
		return $this->available;
	}//end isAvailable()

	/**
	 * The accounts.
	 *
	 * @return array<int, array{id: int, name: string, email: string}> The accounts.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function accounts(): array {
		if ($this->available === false) {
			return [];
		}

		return $this->accounts;
	}//end accounts()

	/**
	 * The folders of one account.
	 *
	 * @param integer $accountId The account.
	 *
	 * @return array<int, string> The folder names.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function mailboxes(int $accountId): array {
		if ($this->available === false) {
			return [];
		}

		return ($this->mailboxes[$accountId] ?? []);
	}//end mailboxes()

	/**
	 * The messages waiting in one folder.
	 *
	 * @param integer $accountId The account.
	 * @param string  $mailbox   The folder.
	 * @param integer $limit     How many at most.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function messages(int $accountId, string $mailbox, int $limit): array {
		if ($this->available === false) {
			return [];
		}

		return array_slice(($this->messages[$accountId . '|' . $mailbox] ?? []), 0, $limit);
	}//end messages()

	/**
	 * The raw source of a message.
	 *
	 * @param integer $accountId The account.
	 * @param string  $mailbox   The folder.
	 * @param integer $uid       The uid.
	 *
	 * @return string The source, or ''.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function source(int $accountId, string $mailbox, int $uid): string {
		if ($this->available === false) {
			return '';
		}

		return ($this->sources[$accountId . '|' . $mailbox . '|' . $uid] ?? '');
	}//end source()

	/**
	 * Whether this account holds a message with that id.
	 *
	 * @param integer $accountId The account.
	 * @param string  $messageId The message id.
	 *
	 * @return boolean True only when it really does.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function holdsMessageId(int $accountId, string $messageId): bool {
		if ($this->available === false) {
			return false;
		}

		unset($accountId);

		return in_array(trim($messageId, " \t<>"), $this->heldMessageIds, true);
	}//end holdsMessageId()

	/**
	 * File a message in another folder.
	 *
	 * @param integer $accountId The account.
	 * @param string  $mailbox   The folder it is in.
	 * @param integer $uid       The uid.
	 * @param string  $target    The folder it goes to.
	 *
	 * @return boolean True when the folder exists.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function moveMessage(int $accountId, string $mailbox, int $uid, string $target): bool {
		if ($this->available === false) {
			return false;
		}

		if (in_array($target, $this->mailboxes($accountId), true) === false) {
			return false;
		}

		$this->moves[] = [
			'accountId' => $accountId,
			'mailbox' => $mailbox,
			'uid' => $uid,
			'target' => $target,
		];

		$key = $accountId . '|' . $mailbox;
		$targetKey = $accountId . '|' . $target;
		foreach (($this->messages[$key] ?? []) as $index => $row) {
			if ((int)($row['uid'] ?? 0) !== $uid) {
				continue;
			}

			$moved = $row;
			$moved['mailbox'] = $target;
			$this->messages[$targetKey][] = $moved;
			unset($this->messages[$key][$index]);
			$this->messages[$key] = array_values($this->messages[$key]);
			break;
		}

		return true;
	}//end moveMessage()

	/**
	 * The DKIM verdict.
	 *
	 * @param string $source The raw source.
	 *
	 * @return string One of the {@see AuthenticationResult} values.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function dkimResult(string $source): string {
		if ($this->available === false) {
			return AuthenticationResult::UNAVAILABLE;
		}

		if (stripos($source, 'DKIM-Signature:') === false) {
			return AuthenticationResult::NONE;
		}

		return $this->dkim;
	}//end dkimResult()

	/**
	 * Whether the trusted-sender list carries this address.
	 *
	 * @param string $email The address.
	 *
	 * @return boolean True when it does.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function isTrustedSender(string $email): bool {
		if ($this->available === false) {
			return false;
		}

		return in_array(strtolower(trim($email)), $this->trustedSenders, true);
	}//end isTrustedSender()

	/**
	 * The attachments of a message.
	 *
	 * @param integer $accountId The account.
	 * @param string  $mailbox   The folder.
	 * @param integer $uid       The uid.
	 *
	 * @return array<int, array{name: string, mimeType: string, size: int}> The attachments.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function attachments(int $accountId, string $mailbox, int $uid): array {
		unset($accountId, $mailbox, $uid);

		return [];
	}//end attachments()

	/**
	 * Unpack a synchronisation event.
	 *
	 * @param object $event The event.
	 *
	 * @return array{accountId: int, mailbox: string, messages: array<int, array<string, mixed>>}|null The unpacked event.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function unpackSynchronisation(object $event): ?array {
		unset($event);

		if ($this->available === false) {
			return null;
		}

		return $this->synchronisation;
	}//end unpackSynchronisation()

	/**
	 * Put one message in a folder, with its raw source.
	 *
	 * @param integer $accountId The account.
	 * @param string  $mailbox   The folder.
	 * @param integer $uid       The uid.
	 * @param string  $source    The raw source.
	 *
	 * @return array<string, mixed> The row, as the gateway would answer it.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function deliver(int $accountId, string $mailbox, int $uid, string $source): array {
		$row = [
			'id' => $uid,
			'uid' => $uid,
			'mailbox' => $mailbox,
			'accountId' => $accountId,
			'messageId' => '',
			'subject' => '',
			'sentAt' => '',
		];

		if (preg_match('/^Subject:\s*(.*)$/mi', $source, $matches) === 1) {
			$row['subject'] = trim($matches[1]);
		}

		if (preg_match('/^Message-ID:\s*(.*)$/mi', $source, $matches) === 1) {
			$row['messageId'] = trim($matches[1], " \t<>");
		}

		$this->messages[$accountId . '|' . $mailbox][] = $row;
		$this->sources[$accountId . '|' . $mailbox . '|' . $uid] = $source;

		return $row;
	}//end deliver()
}//end class
