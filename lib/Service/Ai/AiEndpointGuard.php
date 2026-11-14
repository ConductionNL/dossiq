<?php

/**
 * Dossiq AI endpoint guard.
 *
 * Which of the two questions of {@see \OCA\Dossiq\Service\Outbound\OutboundUrlGuard}
 * the configured AI model URL is asked before any outbound request is made: a
 * cloud model must be outside the instance's own network, a local model inside it.
 *
 * It answers with the ADDRESS to connect to rather than a boolean, so the check
 * and the connection cannot disagree about which host they meant.
 *
 * The ranges and the address arithmetic used to live here, as one of three
 * copies. They are in the shared guard now; what is left is the one decision
 * that belongs to AI, which is what a model type means.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Ai
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
 * @spec openspec/specs/ai-assistance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Ai;

use OCA\Dossiq\Service\Outbound\OutboundUrlGuard;
use Psr\Log\LoggerInterface;

/**
 * Validates that a configured AI model URL is safe to connect to.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/ai-assistance/spec.md
 */
class AiEndpointGuard {
	/**
	 * The shared outbound-URL guard.
	 *
	 * @var OutboundUrlGuard
	 */
	private OutboundUrlGuard $guard;

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger The logger interface.
	 * @param OutboundUrlGuard|null $guard The shared guard; built on the logger when left out.
	 *
	 * @return void
	 */
	public function __construct(LoggerInterface $logger, ?OutboundUrlGuard $guard = null) {
		$this->guard = ($guard ?? new OutboundUrlGuard(logger: $logger));
	}//end __construct()

	/**
	 * Resolve the configured AI model URL to the ONE address it may be connected
	 * to, or null when the URL is refused (SSRF guard).
	 *
	 * "Local" is a promise to the administrator that case identifiers stay on
	 * the instance, so a local model is held to an allow-list: loopback and the
	 * private space a container shares with its Ollama sidecar, over http or
	 * https. Anything else is a cloud model: https only, and never an address
	 * inside the instance's own network. The two are inverses, and neither is a
	 * relaxation of the other.
	 *
	 * The caller pins the answer with `CURLOPT_RESOLVE`, so the connection goes
	 * to the address that was inspected (see the shared guard on DNS rebinding).
	 *
	 * @param string $url The base AI model URL.
	 * @param string $modelType The model type ('local' or 'cloud').
	 *
	 * @return string|null The single IP address to connect to, or null when refused.
	 *
	 * @spec openspec/specs/ai-assistance/spec.md
	 */
	public function resolveSafeAddress(string $url, string $modelType): ?string {
		if ($modelType === 'local') {
			return $this->guard->ownNetworkAddress(url: $url);
		}

		return $this->guard->publicAddress(url: $url);
	}//end resolveSafeAddress()
}//end class
