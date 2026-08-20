<?php

/**
 * Live Procest BRK adapter (Kadaster Haal Centraal BRK Bevragen API v2).
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
 * @package  OCA\Procest\Service\External\Brk
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 * @link https://kadaster.github.io/BRK-bevragen/
 *
 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Service\External\Brk;

use OCA\Procest\Service\External\IntegrationMode;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Live Kadaster Haal Centraal BRK Bevragen API v2 adapter (test / live tiers).
 *
 * @SuppressWarnings(PHPMD.LongVariable) — kadastrale-aanduiding parameter
 * names are the canonical BRK domain terms (see interface).
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
	 * @param string $kadastraleMunicipalityCode Kadastrale gemeentecode.
	 * @param string $section Sectie (1-2 uppercase letters).
	 * @param string $perceelnummer Perceelnummer (1-5 digits).
	 * @param string|null $appartementsrechtSequenceNumber Optional appartementsrecht
	 *                                                 volgnummer.
	 * @param array<string,mixed> $context Lookup context.
	 *
	 * @return BrkLookupResult
	 *
	 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
	 */
	public function lookupByKadastraleAanduiding(
		string $kadastraleMunicipalityCode,
		string $section,
		string $perceelnummer,
		?string $appartementsrechtSequenceNumber = null,
		array $context = [],
	): BrkLookupResult {
		$normalizedSection = strtoupper($section);
		$invalidInput = $this->validateKadastraleAanduidingInput(
			municipalityCode: $kadastraleMunicipalityCode,
			section: $normalizedSection,
			perceelnummer: $perceelnummer,
			sequenceNumber: $appartementsrechtSequenceNumber
		);
		if ($invalidInput !== null) {
			return $invalidInput;
		}

		$query = [
			'kadastraleGemeenteCode' => $kadastraleMunicipalityCode,
			'sectie' => $normalizedSection,
			'perceelnummer' => $perceelnummer,
		];
		if ($appartementsrechtSequenceNumber !== null && $appartementsrechtSequenceNumber !== '') {
			$query['appartementsrechtVolgnummer'] = strtoupper($appartementsrechtSequenceNumber);
		}

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

			$status = (int)$response->getStatusCode();
			if ($status < 200 || $status >= 300) {
				return $this->errorResult(status: $status, context: $context);
			}

			$percelen = $this->extractPercelen(body: (string)$response->getBody());
			if ($percelen === []) {
				return new BrkLookupResult(lookupStatus: 'NOT_FOUND', parcel: [], dormant: false);
			}

			return $this->foundSearchResult(percelen: $percelen);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Procest BRK kadastrale-aanduiding lookup failed',
				[
					'kadastraleGemeenteCode' => $kadastraleMunicipalityCode,
					'sectie' => $normalizedSection,
					'perceelnummer' => $perceelnummer,
					'error' => $e->getMessage(),
					'context' => $context,
				]
			);

			return new BrkLookupResult(lookupStatus: 'LOOKUP_ERROR', parcel: [], dormant: false, extras: ['reason' => 'transport-error']);
		}//end try
	}//end lookupByKadastraleAanduiding()

	/**
	 * Validate the kadastrale-aanduiding search input, returning an
	 * INVALID_INPUT result when malformed, or null when valid.
	 *
	 * @param string $municipalityCode Kadastrale gemeentecode.
	 * @param string $section Already-normalized sectie.
	 * @param string $perceelnummer Perceelnummer.
	 * @param string|null $sequenceNumber Optional appartementsrecht volgnummer.
	 *
	 * @return BrkLookupResult|null
	 */
	private function validateKadastraleAanduidingInput(
		string $municipalityCode,
		string $section,
		string $perceelnummer,
		?string $sequenceNumber,
	): ?BrkLookupResult {
		if ($municipalityCode === '') {
			return new BrkLookupResult(lookupStatus: 'INVALID_INPUT', parcel: [], dormant: false, extras: ['reason' => 'invalid-gemeentecode']);
		}

		if (preg_match(self::SECTIE_PATTERN, $section) !== 1) {
			return new BrkLookupResult(lookupStatus: 'INVALID_INPUT', parcel: [], dormant: false, extras: ['reason' => 'invalid-sectie']);
		}

		if (preg_match(self::PERCEELNUMMER_PATTERN, $perceelnummer) !== 1) {
			return new BrkLookupResult(lookupStatus: 'INVALID_INPUT', parcel: [], dormant: false, extras: ['reason' => 'invalid-perceelnummer']);
		}

		if ($sequenceNumber !== null && $sequenceNumber !== '' && preg_match(self::VOLGNUMMER_PATTERN, strtoupper($sequenceNumber)) !== 1) {
			return new BrkLookupResult(lookupStatus: 'INVALID_INPUT', parcel: [], dormant: false, extras: ['reason' => 'invalid-volgnummer']);
		}

		return null;
	}//end validateKadastraleAanduidingInput()

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
		$data = json_decode($body, true);
		if (is_array($data) === false) {
			return [];
		}

		$embedded = ($data['_embedded'] ?? []);
		if (is_array($embedded) === false) {
			return [];
		}

		return (array)($embedded['kadastraalOnroerendeZaken'] ?? []);
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
			$response = $this->clientService->newClient()->get(
				rtrim($baseUrl, '/') . '/kadastraalonroerendezaken/' . rawurlencode($id),
				[
					'timeout' => 10,
					'headers' => $this->headers(),
				]
			);

			$status = (int)$response->getStatusCode();
			if ($status === 404) {
				return new BrkLookupResult(lookupStatus: 'NOT_FOUND', parcel: [], dormant: false);
			}

			if ($status < 200 || $status >= 300) {
				return $this->errorResult(status: $status, context: $context);
			}

			$data = json_decode((string)$response->getBody(), true);
			$body = ($data['kadastraalOnroerendeZaak'] ?? $data);
			if (is_array($body) === false || $body === []) {
				return new BrkLookupResult(lookupStatus: 'NOT_FOUND', parcel: [], dormant: false);
			}

			return new BrkLookupResult(
				lookupStatus: 'FOUND',
				parcel: $this->mapper->map($body),
				dormant: false,
				extras: ['tier' => $this->mode->resolve(integration: 'brk', allowed: [IntegrationMode::TEST, IntegrationMode::LIVE])]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Procest BRK object lookup failed',
				['id' => $id, 'error' => $e->getMessage(), 'context' => $context]
			);

			return new BrkLookupResult(lookupStatus: 'LOOKUP_ERROR', parcel: [], dormant: false, extras: ['reason' => 'transport-error']);
		}//end try
	}//end lookupObject()

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
			'Procest BRK lookup returned a non-success status',
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
