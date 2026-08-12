<?php

/**
 * Dormant default Procest BRK adapter.
 *
 * Records the would-be Kadaster BRK Bevragen v2 lookup to the structured
 * logger and returns a synthetic LOOKUP_DEFERRED result so the surrounding
 * lifecycle (VTH enforcement, spatial/tax case intake) stays observable
 * until a live binding is configured via `integration.brk.mode` (resolved
 * through `Application::register()`). Mirrors the `LogBagAdapter` /
 * `LogBrpHaalCentraalAdapter` / `LogKvkHandelsregisterAdapter`
 * dormant-default pattern used across the Procest external surface.
 *
 * @category Service
 * @package  OCA\Procest\Service\External\Brk
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Service\External\Brk;

use Psr\Log\LoggerInterface;

/**
 * Dormant log-backed Procest BRK adapter.
 *
 * @SuppressWarnings(PHPMD.LongVariable) — kadastrale-aanduiding parameter
 * names are the canonical BRK domain terms (see interface).
 *
 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
 */
class LogBrkAdapter implements BrkAdapterInterface {
	/**
	 * Construct the log-backed BRK adapter.
	 *
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Log the intent + synthesise a LOOKUP_DEFERRED result.
	 *
	 * Kadastrale aanduiding is not personal data (it identifies a parcel,
	 * not a person), so it is logged as-is, matching the
	 * `LogBagAdapter` precedent (postcode/huisnummer logged verbatim).
	 *
	 * @param string $kadastraleGemeenteCode Kadastrale gemeentecode.
	 * @param string $sectie Sectie.
	 * @param string $perceelnummer Perceelnummer.
	 * @param string|null $appartementsrechtVolgnummer Optional appartementsrecht
	 *                                                 volgnummer.
	 * @param array<string,mixed> $context Lookup context.
	 *
	 * @return BrkLookupResult The dispatch outcome.
	 *
	 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
	 */
	public function lookupByKadastraleAanduiding(
		string $kadastraleGemeenteCode,
		string $sectie,
		string $perceelnummer,
		?string $appartementsrechtVolgnummer = null,
		array $context = [],
	): BrkLookupResult {
		$this->logger->info(
			'Procest BRK lookup deferred (no outbound connector bound)',
			[
				'kadastraleGemeenteCode' => $kadastraleGemeenteCode,
				'sectie' => $sectie,
				'perceelnummer' => $perceelnummer,
				'appartementsrechtVolgnummer' => $appartementsrechtVolgnummer,
				'context' => $context,
			]
		);

		return $this->deferred();
	}//end lookupByKadastraleAanduiding()

	/**
	 * Log the intent + synthesise a LOOKUP_DEFERRED result.
	 *
	 * @param string $id BRK kadastraalOnroerendeZaak identificatie.
	 * @param array<string,mixed> $context Lookup context.
	 *
	 * @return BrkLookupResult The dispatch outcome.
	 *
	 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
	 */
	public function lookupObject(string $id, array $context = []): BrkLookupResult {
		$this->logger->info(
			'Procest BRK lookup deferred (no outbound connector bound)',
			['id' => $id, 'context' => $context]
		);

		return $this->deferred();
	}//end lookupObject()

	/**
	 * Build the shared LOOKUP_DEFERRED result.
	 *
	 * @return BrkLookupResult
	 */
	private function deferred(): BrkLookupResult {
		return new BrkLookupResult(
			lookupStatus: 'LOOKUP_DEFERRED',
			parcel: [],
			dormant: true,
			extras: [
				'reason' => 'no-outbound-connector-bound',
				'note' => 'Set `integration.brk.mode` to `test` or `live` (plus `integration.brk.baseUrl` / '
					. '`integration.brk.apiKey` — request a key via the BRK Bevragen registration flow at '
					. 'www.kadaster.nl/zakelijk/producten/eigendom/brk-bevragen) to enable real lookups. '
					. 'Application::register() binds BrkApiAdapter automatically once the mode resolves to a '
					. 'non-log tier.',
			],
		);
	}//end deferred()

	/**
	 * Report whether this adapter is dormant.
	 *
	 * @inheritDoc
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
	 */
	public function isDormant(): bool {
		return true;
	}//end isDormant()
}//end class
