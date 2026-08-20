<?php

/**
 * Dormant default Procest BAG adapter.
 *
 * Records the would-be Kadaster BAG API Individuele Bevragingen v2 lookup
 * to the structured logger and returns a synthetic LOOKUP_DEFERRED result
 * so the surrounding lifecycle (VTH enforcement, spatial case intake)
 * stays observable until a live binding is configured via
 * `integration.bag.mode` (resolved through `Application::register()`).
 * Mirrors the `LogBrpHaalCentraalAdapter` / `LogKvkHandelsregisterAdapter`
 * dormant-default pattern used across the Procest external surface.
 *
 * @category Service
 * @package  OCA\Procest\Service\External\Bag
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/bag-register-adapter/proposal.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Service\External\Bag;

use Psr\Log\LoggerInterface;

/**
 * Dormant log-backed Procest BAG adapter.
 *
 * @spec openspec/changes/bag-register-adapter/proposal.md
 */
class LogBagAdapter implements BagAdapterInterface {
	/**
	 * Construct the log-backed BAG adapter.
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
	 * Postcode/huisnummer are not personal data (they identify a building,
	 * not a person), so they are logged as-is, matching the
	 * `LogKvkHandelsregisterAdapter` precedent (KvK number logged
	 * verbatim).
	 *
	 * @param string $postcode Dutch postcode.
	 * @param string $houseNumber House number.
	 * @param string|null $huisletter Optional house letter.
	 * @param string|null $toevoeging Optional house number
	 *                                addition.
	 * @param array<string,mixed> $context Lookup context.
	 *
	 * @return BagLookupResult The dispatch outcome.
	 *
	 * @spec openspec/changes/bag-register-adapter/proposal.md
	 */
	public function lookupAddress(
		string $postcode,
		string $houseNumber,
		?string $huisletter = null,
		?string $toevoeging = null,
		array $context = [],
	): BagLookupResult {
		$this->logger->info(
			'Procest BAG lookup deferred (no outbound connector bound)',
			[
				'postcode' => $postcode,
				'houseNumber' => $houseNumber,
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
	 * @param string $objectType `pand`, `verblijfsobject`, or
	 *                           `nummeraanduiding`.
	 * @param string $id BAG identificatie.
	 * @param array<string,mixed> $context Lookup context.
	 *
	 * @return BagLookupResult The dispatch outcome.
	 *
	 * @spec openspec/changes/bag-register-adapter/proposal.md
	 */
	public function lookupObject(string $objectType, string $id, array $context = []): BagLookupResult {
		$this->logger->info(
			'Procest BAG lookup deferred (no outbound connector bound)',
			[
				'objectType' => $objectType,
				'id' => $id,
				'context' => $context,
			]
		);

		return $this->deferred();
	}//end lookupObject()

	/**
	 * Build the shared LOOKUP_DEFERRED result.
	 *
	 * @return BagLookupResult
	 */
	private function deferred(): BagLookupResult {
		return new BagLookupResult(
			lookupStatus: 'LOOKUP_DEFERRED',
			address: [],
			dormant: true,
			extras: [
				'reason' => 'no-outbound-connector-bound',
				'note' => 'Set `integration.bag.mode` to `test` or `live` (plus `integration.bag.baseUrl` / '
					. '`integration.bag.apiKey` — request a free acceptatie key via '
					. 'formulieren.kadaster.nl/aanvraag_bag_api_individuele_bevragingen_test_api_key) to enable '
					. 'real lookups. Application::register() binds BagApiAdapter automatically once the mode '
					. 'resolves to a non-log tier.',
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
	 * @spec openspec/changes/bag-register-adapter/proposal.md
	 */
	public function isDormant(): bool {
		return true;
	}//end isDormant()
}//end class
