<?php

/**
 * Live Procest WOZ adapter (Kadaster Haal Centraal WOZ Bevragen API).
 *
 * Calls the Kadaster Haal Centraal WOZ Bevragen API (LV-WOZ) — the
 * authoritative, key-gated property-valuation channel restricted to WOZ
 * data holders (municipalities), mirroring the BRP/KvK/BAG/BRK config-tier
 * conventions exactly. Selected by `integration.woz.mode` ∈ {test, live};
 * base URL and X-Api-Key come from `integration.woz.baseUrl` /
 * `integration.woz.apiKey`.
 *
 * Unlike BAG/BRK, Kadaster does not publish a stable public acceptatie
 * hostname for WOZ Bevragen (production access additionally requires an
 * OIN + PKIOverheid certificate issued per registered WOZ data holder — see
 * design.md "Known trade-off"). `DEFAULT_BASE_URL` therefore points at the
 * SwaggerHub-hosted auto-mock server generated from the published OpenAPI
 * spec, intended for `test`-tier smoke-testing only; a real deployment
 * MUST override `integration.woz.baseUrl` once Kadaster issues acceptatie
 * or live credentials.
 *
 * Address/nummeraanduiding lookups (`lookupAddress`,
 * `lookupByNummeraanduiding`) use the `/wozobjecten` resource, which
 * returns 200 with an empty `_embedded.wozObjecten` on no match (mirrors
 * the BAG/BRK "empty result set" convention). Object lookup
 * (`lookupByWozObjectNummer`) uses the singular `/wozobjecten/{id}`
 * resource, which returns a true HTTP 404 on no match.
 *
 * Any transport/HTTP failure other than a mapped 404 degrades to a
 * `LOOKUP_ERROR` result (never throws into the lifecycle), mirroring the
 * BAG/BRK/BRP/KvK adapters' fail-soft contract.
 *
 * @category Service
 * @package  OCA\Procest\Service\External\Woz
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 * @link https://kadaster.github.io/WOZ-bevragen/
 *
 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Service\External\Woz;

use OCA\Procest\Service\External\IntegrationMode;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Live Kadaster Haal Centraal WOZ Bevragen API adapter (test / live tiers).
 *
 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
 */
class WozApiAdapter implements WozAdapterInterface {
	/**
	 * Default base URL — SwaggerHub auto-mock of the published WOZ
	 * Bevragen OpenAPI spec (`test`-tier smoke-testing only; see class
	 * docblock). Override via `integration.woz.baseUrl` for a real
	 * Kadaster environment.
	 */
	public const DEFAULT_BASE_URL = 'https://virtserver.swaggerhub.com/VNG-sandbox/Waardering-onroerende-zaken/1.0.0';

	/**
	 * Dutch postcode shape — 4 digits (first non-zero) + 2 uppercase
	 * letters, no space. Same pattern as `BagApiAdapter` — WOZ objects
	 * share the BAG address taxonomy.
	 */
	private const POSTCODE_PATTERN = '/^[1-9][0-9]{3}[A-Z]{2}$/';

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService HTTP client factory.
	 * @param IntegrationMode $mode Config-tier resolver.
	 * @param WozResponseMapper $mapper Pure response normalizer.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly IClientService $clientService,
		private readonly IntegrationMode $mode,
		private readonly WozResponseMapper $mapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Look up WOZ object(s) by postcode + huisnummer against the
	 * configured tier.
	 *
	 * @param string $postcode Dutch postcode.
	 * @param string $houseNumber House number.
	 * @param string|null $huisletter Optional house letter.
	 * @param string|null $toevoeging Optional house number
	 *                                addition.
	 * @param array<string,mixed> $context Lookup context.
	 *
	 * @return WozLookupResult
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
		$normalizedPostcode = strtoupper(str_replace(' ', '', $postcode));
		if (preg_match(self::POSTCODE_PATTERN, $normalizedPostcode) !== 1) {
			return new WozLookupResult(lookupStatus: 'INVALID_INPUT', wozObject: [], dormant: false, extras: ['reason' => 'invalid-postcode']);
		}

		if ($houseNumber === '' || ctype_digit($houseNumber) === false) {
			return new WozLookupResult(lookupStatus: 'INVALID_INPUT', wozObject: [], dormant: false, extras: ['reason' => 'invalid-huisnummer']);
		}

		$query = ['postcode' => $normalizedPostcode, 'huisnummer' => $houseNumber];
		if ($huisletter !== null && $huisletter !== '') {
			$query['huisletter'] = $huisletter;
		}

		if ($toevoeging !== null && $toevoeging !== '') {
			$query['huisnummertoevoeging'] = $toevoeging;
		}

		return $this->search(query: $query, context: $context);
	}//end lookupAddress()

	/**
	 * Look up WOZ object(s) by BAG nummeraanduiding identificatie.
	 *
	 * @param string $addressDesignationId BAG nummeraanduiding identificatie.
	 * @param array<string,mixed> $context Lookup context.
	 *
	 * @return WozLookupResult
	 *
	 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
	 */
	public function lookupByNummeraanduiding(string $addressDesignationId, array $context = []): WozLookupResult {
		if ($addressDesignationId === '') {
			return new WozLookupResult(
				lookupStatus: 'INVALID_INPUT',
				wozObject: [],
				dormant: false,
				extras: ['reason' => 'invalid-nummeraanduiding-id']
			);
		}

		return $this->search(query: ['nummeraanduidingIdentificatie' => $addressDesignationId], context: $context);
	}//end lookupByNummeraanduiding()

	/**
	 * Shared search-shaped request against `/wozobjecten`.
	 *
	 * @param array<string,string> $query Query parameters.
	 * @param array<string,mixed> $context Lookup context.
	 *
	 * @return WozLookupResult
	 */
	private function search(array $query, array $context): WozLookupResult {
		$baseUrl = $this->mode->setting(integration: 'woz', key: 'baseUrl', default: self::DEFAULT_BASE_URL);

		try {
			$response = $this->clientService->newClient()->get(
				rtrim($baseUrl, '/') . '/wozobjecten',
				[
					'timeout' => 10,
					'query' => $query,
					'headers' => $this->headers(),
				]
			);

			$status = (int)$response->getStatusCode();
			if ($status < 200 || $status >= 300) {
				return $this->errorResult(status: $status, context: $context);
			}

			$wozObjecten = $this->extractWozObjecten(body: (string)$response->getBody());
			if ($wozObjecten === []) {
				return new WozLookupResult(lookupStatus: 'NOT_FOUND', wozObject: [], dormant: false);
			}

			return $this->foundSearchResult(wozObjecten: $wozObjecten);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Procest WOZ search lookup failed',
				['query' => $query, 'error' => $e->getMessage(), 'context' => $context]
			);

			return new WozLookupResult(lookupStatus: 'LOOKUP_ERROR', wozObject: [], dormant: false, extras: ['reason' => 'transport-error']);
		}//end try
	}//end search()

	/**
	 * Extract the `_embedded.wozObjecten` list from a decoded response
	 * body, defensively defaulting to an empty list on any unexpected
	 * shape.
	 *
	 * @param string $body Raw response body.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function extractWozObjecten(string $body): array {
		$data = json_decode($body, true);
		if (is_array($data) === false) {
			return [];
		}

		$embedded = ($data['_embedded'] ?? []);
		if (is_array($embedded) === false) {
			return [];
		}

		return (array)($embedded['wozObjecten'] ?? []);
	}//end extractWozObjecten()

	/**
	 * Build the FOUND result for a non-empty search.
	 *
	 * @param array<int,array<string,mixed>> $wozObjecten Raw Kadaster fragments.
	 *
	 * @return WozLookupResult
	 */
	private function foundSearchResult(array $wozObjecten): WozLookupResult {
		$matches = $this->mapper->mapMany(rawList: $wozObjecten);

		return new WozLookupResult(
			lookupStatus: 'FOUND',
			wozObject: $matches[0],
			dormant: false,
			extras: [
				'tier' => $this->mode->resolve(integration: 'woz', allowed: [IntegrationMode::TEST, IntegrationMode::LIVE]),
				'count' => count($matches),
				'matches' => $matches,
			]
		);
	}//end foundSearchResult()

	/**
	 * Look up a single WOZ object by its wozobjectnummer against the
	 * configured tier.
	 *
	 * @param string $wozobjectnummer WOZ object number.
	 * @param array<string,mixed> $context Lookup context.
	 *
	 * @return WozLookupResult
	 *
	 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
	 */
	public function lookupByWozObjectNummer(string $wozobjectnummer, array $context = []): WozLookupResult {
		if ($wozobjectnummer === '') {
			return new WozLookupResult(lookupStatus: 'INVALID_INPUT', wozObject: [], dormant: false, extras: ['reason' => 'invalid-wozobjectnummer']);
		}

		$baseUrl = $this->mode->setting(integration: 'woz', key: 'baseUrl', default: self::DEFAULT_BASE_URL);

		try {
			$response = $this->clientService->newClient()->get(
				rtrim($baseUrl, '/') . '/wozobjecten/' . rawurlencode($wozobjectnummer),
				[
					'timeout' => 10,
					'headers' => $this->headers(),
				]
			);

			$status = (int)$response->getStatusCode();
			if ($status === 404) {
				return new WozLookupResult(lookupStatus: 'NOT_FOUND', wozObject: [], dormant: false);
			}

			if ($status < 200 || $status >= 300) {
				return $this->errorResult(status: $status, context: $context);
			}

			$data = json_decode((string)$response->getBody(), true);
			$body = ($data['wozObject'] ?? $data);
			if (is_array($body) === false || $body === []) {
				return new WozLookupResult(lookupStatus: 'NOT_FOUND', wozObject: [], dormant: false);
			}

			return new WozLookupResult(
				lookupStatus: 'FOUND',
				wozObject: $this->mapper->map($body),
				dormant: false,
				extras: ['tier' => $this->mode->resolve(integration: 'woz', allowed: [IntegrationMode::TEST, IntegrationMode::LIVE])]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Procest WOZ object lookup failed',
				['wozobjectnummer' => $wozobjectnummer, 'error' => $e->getMessage(), 'context' => $context]
			);

			return new WozLookupResult(lookupStatus: 'LOOKUP_ERROR', wozObject: [], dormant: false, extras: ['reason' => 'transport-error']);
		}//end try
	}//end lookupByWozObjectNummer()

	/**
	 * A configured live adapter is not dormant.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
	 */
	public function isDormant(): bool {
		return false;
	}//end isDormant()

	/**
	 * Build the shared request headers.
	 *
	 * @return array<string,string>
	 */
	private function headers(): array {
		$apiKey = $this->mode->setting(integration: 'woz', key: 'apiKey');
		$headers = ['Accept' => 'application/hal+json'];
		if ($apiKey !== '') {
			$headers['X-Api-Key'] = $apiKey;
		}

		return $headers;
	}//end headers()

	/**
	 * Build a LOOKUP_ERROR result for a non-2xx, non-404 HTTP status.
	 *
	 * @param int $status HTTP status code.
	 * @param array<string,mixed> $context Lookup context.
	 *
	 * @return WozLookupResult
	 */
	private function errorResult(int $status, array $context): WozLookupResult {
		$this->logger->warning(
			'Procest WOZ lookup returned a non-success status',
			['status' => $status, 'context' => $context]
		);

		return new WozLookupResult(
			lookupStatus: 'LOOKUP_ERROR',
			wozObject: [],
			dormant: false,
			extras: ['reason' => 'http-' . $status]
		);
	}//end errorResult()
}//end class
