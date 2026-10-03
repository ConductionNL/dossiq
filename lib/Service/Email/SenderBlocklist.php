<?php

/**
 * Dossiq Sender Blocklist
 *
 * Who may open a case by mail, administered as one control read from two ends
 * (design D-9). The allow half is Nextcloud Mail's trusted-sender list, read
 * through the gateway rather than copied; the block half is dossiq's own,
 * because blocking who may open a case is a case decision and not a mail
 * decision.
 *
 * 🔴 BLOCKING HERE MUST NOT BLOCK THE MAILBOX. A municipality that blocks a
 * sender in dossiq is saying "this address does not open cases", not "this
 * address may not reach anyone on this instance". Nothing in this class touches
 * Mail's own filtering, and the spec asserts that a blocked sender's mail to a
 * colleague is unaffected.
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

use OCA\Dossiq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * The administered answer to who may open a case by mail.
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
class SenderBlocklist {

	/**
	 * The app-config key holding the block half.
	 */
	public const BLOCKLIST_KEY = 'email_intake_blocklist';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig           $appConfig Instance configuration.
	 * @param MailGatewayInterface $gateway   The mail gateway, for the allow half.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly MailGatewayInterface $gateway,
	) {
	}//end __construct()

	/**
	 * The blocked entries an administrator has written.
	 *
	 * @return array<int, string> The entries, lowercased.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function entries(): array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, self::BLOCKLIST_KEY, '');
		$parts = preg_split('/[\s,;]+/', strtolower(trim($raw)));
		if ($parts === false) {
			return [];
		}

		return array_values(array_unique(array_filter($parts)));
	}//end entries()

	/**
	 * Replace the block half.
	 *
	 * @param array<int, string> $entries The addresses and domains to block.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function replace(array $entries): void {
		$cleaned = [];
		foreach ($entries as $entry) {
			$value = strtolower(trim($entry));
			if ($value === '') {
				continue;
			}

			$cleaned[] = $value;
		}

		$this->appConfig->setValueString(
			Application::APP_ID,
			self::BLOCKLIST_KEY,
			implode(',', array_values(array_unique($cleaned)))
		);
	}//end replace()

	/**
	 * Whether an address may not open a case.
	 *
	 * An entry matches the whole address, or a whole domain when it is written
	 * as `@gemeente.nl`. A bare domain without the `@` is deliberately NOT a
	 * domain match: `example.nl` would otherwise also block
	 * `notexample.nl`, and a blocklist that blocks more than it says is worse
	 * than one that blocks less.
	 *
	 * @param string $email The sender address.
	 *
	 * @return boolean True when the address is blocked.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function blocks(string $email): bool {
		$address = InboundMessage::addressIn(value: $email);
		if ($address === '') {
			return false;
		}

		$domain = strstr($address, '@');
		foreach ($this->entries() as $entry) {
			if ($entry === $address) {
				return true;
			}

			if (str_starts_with($entry, '@') === true && $domain === $entry) {
				return true;
			}
		}

		return false;
	}//end blocks()

	/**
	 * Whether Nextcloud Mail's trusted-sender list carries this address.
	 *
	 * @param string $email The sender address.
	 *
	 * @return boolean True when the address is on the allow half.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function allows(string $email): bool {
		$address = InboundMessage::addressIn(value: $email);
		if ($address === '') {
			return false;
		}

		return $this->gateway->isTrustedSender($address);
	}//end allows()
}//end class
