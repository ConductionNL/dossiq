<?php

/**
 * Dossiq outbound recipient allow-list.
 *
 * Answers one question: may dossiq send case mail to this address? It is the
 * whole of the recipient policy, so the service that dispatches mail states the
 * intent and this class holds the rule.
 *
 * The list is deliberately never empty. An allow-list that is empty and treated
 * as "no restriction" is not a control at all, and an allow-list that is empty
 * and rejects everything stops outbound mail on every instance that never
 * configured one. So when the operator has set nothing, the list defaults to the
 * envelope from-address's own domain: dossiq may mail the organisation it sends
 * as, and any recipient outside that domain is an explicit operator decision.
 *
 * `email_from_address` is already mandatory — `CaseEmailService` refuses to send
 * without it — so the default is always derivable on any instance that can send
 * at all.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Email
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

use OCA\Dossiq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Resolves and applies the outbound recipient allow-list.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/case-management/spec.md
 */
class RecipientAllowlist {

	/**
	 * App-config key holding the operator's allow-list.
	 *
	 * Comma-, semicolon- or newline-separated. Each entry is a full address
	 * (`team@gemeente.nl`), a domain (`@gemeente.nl`, or bare `gemeente.nl`),
	 * or `*` to allow every recipient.
	 */
	public const CONFIG_KEY = 'email_recipient_allowlist';

	/**
	 * The entry that switches the allow-list off entirely.
	 */
	public const WILDCARD = '*';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Nextcloud app config
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Resolve the effective allow-list entries.
	 *
	 * Returns the operator's configured entries when there are any, and
	 * otherwise the default: the from-address's own domain. Only an unparsable
	 * from-address yields an empty list, and an empty list rejects everything.
	 *
	 * @param string $fromAddress The resolved envelope from-address
	 *
	 * @return array<string> Normalised entries (`*`, `@domain` or a full address)
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function entriesFor(string $fromAddress): array {
		$configured = $this->appConfig->getValueString(
			Application::APP_ID,
			self::CONFIG_KEY,
			'',
		);

		$entries = $this->normalizeEntries(raw: $configured);
		if ($entries !== []) {
			return $entries;
		}

		$domain = $this->domainOf(address: $fromAddress);
		if ($domain === null) {
			return [];
		}

		return ['@' . $domain];
	}//end entriesFor()

	/**
	 * Whether the operator has switched the allow-list off.
	 *
	 * @param array<string> $entries Normalised entries from entriesFor()
	 *
	 * @return bool True when every recipient is allowed
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function isOpen(array $entries): bool {
		return in_array(self::WILDCARD, $entries, true);
	}//end isOpen()

	/**
	 * Whether a recipient may be sent to.
	 *
	 * Fails closed: a recipient that matches neither the allow-list entries nor
	 * a contact registered on the case is rejected, and both sources being empty
	 * rejects rather than permits.
	 *
	 * @param string $recipient The recipient address (any case)
	 * @param array<string> $entries Normalised entries from entriesFor()
	 * @param array<string> $caseContacts Lowercase addresses registered on the case
	 *
	 * @return bool True when the recipient is allowed
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function permits(string $recipient, array $entries, array $caseContacts): bool {
		$address = strtolower(trim($recipient));
		if ($address === '') {
			return false;
		}

		if ($this->isOpen(entries: $entries) === true) {
			return true;
		}

		if (in_array($address, $caseContacts, true) === true) {
			return true;
		}

		return $this->matchesEntry(address: $address, entries: $entries);
	}//end permits()

	/**
	 * Match an address against the normalised entries.
	 *
	 * @param string $address The lowercase recipient address
	 * @param array<string> $entries Normalised entries
	 *
	 * @return bool True when one entry matches
	 */
	private function matchesEntry(string $address, array $entries): bool {
		$domain = $this->domainOf(address: $address);

		foreach ($entries as $entry) {
			if (str_starts_with($entry, '@') === true) {
				if ($domain !== null && $entry === ('@' . $domain)) {
					return true;
				}

				continue;
			}

			if ($entry === $address) {
				return true;
			}
		}

		return false;
	}//end matchesEntry()

	/**
	 * Split and normalise a raw configured allow-list.
	 *
	 * A bare domain is read as a domain entry, so `gemeente.nl` and
	 * `@gemeente.nl` mean the same thing and an operator cannot silently write
	 * an entry that matches nothing.
	 *
	 * @param string $raw The configured value
	 *
	 * @return array<string> Normalised entries, in configured order
	 */
	private function normalizeEntries(string $raw): array {
		$parts = preg_split('/[\s,;]+/', strtolower(trim($raw)));
		if ($parts === false) {
			return [];
		}

		$entries = [];
		foreach ($parts as $part) {
			$entry = trim($part);
			if ($entry === '') {
				continue;
			}

			if ($entry === self::WILDCARD) {
				$entries[] = self::WILDCARD;
				continue;
			}

			if (str_starts_with($entry, '@') === false && str_contains($entry, '@') === false) {
				$entry = '@' . $entry;
			}

			$entries[] = $entry;
		}

		return array_values(array_unique($entries));
	}//end normalizeEntries()

	/**
	 * Extract the domain part of an address.
	 *
	 * @param string $address The address
	 *
	 * @return string|null The lowercase domain, or null when there is none
	 */
	private function domainOf(string $address): ?string {
		$atPos = strrpos($address, '@');
		if ($atPos === false) {
			return null;
		}

		$domain = strtolower(trim(substr($address, ($atPos + 1))));
		if ($domain === '') {
			return null;
		}

		return $domain;
	}//end domainOf()
}//end class
