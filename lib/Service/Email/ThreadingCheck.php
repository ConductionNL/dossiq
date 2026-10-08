<?php

/**
 * Dossiq Threading Check
 *
 * Whether a message's claim to be a reply is true. This is the check no mail
 * server can make for us, because only the account that sent a message knows it
 * sent it (design D-6).
 *
 * 🔴 THIS IS THE FORGERY THE BUILD PLAN MEASURED. `InboundEmailJob` matched a
 * bracketed case tag in the subject and checked nothing else, so a bezwaar
 * could be filed on somebody else's case by typing their case number. Frappe's
 * version of the same gap was measured with a forged `In-Reply-To` landing one
 * customer's mail on another's case.
 *
 * Three answers and each means something different:
 *
 *  - `pass`  — a referenced id is one this account really holds.
 *  - `fail`  — the message names references and this account holds none of them.
 *  - `none`  — the message makes no threading claim, which is what a first
 *              message looks like and is not a failure.
 *
 * `unavailable` is answered when the gateway cannot look, and like everywhere
 * else in this change it is not `pass`.
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
 * Checks a message's threading claim against the account that would answer it.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 *
 * @SuppressWarnings(PHPMD.StaticAccess) — the static calls here are named
 *  constructors and value-object factories (`InboundMessage::fromRow()`,
 *  `FilterVerdict::accept()`, `AuthenticationVerdict::unknown()`), which hold no
 *  state and exist so a caller cannot build a half-built value.
 */
class ThreadingCheck {

	/**
	 * Constructor.
	 *
	 * @param MailGatewayInterface $gateway The mail gateway.
	 */
	public function __construct(
		private readonly MailGatewayInterface $gateway,
	) {
	}//end __construct()

	/**
	 * What this message's threading headers are worth.
	 *
	 * @param InboundMessage $message The message.
	 *
	 * @return string One of the {@see AuthenticationResult} values.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function resultFor(InboundMessage $message): string {
		$referenced = $message->referencedMessageIds();
		if ($referenced === []) {
			return AuthenticationResult::NONE;
		}

		if ($this->gateway->isAvailable() === false) {
			return AuthenticationResult::UNAVAILABLE;
		}

		foreach ($referenced as $messageId) {
			if ($this->gateway->holdsMessageId($message->accountId, $messageId) === true) {
				return AuthenticationResult::PASS;
			}
		}

		return AuthenticationResult::FAIL;
	}//end resultFor()

	/**
	 * Whether a subject tag may be trusted to name this message's case.
	 *
	 * 🔴 A `fail` STOPS A SUBJECT-TAG LINK AND NOTHING ELSE DOES. A message
	 * that claims to be a reply to something this account never sent, and
	 * carries a case tag in its subject, is the exact shape of the forgery. A
	 * message making no claim at all (`none`) is an ordinary first mail and its
	 * tag is still honoured, because that is how a citizen quoting their own
	 * case number reaches their case.
	 *
	 * @param string $threadingResult The result from {@see self::resultFor()}.
	 *
	 * @return boolean False only when the threading result is `fail`.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function allowsSubjectTagLink(string $threadingResult): bool {
		return (AuthenticationResult::isFailure(value: $threadingResult) === false);
	}//end allowsSubjectTagLink()
}//end class
