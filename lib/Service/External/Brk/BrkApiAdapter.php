<?php

/**
 * Live Dossiq BRK adapter (Kadaster Haal Centraal BRK Bevragen API v2).
 *
 * Calls the Kadaster Haal Centraal BRK Bevragen API v2 — the authoritative,
 * key-gated cadastral (parcel/ownership-reference) channel Kadaster
 * operates for consumers who need provable per-lookup provenance, mirroring
 * the BRP/KvK/BAG config-tier conventions exactly. Selected by
 * `integration.brk.mode` ∈ {test, live}; base URL and X-Api-Key come from
 * `integration.brk.baseUrl` / `integration.brk.apiKey`.
 *
 * Kadastrale-aanduiding search (`lookupByKadastraleAanduiding`) uses the
 * `/kadastraalonroerendezaken` resource with `kadastraleGemeenteCode` +
 * `sectie` + `perceelnummer` (+ optional `appartementsrechtVolgnummer`)
 * query parameters, which returns 200 with an empty
 * `_embedded.kadastraalOnroerendeZaken` on no match (mirrors the BAG
 * `/adressen` "empty result set" convention). Object lookup
 * (`lookupObject`) uses the singular `/kadastraalonroerendezaken/{id}`
 * resource, which returns a true HTTP 404 on no match.
 *
 * Any transport/HTTP failure other than a mapped 404 degrades to a
 * `LOOKUP_ERROR` result (never throws into the lifecycle), mirroring the
 * BAG/BRP/KvK adapters' fail-soft contract.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\External\Brk
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 * @link https://kadaster.github.io/BRK-bevragen/
 *
 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\External\Brk;

use OCA\Dossiq\Service\External\HalResponseBody;
use OCA\Dossiq\Service\External\IntegrationMode;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Live Kadaster Haal Centraal BRK Bevragen API v2 adapter (test / live tiers).
 *
 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
 */
class BrkApiAdapter implements BrkAdapterInterface {
	/**
	 * Default base URL — Kadaster's `esd-eto-apikey` API-key test
	 * environment for BRK Bevragen v2.
	 */
	public const DEFAULT_BASE_URL = 'https://api.brk.kadaster.nl/esd-eto-apikey/bevragen/v2';

	/**
	 * Any non-empty string.
	 */
	private const NON_EMPTY_PATTERN = '/./s';

	/**
	 * Sectie shape — 1 or 2 uppercase letters.
	 */
	private const SECTIE_PATTERN = '/^[A-Z]{1,2}$/';

	/**
	 * Perceelnummer shape — 1 to 5 digits.
	 */
	private const PERCEELNUMMER_PATTERN = '/^[0-9]{1,5}$/';

	/**
	 * Appartementsrecht volgnummer shape — `A` followed by 1 to 4 digits.
	 */
	private const VOLGNUMMER_PATTERN = '/^A[0-9]{1,4}$/';

	/**
	 * The shape each search query field must match, with its INVALID_INPUT reason, in checking order.
	 * A field absent from the query is not checked.
	 */
	private const QUERY_PATTERNS = [
		'kadastraleGemeenteCode' => [self::NON_EMPTY_PATTERN, 'invalid-gemeentecode'],
		'sectie' => [self::SECTIE_PATTERN, 'invalid-sectie'],
		'perceelnummer' => [self::PERCEELNUMMER_PATTERN, 'invalid-perceelnummer'],
		'appartementsrechtVolgnummer' => [self::VOLGNUMMER_PATTERN, 'invalid-volgnummer'],
	];

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService HTTP client factory.
	 * @param IntegrationMode $mode Config-tier resolver.
	 * @param BrkResponseMapper $mapper Pure response normalizer.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly IClientService $clientService,
		private readonly IntegrationMode $mode,
		private readonly BrkResponseMapper $mapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Look up a parcel by kadastrale aanduiding against the configured
	 * tier.
	 *
	 * @param string $municipalityCode Kadastrale gemeentecode.
	 * @param string $section Sectie (1-2 uppercase letters).
	 * @param string $perceelnummer Perceelnummer (1-5 digits).
	 * @param string|null $sequenceNumber Optional appartementsrecht
	 *                                    volgnummer.
	 * @param array<string,mixed> $context Lookup context.
	 *
	 * @return BrkLookupResult
	 *
	 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
	 */
	public function lookupByKadastraleAanduiding(
		string $municipalityCode,
		string $section,
		string $perceelnummer,
		?string $sequenceNumber = null,
		array $context = [],
	): BrkLookupResult {
		$query = [
			'kadastraleGemeenteCode' => $municipalityCode,
			'sectie' => strtoupper($section),
			'perceelnummer' => $perceelnummer,
		];
		if ($sequenceNumber !== null && $sequenceNumber !== '') {
			$query['appartementsrechtVolgnummer'] = strtoupper($sequenceNumber);
		}

		$invalid = $this->invalidReason(query: $query);
		if ($invalid !== null) {
			return new BrkLookupResult(lookupStatus: 'INVALID_INPUT', parcel: [], dormant: false, extras: ['reason' => $invalid]);
		}

		return $this->search(query: $query, context: $context);
	}//end lookupByKadastraleAanduiding()

	/**
	 * Why a kadastrale-aanduiding search query is malformed, checked in field order.
	 *
	 * @param array<string,string> $query The search query, sectie and volgnummer already uppercased.
	 *
	 * @return string|null The INVALID_INPUT reason, or null when the query is valid.
	 */
	private function invalidReason(array $query): ?string {
		foreach (self::QUERY_PATTERNS as $key => [$pattern, $reason]) {
			if (isset($query[$key]) === true && preg_match($pattern, $query[$key]) !== 1) {
				return $reason;
			}
		}

		return null;
	}//end invalidReason()

	/**
	 * Send a valid kadastrale-aanduiding search, degrading any transport failure to LOOKUP_ERROR.
	 *
	 * @param array<string,string> $query   The search query.
	 * @param array<string,mixed>  $context Lookup context.
	 *
	 * @return BrkLookupResult
	 */
	private function search(array $query, array $context): BrkLookupResult {
		$baseUrl = $this->mode->setting(integration: 'brk', key: 'baseUrl', default: self::DEFAULT_BASE_URL);

		try {
			$response = $this->clientService->newClient()->get(
				rtrim($baseUrl, '/') . '/kadastraalonroerendezaken',
				[
					'timeout' => 10,
					'query' => $query,
					'headers' => $this->headers(),
				]
			);

			return $this->searchResult(response: $response, context: $context);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq BRK kadastrale-aanduiding lookup failed',
				[
					'kadastraleGemeenteCode' => $query['kadastraleGemeenteCode'],
					'sectie' => $query['sectie'],
					'perceelnummer' => $query['perceelnummer'],
					'error' => $e->getMessage(),
					'context' => $context,
				]
			);

			return new BrkLookupResult(lookupStatus: 'LOOKUP_ERROR', parcel: [], dormant: false, extras: ['reason' => 'transport-error']);
		}//end try
	}//end search()

	/**
	 * The result of a search response: an error for a non-2xx status, else NOT_FOUND or FOUND.
	 *
	 * @param IResponse           $response The search response.
	 * @param array<string,mixed> $context  Lookup context.
	 *
	 * @return BrkLookupResult
	 */
	private function searchResult(IResponse $response, array $context): BrkLookupResult {
		$status = (int)$response->getStatusCode();
		if ($status < 200 || $status >= 300) {
			return $this->errorResult(status: $status, context: $context);
		}

		$percelen = $this->extractPercelen(body: (string)$response->getBody());
		if ($percelen === []) {
			return new BrkLookupResult(lookupStatus: 'NOT_FOUND', parcel: [], dormant: false);
		}

		return $this->foundSearchResult(percelen: $percelen);
	}//end searchResult()

	/**
	 * Extract the `_embedded.kadastraalOnroerendeZaken` list from a decoded
	 * response body, defensively defaulting to an empty list on any
	 * unexpected shape.
	 *
	 * @param string $body Raw response body.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function extractPercelen(string $body): array {
		return (new HalResponseBody())->embeddedList(body: $body, key: 'kadastraalOnroerendeZaken');
	}//end extractPercelen()

	/**
	 * Build the FOUND result for a non-empty kadastrale-aanduiding search.
	 *
	 * @param array<int,array<string,mixed>> $percelen Raw Kadaster fragments.
	 *
	 * @return BrkLookupResult
	 */
	private function foundSearchResult(array $percelen): BrkLookupResult {
		$matches = $this->mapper->mapMany(rawList: $percelen);

		return new BrkLookupResult(
			lookupStatus: 'FOUND',
			parcel: $matches[0],
			dormant: false,
			extras: [
				'tier' => $this->mode->resolve(integration: 'brk', allowed: [IntegrationMode::TEST, IntegrationMode::LIVE]),
				'count' => count($matches),
				'matches' => $matches,
			]
		);
	}//end foundSearchResult()

	/**
	 * Look up a kadastraal onroerende zaak by identificatie against the
	 * configured tier.
	 *
	 * @param string $id BRK kadastraalOnroerendeZaak identificatie.
	 * @param array<string,mixed> $context Lookup context.
	 *
	 * @return BrkLookupResult
	 *
	 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
	 */
	public function lookupObject(string $id, array $context = []): BrkLookupResult {
		if ($id === '') {
			return new BrkLookupResult(lookupStatus: 'INVALID_INPUT', parcel: [], dormant: false, extras: ['reason' => 'invalid-id']);
		}

		$baseUrl = $this->mode->setting(integration: 'brk', key: 'baseUrl', default: self::DEFAULT_BASE_URL);

		try {
			$response = $this->fetchObject(url: rtrim($baseUrl, '/') . '/kadastraalonroerendezaken/' . rawurlencode($id));

			return $this->objectResult(response: $response, context: $context);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq BRK object lookup failed',
				['id' => $id, 'error' => $e->getMessage(), 'context' => $context]
			);

			return new BrkLookupResult(lookupStatus: 'LOOKUP_ERROR', parcel: [], dormant: false, extras: ['reason' => 'transport-error']);
		}//end try
	}//end lookupObject()

	/**
	 * GET one object from the registry.
	 *
	 * @param string $url The object's URL.
	 *
	 * @return IResponse The answer.
	 */
	private function fetchObject(string $url): IResponse {
		$client = $this->clientService->newClient();

		return $client->get($url, ['timeout' => 10, 'headers' => $this->headers()]);
	}//end fetchObject()

	/**
	 * The result of an object lookup's answer.
	 *
	 * A 404 and an answer without an object are NOT_FOUND; any other status
	 * outside 2xx is a LOOKUP_ERROR.
	 *
	 * @param IResponse            $response The answer.
	 * @param array<string, mixed> $context  Caller context for the log.
	 *
	 * @return BrkLookupResult
	 */
	private function objectResult(IResponse $response, array $context): BrkLookupResult {
		$object = (new HalResponseBody())->objectOf(response: $response, key: 'kadastraalOnroerendeZaak');
		if (is_int($object) === true) {
			return $this->errorResult(status: $object, context: $context);
		}

		if ($object === []) {
			return new BrkLookupResult(lookupStatus: 'NOT_FOUND', parcel: [], dormant: false);
		}

		return $this->foundObject(object: $object);
	}//end objectResult()

	/**
	 * The FOUND result for one object, with the tier it came from.
	 *
	 * @param array<string, mixed> $object The object as the registry answered it.
	 *
	 * @return BrkLookupResult
	 */
	private function foundObject(array $object): BrkLookupResult {
		return new BrkLookupResult(
			lookupStatus: 'FOUND',
			parcel: $this->mapper->map($object),
			dormant: false,
			extras: ['tier' => $this->mode->resolve(integration: 'brk', allowed: [IntegrationMode::TEST, IntegrationMode::LIVE])]
		);
	}//end foundObject()

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
		$apiKey = $this->mode->setting(integration: 'brk', key: 'apiKey');
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
	 * @return BrkLookupResult
	 */
	private function errorResult(int $status, array $context): BrkLookupResult {
		$this->logger->warning(
			'Dossiq BRK lookup returned a non-success status',
			['status' => $status, 'context' => $context]
		);

		return new BrkLookupResult(
			lookupStatus: 'LOOKUP_ERROR',
			parcel: [],
			dormant: false,
			extras: ['reason' => 'http-' . $status]
		);
	}//end errorResult()
}//end class
