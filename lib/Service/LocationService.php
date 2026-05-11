<?php

/**
 * Procest Location Service.
 *
 * Domain service for the `location` schema (case-location spec). CRUD is
 * delegated to the manifest renderer (OpenRegister auto-form on the case
 * detail "Locaties" tab and the admin index/detail pages); this service owns
 * the server-side helpers that the manifest cannot express:
 *   - validate()        — cross-field validation per design.md §Validation Rules
 *   - reverseGeocode()  — coordinate → BAG nummeraanduiding + formattedAddress
 *   - attachToCase()    — persist a location row scoped to a case
 *
 * reverseGeocode() delegates to {@see Pdok\PdokLocatieserverService} (from
 * the pdok-integration spec) when available; otherwise it returns null so
 * callers can fall back to `source = free`. PDOK is resolved lazily via the
 * DI container so this service stays usable on deployments that have not
 * enabled pdok-integration yet.
 *
 * Persistence MUST go through the OpenRegister object store via the
 * `location_schema` config key — no direct DB writes.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for case-location domain operations.
 */
class LocationService
{

    /**
     * Valid `source` enum values mirrored from procest_register.json.
     */
    private const VALID_SOURCES = [
        'bag',
        'pdok-reverse',
        'gps',
        'free',
        'geocoded',
        'import',
    ];

    /**
     * Maximum BAG-match distance for reverseGeocode (metres).
     */
    private const REVERSE_MAX_DISTANCE_M = 25;

    /**
     * Constructor.
     *
     * @param SettingsService    $settingsService The settings service
     * @param ContainerInterface $container       The DI container (used to
     *                                            lazily resolve the optional
     *                                            PdokLocatieserverService from
     *                                            the pdok-integration spec)
     * @param LoggerInterface    $logger          The logger
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Validate a location payload against the cross-field rules from design.md.
     *
     * Rules:
     *   - `source` MUST be one of VALID_SOURCES.
     *   - `case` UUID MUST be present.
     *   - `source=bag`            → `nummeraanduidingId` MUST be present.
     *   - `source=pdok-reverse`   → `latitude` + `longitude` MUST be present.
     *   - `source=gps`            → `latitude`, `longitude`, `accuracyRadius` MUST be present.
     *   - `source=free`           → at least one of `formattedAddress` OR (`latitude`+`longitude`).
     *   - A location MUST carry either `nummeraanduidingId` OR (`latitude`+`longitude`).
     *
     * Returns an array of error codes; an empty array means the payload is valid.
     *
     * @param array<string, mixed> $payload The location payload
     *
     * @return array<int, string> Error codes (empty = valid)
     */
    public function validate(array $payload): array
    {
        $errors = [];

        $source = isset($payload['source']) === true ? (string) $payload['source'] : '';
        if ($source === '') {
            $errors[] = 'source.required';
        } else if (in_array($source, self::VALID_SOURCES, true) === false) {
            $errors[] = 'source.invalid';
        }

        $caseId = isset($payload['case']) === true ? (string) $payload['case'] : '';
        if ($caseId === '') {
            $errors[] = 'case.required';
        }

        $hasLatLng = (
            isset($payload['latitude']) === true
            && isset($payload['longitude']) === true
            && is_numeric($payload['latitude']) === true
            && is_numeric($payload['longitude']) === true
        );
        $hasBag = (
            isset($payload['nummeraanduidingId']) === true
            && (string) $payload['nummeraanduidingId'] !== ''
        );
        $hasFormatted = (
            isset($payload['formattedAddress']) === true
            && (string) $payload['formattedAddress'] !== ''
        );

        switch ($source) {
        case 'bag':
            if ($hasBag === false) {
                $errors[] = 'nummeraanduidingId.required';
            }
            break;

        case 'pdok-reverse':
            if ($hasLatLng === false) {
                $errors[] = 'latitude-longitude.required';
            }
            break;

        case 'gps':
            if ($hasLatLng === false) {
                $errors[] = 'latitude-longitude.required';
            }

            if (isset($payload['accuracyRadius']) === false
                || is_numeric($payload['accuracyRadius']) === false
            ) {
                $errors[] = 'accuracyRadius.required';
            }
            break;

        case 'free':
            if ($hasFormatted === false && $hasLatLng === false) {
                $errors[] = 'formattedAddress-or-coordinates.required';
            }
            break;
        }//end switch

        // Universal anchor rule: every location MUST have either a BAG
        // reference or valid coordinates so it can be placed on a map.
        if ($hasBag === false && $hasLatLng === false) {
            $errors[] = 'bag-or-coordinates.required';
        }

        return $errors;
    }//end validate()

    /**
     * Reverse-geocode a coordinate pair to a BAG nummeraanduiding + formatted address.
     *
     * Delegates to {@see Pdok\PdokLocatieserverService::reverse()} from the
     * pdok-integration spec. Returns:
     *   - ['nummeraanduidingId' => string, 'formattedAddress' => string]
     *     when a BAG match is found within {@see self::REVERSE_MAX_DISTANCE_M}.
     *   - null when no match is found, the service is degraded/unavailable,
     *     or the input coordinates are outside the WGS84 envelope.
     *
     * Callers MUST handle null and either reject the save (when
     * source = pdok-reverse) or persist with source = free.
     *
     * @param float $latitude  WGS84 latitude
     * @param float $longitude WGS84 longitude
     *
     * @return array<string, string>|null Match or null when unavailable
     *
     * @psalm-suppress MixedAssignment
     * @psalm-suppress MixedArrayAccess
     */
    public function reverseGeocode(float $latitude, float $longitude): ?array
    {
        // Sanity check on the coordinate envelope before we burn an HTTP call.
        if ($latitude < -90.0 || $latitude > 90.0) {
            return null;
        }

        if ($longitude < -180.0 || $longitude > 180.0) {
            return null;
        }

        $pdok = $this->resolvePdokService();
        if ($pdok === null) {
            $this->logger->debug(
                'Procest: reverseGeocode requested but PdokLocatieserverService is unavailable',
                [
                    'app'       => Application::APP_ID,
                    'latitude'  => $latitude,
                    'longitude' => $longitude,
                ]
            );
            return null;
        }

        try {
            $response = $pdok->reverse($latitude, $longitude);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Procest: reverseGeocode call failed: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            return null;
        }

        // The PDOK Locatieserver `/reverse` payload follows the Solr response
        // envelope: response.docs[]. Each doc may carry `nummeraanduiding_id`
        // (or `id` when type = 'adres'), `weergavenaam` (the formatted
        // address), and `afstand` (distance from the query point in metres).
        $docs = $this->extractDocs($response);
        if ($docs === []) {
            return null;
        }

        $best = $docs[0];
        $distance = isset($best['afstand']) === true && is_numeric($best['afstand']) === true
            ? (float) $best['afstand']
            : null;

        if ($distance !== null && $distance > (float) self::REVERSE_MAX_DISTANCE_M) {
            return null;
        }

        $nummeraanduidingId = '';
        if (isset($best['nummeraanduiding_id']) === true) {
            $nummeraanduidingId = (string) $best['nummeraanduiding_id'];
        } else if (isset($best['type']) === true
            && (string) $best['type'] === 'adres'
            && isset($best['id']) === true
        ) {
            $nummeraanduidingId = (string) $best['id'];
        }

        $formattedAddress = isset($best['weergavenaam']) === true
            ? (string) $best['weergavenaam']
            : '';

        if ($nummeraanduidingId === '' && $formattedAddress === '') {
            return null;
        }

        return [
            'nummeraanduidingId' => $nummeraanduidingId,
            'formattedAddress'   => $formattedAddress,
        ];
    }//end reverseGeocode()

    /**
     * Resolve PdokLocatieserverService from the DI container.
     *
     * Returns null when pdok-integration is not enabled / the service is not
     * registered. Mirrors the lazy-resolve pattern used by SettingsService for
     * the OpenRegister ObjectService.
     *
     * @return object|null PdokLocatieserverService instance, or null
     *
     * @psalm-suppress MixedReturnStatement
     * @psalm-suppress MixedInferredReturnType
     */
    private function resolvePdokService(): ?object
    {
        try {
            return $this->container->get(
                'OCA\Procest\Service\Pdok\PdokLocatieserverService'
            );
        } catch (\Throwable $e) {
            $this->logger->debug(
                'Procest: PdokLocatieserverService is not available: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            return null;
        }
    }//end resolvePdokService()

    /**
     * Extract the `response.docs` array from a PDOK Solr-style payload.
     *
     * @param array<string, mixed> $response The decoded PDOK response.
     *
     * @return array<int, array<string, mixed>> The docs array, possibly empty.
     */
    private function extractDocs(array $response): array
    {
        if (isset($response['response']) === false
            || is_array($response['response']) === false
        ) {
            return [];
        }

        $envelope = $response['response'];
        if (isset($envelope['docs']) === false || is_array($envelope['docs']) === false) {
            return [];
        }

        $docs = [];
        foreach ($envelope['docs'] as $doc) {
            if (is_array($doc) === true) {
                $docs[] = $doc;
            }
        }

        return $docs;
    }//end extractDocs()

    /**
     * Attach a validated location to a case and persist it via OpenRegister.
     *
     * The caller is responsible for running `validate()` first; this method
     * does a defensive re-check and refuses to write when validation fails
     * or when the OpenRegister object store is unavailable.
     *
     * @param string               $caseId   The case UUID
     * @param array<string, mixed> $location The location payload (without `case` set)
     *
     * @return array<string, mixed>|null The persisted object, or null on failure
     *
     * @throws \RuntimeException When validation fails or OpenRegister is missing
     */
    public function attachToCase(string $caseId, array $location): ?array
    {
        if ($caseId === '') {
            throw new \RuntimeException('caseId is required');
        }

        $payload         = $location;
        $payload['case'] = $caseId;

        $errors = $this->validate($payload);
        if (count($errors) > 0) {
            throw new \RuntimeException(
                'Location payload failed validation: '.implode(', ', $errors)
            );
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('location_schema');

        if ($register === '' || $schema === '') {
            throw new \RuntimeException('Location schema is not configured');
        }

        try {
            $saved = $objectService->saveObject($register, $schema, $payload);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: failed to attach location to case: '.$e->getMessage(),
                ['app' => Application::APP_ID, 'caseId' => $caseId]
            );
            return null;
        }

        if (is_array($saved) === true) {
            return $saved;
        }

        if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
            $serialised = $saved->jsonSerialize();
            if (is_array($serialised) === true) {
                return $serialised;
            }
        }

        return null;
    }//end attachToCase()

    /**
     * List all locations for a given case.
     *
     * Used by workflow guards, the case-map clustering helper, and any
     * future LocationController. The manifest-driven case detail tab
     * fetches via the generic OpenRegister object endpoint directly, so
     * this method is reserved for server-side consumers.
     *
     * @param string $caseId The case UUID
     *
     * @return array<int, array<string, mixed>> Location records (possibly empty)
     */
    public function listForCase(string $caseId): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('location_schema');

        if ($register === '' || $schema === '') {
            return [];
        }

        try {
            $results = $objectService->findObjects(
                $register,
                $schema,
                ['case' => $caseId],
                [],
                500,
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: failed to list locations for case: '.$e->getMessage(),
                ['app' => Application::APP_ID, 'caseId' => $caseId]
            );
            return [];
        }

        if (is_array($results) === true) {
            return $results;
        }

        return [];
    }//end listForCase()
}//end class
