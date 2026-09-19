<?php

/**
 * Dossiq Mail Gateway Contract
 *
 * The mail-shaped things intake needs, named in dossiq's own vocabulary so that
 * nothing outside {@see \OCA\Dossiq\Service\Email\NextcloudMailGateway} has to
 * know which app provides them.
 *
 * Nextcloud Mail publishes no `OCP` interface, so every symbol dossiq reaches
 * for there is another app's internal API and a Mail release can move it
 * (design D-1). This interface is the seam that keeps the blast radius at one
 * file: the pipeline, the verdicts and the actions depend on this contract, the
 * gateway depends on Mail, and a test supplies a fake.
 *
 * Every method fails soft. An instance without the Mail app answers "nothing"
 * rather than throwing, because intake runs on a cron and a throw there is an
 * outage nobody sees.
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
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

/**
 * What dossiq asks a mail app for.
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */
interface MailGatewayInterface {

	/**
	 * Whether a mail app is installed and answering.
	 *
	 * @return boolean True when intake can read messages.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function isAvailable(): bool;

	/**
	 * The mail accounts an administrator can choose between.
	 *
	 * @return array<int, array{id: int, name: string, email: string}> The accounts.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function accounts(): array;

	/**
	 * The folders of one account.
	 *
	 * @param integer $accountId The account.
	 *
	 * @return array<int, string> The folder names.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function mailboxes(int $accountId): array;

	/**
	 * The unprocessed messages waiting in one folder.
	 *
	 * @param integer $accountId The account.
	 * @param string  $mailbox   The folder.
	 * @param integer $limit     How many at most.
	 *
	 * @return array<int, array<string, mixed>> Normalised message rows.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function messages(int $accountId, string $mailbox, int $limit): array;

	/**
	 * The raw source of a message, headers included.
	 *
	 * @param integer $accountId The account.
	 * @param string  $mailbox   The folder.
	 * @param integer $uid       The message uid.
	 *
	 * @return string The source, or '' when it cannot be read.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function source(int $accountId, string $mailbox, int $uid): string;

	/**
	 * Whether this account holds a message with that id.
	 *
	 * The threading check rests on this and nothing else: only the account that
	 * sent a message knows it sent it.
	 *
	 * @param integer $accountId The account.
	 * @param string  $messageId The RFC 5322 message id, angle brackets optional.
	 *
	 * @return boolean True only when the account really holds it.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function holdsMessageId(int $accountId, string $messageId): bool;

	/**
	 * File a message in another folder of the same account.
	 *
	 * @param integer $accountId The account.
	 * @param string  $mailbox   The folder it is in.
	 * @param integer $uid       The message uid.
	 * @param string  $target    The folder it goes to.
	 *
	 * @return boolean True when the mail server confirmed the move.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function moveMessage(int $accountId, string $mailbox, int $uid, string $target): bool;

	/**
	 * The DKIM verdict the mail app reaches for a raw message.
	 *
	 * @param string $source The raw message source.
	 *
	 * @return string One of the {@see AuthenticationResult} values.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function dkimResult(string $source): string;

	/**
	 * Whether the mail app's trusted-sender list carries this address.
	 *
	 * The allow half of the administered list is read here rather than copied
	 * into dossiq (design D-9).
	 *
	 * @param string $email The sender address.
	 *
	 * @return boolean True when the address is trusted.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function isTrustedSender(string $email): bool;

	/**
	 * The attachments of a message.
	 *
	 * @param integer $accountId The account.
	 * @param string  $mailbox   The folder.
	 * @param integer $uid       The message uid.
	 *
	 * @return array<int, array{name: string, mimeType: string, size: int}> The attachments.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function attachments(int $accountId, string $mailbox, int $uid): array;

	/**
	 * Unpack a mail-app synchronisation event into normalised message rows.
	 *
	 * The event class is the mail app's, so only the gateway may name it.
	 *
	 * @param object $event The dispatched event.
	 *
	 * @return array{accountId: int, mailbox: string, messages: array<int, array<string, mixed>>}|null
	 *         The unpacked event, or null when it is not one intake reads.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function unpackSynchronisation(object $event): ?array;
}//end interface
