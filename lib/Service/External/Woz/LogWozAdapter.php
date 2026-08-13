<?php

/**
 * Dormant default Procest WOZ adapter.
 *
 * Records the would-be Kadaster WOZ Bevragen lookup to the structured
 * logger and returns a synthetic LOOKUP_DEFERRED result so the surrounding
 * lifecycle (VTH enforcement, tax case intake) stays observable until a
 * live binding is configured via `integration.woz.mode` (resolved through
 * `Application::register()`). Mirrors the `LogBagAdapter` / `LogBrkAdapter`
 * / `LogBrpHaalCentraalAdapter` / `LogKvkHandelsregisterAdapter`
 * dormant-default pattern used across the Procest external surface.
 *
 * @category Service
 * @package  OCA\Procest\Service\External\Woz
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

namespace OCA\Procest\Service\External\Woz;

use Psr\Log\LoggerInterface;

/**
 * Dormant log-backed Procest WOZ adapter.
 *
 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
 */
class LogWozAdapter implements WozAdapterInterface {
	/**
	 * Construct the log-backed WOZ adapter.
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
	 * Postcode/huisnummer are not personal data, so they are logged as-is,
	 * matching the `LogBagAdapter` precedent.
	 *
	 * @param string $postcode Dutch postcode.
	 * @param string $houseNumber House number.
	 * @param string|null $huisletter Optional house letter.
	 * @param string|null $toevoeging Optional house number
	 *                                addition.
	 * @param array<string,mixed> $context Lookup context.
	 *
	 * @return WozLookupResult The dispatch outcome.
	 *
	 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
	 */
	public function lookupAddress(
		string $postcode,
		string $houseNumber,
		?string $huisletter = null,
		?string $toevoeging = null,
		array $context = [],
	): WozLookupResult {
		$this->logger->info(
			'Procest WOZ lookup deferred (no outbound connector bound)',
			[
				'postcode' => $postcode,
				'huisnummer' => $houseNumber,
				'huisletter' => $huisletter,
				'toevoeging' => $toevoeging,
				'context' => $context,
			]
		);

		return $this->deferred();
	}//end lookupAddress()

	/**
	 * Log the intent + synthesise a LOOKUP_DEFERRED result.
	 *
	 * @param string $addressDesignationId BAG nummeraanduiding identificatie.
	 * @param array<string,mixed> $context Lookup context.
	 *
	 * @return WozLookupResult The dispatch outcome.
	 *
	 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
	 */
	public function lookupByNummeraanduiding(string $addressDesignationId, array $context = []): WozLookupResult {
		$this->logger->info(
			'Procest WOZ lookup deferred (no outbound connector bound)',
			['nummeraanduidingId' => $addressDesignationId, 'context' => $context]
		);

		return $this->deferred();
	}//end lookupByNummeraanduiding()

	/**
	 * Log the intent + synthesise a LOOKUP_DEFERRED result.
	 *
	 * @param string $wozobjectnummer WOZ object number.
	 * @param array<string,mixed> $context Lookup context.
	 *
	 * @return WozLookupResult The dispatch outcome.
	 *
	 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
	 */
	public function lookupByWozObjectNummer(string $wozobjectnummer, array $context = []): WozLookupResult {
		$this->logger->info(
			'Procest WOZ lookup deferred (no outbound connector bound)',
			['wozobjectnummer' => $wozobjectnummer, 'context' => $context]
		);

		return $this->deferred();
	}//end lookupByWozObjectNummer()

	/**
	 * Build the shared LOOKUP_DEFERRED result.
	 *
	 * @return WozLookupResult
	 */
	private function deferred(): WozLookupResult {
		return new WozLookupResult(
			lookupStatus: 'LOOKUP_DEFERRED',
			wozObject: [],
			dormant: true,
			extras: [
				'reason' => 'no-outbound-connector-bound',
				'note' => 'Set `integration.woz.mode` to `test` or `live` (plus `integration.woz.baseUrl` / '
					. '`integration.woz.apiKey` — register as a WOZ data holder via '
					. 'www.kadaster.nl/zakelijk/producten/adressen-en-gebouwen/woz-api-bevragen) to enable real '
					. 'lookups. Application::register() binds WozApiAdapter automatically once the mode resolves '
					. 'to a non-log tier.',
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
