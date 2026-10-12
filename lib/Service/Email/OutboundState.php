<?php

/**
 * Dossiq Outbound State
 *
 * What became of a message handed to a Nextcloud Mail account.
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

/**
 * The four answers a send through a mail account can give.
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md#requirement-outbound-mail-leaves-through-the-same-account-with-no-dossiq-credential-req-imf-11
 */
final class OutboundState {

	/**
	 * Sent, and the copy is in the account's sent folder.
	 */
	public const SENT = 'sent';

	/**
	 * Sent, but the copy could not be filed (no sent folder, or the IMAP write failed).
	 */
	public const SENT_NOT_FILED = 'sent-not-filed';

	/**
	 * Taken into the mail app's outbox but not sent yet; the mail app retries it.
	 */
	public const QUEUED = 'queued';

	/**
	 * Not taken at all: the mail app or the account could not be reached.
	 */
	public const UNAVAILABLE = 'unavailable';

	/**
	 * Whether a state means the recipient has the message.
	 *
	 * @param string $state One of the constants.
	 *
	 * @return boolean True for SENT and SENT_NOT_FILED.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md#requirement-outbound-mail-leaves-through-the-same-account-with-no-dossiq-credential-req-imf-11
	 */
	public static function isSent(string $state): bool {
		return in_array($state, [self::SENT, self::SENT_NOT_FILED], true);
	}//end isSent()
}//end class
