<?php

/**
 * Live Procest BAG adapter (Kadaster BAG API Individuele Bevragingen v2).
 *
 * Calls the Kadaster BAG API Individuele Bevragingen v2 — the
 * authoritative, key-gated, individual-record channel Kadaster operates
 * for consumers who need provable per-lookup provenance (as opposed to
 * `PdokBagService`'s free/open BAG WFS mirror — see
 * `openspec/changes/bag-register-adapter/design.md` for the full
 * PDOK-overlap analysis). Selected by `integration.bag.mode` ∈
 * {test, live}; base URL and X-Api-Key come from `integration.bag.baseUrl`
 * / `integration.bag.apiKey`, mirroring the BRP/KvK Haal Centraal
 * config-tier conventions exactly.
 *
 * Address search (`lookupAddress`) uses the `/adressen` resource, which
 * returns 200 with an empty `_embedded.adressen` on no match (mirrors the
 * BRP/KvK "empty result set" convention). Object lookup
 * (`lookupObject`) uses the singular `/panden/{id}` / `/verblijfsobjecten/{id}`
 * resources, which return a true HTTP 404 on no match — mapped explicitly
 * here, since neither BRP nor KvK's adapters need this distinction (their
 * upstream endpoints are search-shaped, not single-resource-shaped).
 *
 * Any transport/HTTP failure other than a mapped 404 degrades to a
 * `LOOKUP_ERROR` result (never throws into the lifecycle), mirroring the
 * dormant Log adapter's fail-soft contract.
 *
 * @category Service
 * @package  OCA\Procest\Service\External\Bag
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 * @link https://lvbag.github.io/BAG-API/Technische%20specificatie/
 *
 * @spec openspec/changes/bag-register-adapter/proposal.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Service\External\Bag;

use OCA\Procest\Service\External\IntegrationMode;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Live Kadaster BAG API Individuele Bevragingen v2 adapter (test / live tiers).
 *
 * @spec openspec/changes/bag-register-adapter/proposal.md
 */
class BagApiAdapter implements BagAdapterInterface
{
    /**
     * Default base URL — Kadaster's acceptatie (test) environment.
     */
    public const DEFAULT_BASE_URL = 'https://api.bag.acceptatie.kadaster.nl/lvbag/individuelebevragingen/v2';

    /**
     * Dutch postcode shape — 4 digits (first non-zero) + 2 uppercase
     * letters, no space.
     */
    private const POSTCODE_PATTERN = '/^[1-9][0-9]{3}[A-Z]{2}$/';

    /**
     * Allowed BAG object types → their Kadaster resource path segment.
     *
     * @var array<string,string>
     */
    private const OBJECT_PATHS = [
        'pand'             => 'panden',
        'verblijfsobject'  => 'verblijfsobjecten',
        'nummeraanduiding' => 'nummeraanduidingen',
    ];

    /**
     * Constructor.
     *
     * @param IClientService    $clientService HTTP client factory.
     * @param IntegrationMode   $mode          Config-tier resolver.
     * @param BagResponseMapper $mapper        Pure response normalizer.
     * @param LoggerInterface   $logger        Structured logger.
     */
    public function __construct(
        private readonly IClientService $clientService,
        private readonly IntegrationMode $mode,
        private readonly BagResponseMapper $mapper,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Look up address record(s) by postcode + huisnummer against the
     * configured tier.
     *
     * @param string              $postcode   Dutch postcode.
     * @param string              $huisnummer House number.
     * @param string|null         $huisletter Optional house letter.
     * @param string|null         $toevoeging Optional house number
     *                                        addition.
     * @param array<string,mixed> $context    Lookup context.
     *
     * @return BagLookupResult
     *
     * @spec openspec/changes/bag-register-adapter/proposal.md
     */
    public function lookupAddress(
        string $postcode,
        string $huisnummer,
        ?string $huisletter=null,
        ?string $toevoeging=null,
        array $context=[]
    ): BagLookupResult {
        $normalizedPostcode = $this->normalizePostcode(postcode: $postcode);
        $invalidInput       = $this->validateAddressInput(postcode: $normalizedPostcode, huisnummer: $huisnummer);
        if ($invalidInput !== null) {
            return $invalidInput;
        }

        $query   = $this->buildAddressQuery(
            postcode: $normalizedPostcode,
            huisnummer: $huisnummer,
            huisletter: $huisletter,
            toevoeging: $toevoeging
        );
        $baseUrl = $this->mode->setting(integration: 'bag', key: 'baseUrl', default: self::DEFAULT_BASE_URL);

        try {
            $response = $this->clientService->newClient()->get(
                rtrim($baseUrl, '/').'/adressen',
                [
                    'timeout' => 10,
                    'query'   => $query,
                    'headers' => $this->headers(),
                ]
            );

            $status = (int) $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                return $this->errorResult(status: $status, context: $context);
            }

            $adressen = $this->extractAdressen(body: (string) $response->getBody());
            if ($adressen === []) {
                return new BagLookupResult(lookupStatus: 'NOT_FOUND', address: [], dormant: false);
            }

            return $this->foundAddressResult(adressen: $adressen);
        } catch (Throwable $e) {
            $this->logger->warning(
                'Procest BAG address lookup failed',
                ['postcode' => $normalizedPostcode, 'huisnummer' => $huisnummer, 'error' => $e->getMessage(), 'context' => $context]
            );

            return new BagLookupResult(lookupStatus: 'LOOKUP_ERROR', address: [], dormant: false, extras: ['reason' => 'transport-error']);
        }//end try
    }//end lookupAddress()

    /**
     * Normalize a postcode input to uppercase, no spaces.
     *
     * @param string $postcode Raw postcode input.
     *
     * @return string
     */
    private function normalizePostcode(string $postcode): string
    {
        return strtoupper(str_replace(' ', '', $postcode));
    }//end normalizePostcode()

    /**
     * Validate the address-search input, returning an INVALID_INPUT result
     * when malformed, or null when valid.
     *
     * @param string $postcode   Already-normalized postcode.
     * @param string $huisnummer House number.
     *
     * @return BagLookupResult|null
     */
    private function validateAddressInput(string $postcode, string $huisnummer): ?BagLookupResult
    {
        if (preg_match(self::POSTCODE_PATTERN, $postcode) !== 1) {
            return new BagLookupResult(lookupStatus: 'INVALID_INPUT', address: [], dormant: false, extras: ['reason' => 'invalid-postcode']);
        }

        if ($huisnummer === '' || ctype_digit($huisnummer) === false) {
            return new BagLookupResult(lookupStatus: 'INVALID_INPUT', address: [], dormant: false, extras: ['reason' => 'invalid-huisnummer']);
        }

        return null;
    }//end validateAddressInput()

    /**
     * Build the `/adressen` query parameters, including optional
     * huisletter/huisnummertoevoeging when present.
     *
     * @param string      $postcode   Already-normalized postcode.
     * @param string      $huisnummer House number.
     * @param string|null $huisletter Optional house letter.
     * @param string|null $toevoeging Optional house number addition.
     *
     * @return array<string,string>
     */
    private function buildAddressQuery(string $postcode, string $huisnummer, ?string $huisletter, ?string $toevoeging): array
    {
        $query = ['postcode' => $postcode, 'huisnummer' => $huisnummer];
        if ($huisletter !== null && $huisletter !== '') {
            $query['huisletter'] = $huisletter;
        }

        if ($toevoeging !== null && $toevoeging !== '') {
            $query['huisnummertoevoeging'] = $toevoeging;
        }

        return $query;
    }//end buildAddressQuery()

    /**
     * Extract the `_embedded.adressen` list from a decoded response body,
     * defensively defaulting to an empty list on any unexpected shape.
     *
     * @param string $body Raw response body.
     *
     * @return array<int,array<string,mixed>>
     */
    private function extractAdressen(string $body): array
    {
        $data = json_decode($body, true);
        if (is_array($data) === false) {
            return [];
        }

        $embedded = ($data['_embedded'] ?? []);
        if (is_array($embedded) === false) {
            return [];
        }

        return (array) ($embedded['adressen'] ?? []);
    }//end extractAdressen()

    /**
     * Build the FOUND result for a non-empty address search.
     *
     * @param array<int,array<string,mixed>> $adressen Raw Kadaster fragments.
     *
     * @return BagLookupResult
     */
    private function foundAddressResult(array $adressen): BagLookupResult
    {
        $matches = $this->mapper->mapMany(rawList: $adressen);

        return new BagLookupResult(
            lookupStatus: 'FOUND',
            address: $matches[0],
            dormant: false,
            extras: [
                'tier'    => $this->mode->resolve(integration: 'bag', allowed: [IntegrationMode::TEST, IntegrationMode::LIVE]),
                'count'   => count($matches),
                'matches' => $matches,
            ]
        );
    }//end foundAddressResult()

    /**
     * Look up a BAG object (pand, verblijfsobject, or nummeraanduiding) by
     * identificatie against the configured tier.
     *
     * @param string              $objectType `pand`, `verblijfsobject`, or
     *                                        `nummeraanduiding`.
     * @param string              $id         BAG identificatie.
     * @param array<string,mixed> $context    Lookup context.
     *
     * @return BagLookupResult
     *
     * @spec openspec/changes/bag-register-adapter/proposal.md
     */
    public function lookupObject(string $objectType, string $id, array $context=[]): BagLookupResult
    {
        $path = (self::OBJECT_PATHS[$objectType] ?? null);
        if ($path === null || $id === '') {
            return new BagLookupResult(
                lookupStatus: 'INVALID_INPUT',
                address: [],
                dormant: false,
                extras: ['reason' => 'invalid-object-type-or-id']
            );
        }

        $baseUrl = $this->mode->setting(integration: 'bag', key: 'baseUrl', default: self::DEFAULT_BASE_URL);

        try {
            $response = $this->clientService->newClient()->get(
                rtrim($baseUrl, '/').'/'.$path.'/'.rawurlencode($id),
                [
                    'timeout' => 10,
                    'headers' => $this->headers(),
                ]
            );

            $status = (int) $response->getStatusCode();
            if ($status === 404) {
                return new BagLookupResult(lookupStatus: 'NOT_FOUND', address: [], dormant: false);
            }

            if ($status < 200 || $status >= 300) {
                return $this->errorResult(status: $status, context: $context);
            }

            $data = json_decode((string) $response->getBody(), true);
            $body = ($data[$objectType] ?? $data);
            if (is_array($body) === false || $body === []) {
                return new BagLookupResult(lookupStatus: 'NOT_FOUND', address: [], dormant: false);
            }

            return new BagLookupResult(
                lookupStatus: 'FOUND',
                address: $this->mapper->map($body),
                dormant: false,
                extras: ['tier' => $this->mode->resolve(integration: 'bag', allowed: [IntegrationMode::TEST, IntegrationMode::LIVE])]
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'Procest BAG object lookup failed',
                ['objectType' => $objectType, 'id' => $id, 'error' => $e->getMessage(), 'context' => $context]
            );

            return new BagLookupResult(lookupStatus: 'LOOKUP_ERROR', address: [], dormant: false, extras: ['reason' => 'transport-error']);
        }//end try
    }//end lookupObject()

    /**
     * A configured live adapter is not dormant.
     *
     * @return bool
     *
     * @spec openspec/changes/bag-register-adapter/proposal.md
     */
    public function isDormant(): bool
    {
        return false;
    }//end isDormant()

    /**
     * Build the shared request headers.
     *
     * @return array<string,string>
     */
    private function headers(): array
    {
        $apiKey  = $this->mode->setting(integration: 'bag', key: 'apiKey');
        $headers = ['Accept' => 'application/hal+json', 'Accept-Crs' => 'epsg:4326'];
        if ($apiKey !== '') {
            $headers['X-Api-Key'] = $apiKey;
        }

        return $headers;
    }//end headers()

    /**
     * Build a LOOKUP_ERROR result for a non-2xx, non-404 HTTP status.
     *
     * @param int                 $status  HTTP status code.
     * @param array<string,mixed> $context Lookup context.
     *
     * @return BagLookupResult
     */
    private function errorResult(int $status, array $context): BagLookupResult
    {
        $this->logger->warning(
            'Procest BAG lookup returned a non-success status',
            ['status' => $status, 'context' => $context]
        );

        return new BagLookupResult(
            lookupStatus: 'LOOKUP_ERROR',
            address: [],
            dormant: false,
            extras: ['reason' => 'http-'.$status]
        );
    }//end errorResult()
}//end class
