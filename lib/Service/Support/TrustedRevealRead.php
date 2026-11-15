<?php

/**
 * Runs a server-side read that needs a value OpenRegister shows only on request.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bsn-reveal-logged/specs/party-pages/spec.md#requirement-the-bsn-is-shown-through-a-logged-server-reveal
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

use Throwable;

/**
 * A BSN is an explicit-reveal value in OpenRegister (property-explicit-reveal):
 * every read route answers it masked, and filtering on it is refused, unless
 * server code asks for it on purpose through `ExplicitReveal::trustedDuring()`.
 * dossiq's own server code that needs the number (the requester projection,
 * finding a household's people by BSN) runs through this. On an OpenRegister
 * without explicit reveal the read simply runs.
 *
 * @spec openspec/changes/bsn-reveal-logged/specs/party-pages/spec.md#requirement-the-bsn-is-shown-through-a-logged-server-reveal
 */
class TrustedRevealRead {

	/**
	 * The OpenRegister class that holds the trust.
	 */
	public const REVEAL_CLASS = 'OCA\\OpenRegister\\Service\\Rbac\\ExplicitReveal';

	/**
	 * Constructor.
	 *
	 * @param object|null $reveal OpenRegister's ExplicitReveal; looked up when not given.
	 */
	public function __construct(
		private readonly ?object $reveal = null,
	) {
	}//end __construct()

	/**
	 * Run the read trusted when OpenRegister knows explicit reveal, else as is.
	 *
	 * @param callable $read The read.
	 *
	 * @return mixed What the read returns.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) `\OCP\Server::get()` is the public container lookup for another app's service.
	 *
	 * @spec openspec/changes/bsn-reveal-logged/specs/party-pages/spec.md#requirement-the-bsn-is-shown-through-a-logged-server-reveal
	 */
	public function run(callable $read): mixed {
		$reveal = $this->reveal;
		if ($reveal === null && class_exists(self::REVEAL_CLASS) === true) {
			try {
				$reveal = \OCP\Server::get(self::REVEAL_CLASS);
			} catch (Throwable) {
				$reveal = null;
			}
		}

		if ($reveal === null || method_exists($reveal, 'trustedDuring') === false) {
			return $read();
		}

		return $reveal->trustedDuring($read);
	}//end run()
}//end class
