<?php

/**
 * Resolves a host name to every address it answers with
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Outbound
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
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Outbound;

use OCA\Dossiq\Support\SuppressesWarnings;

/**
 * The name lookup behind {@see OutboundUrlGuard}.
 *
 * Asks both places a name can be answered from and returns the union. The
 * system resolver sees the hosts file and a container's embedded resolver,
 * which is how an instance reaches a sidecar by service name. A DNS query sees
 * AAAA records, which the system call cannot return. A guard that asked only
 * one of the two judged a name on half of its addresses.
 *
 * Its own class so a test can put a name's answers in by hand.
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class HostResolver {
	use SuppressesWarnings;

	/**
	 * Every address a host name answers with.
	 *
	 * @param string $host The host name, lower-cased, not an IP literal.
	 *
	 * @return string[] The addresses, without duplicates; empty when nothing answers.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function addressesOf(string $host): array {
		$addresses = $this->withoutWarnings(
			operation: static function () use ($host): mixed {
				return gethostbynamel($host);
			}
		);
		if (is_array($addresses) === false) {
			$addresses = [];
		}

		$records = $this->withoutWarnings(
			operation: static function () use ($host): mixed {
				return dns_get_record($host, (DNS_A | DNS_AAAA));
			}
		);
		if (is_array($records) === false) {
			$records = [];
		}

		foreach ($records as $record) {
			$address = ($record['ip'] ?? ($record['ipv6'] ?? null));
			if (is_string($address) === true) {
				$addresses[] = $address;
			}
		}

		return array_values(array_unique($addresses));
	}//end addressesOf()

	/**
	 * What the last lookup complained about, for the log line of a refusal.
	 *
	 * @return string The diagnostic, or '' when there was none.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function lastWarning(): string {
		return $this->lastSuppressedWarning();
	}//end lastWarning()
}//end class
