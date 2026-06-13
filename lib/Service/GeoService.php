<?php

/**
 * Procest Geo Service
 *
 * Pure-logic geo helper for the gis-integration spec. Owns the server-side
 * geometry concerns the OpenRegister manifest renderer cannot express:
 *
 *   - validateGeometry()  — RFC 7946 GeoJSON structural validation for the
 *                           geometry shapes procest stores on a case/location
 *                           (Point, Polygon, MultiPolygon, Feature,
 *                           FeatureCollection), including coordinate-envelope
 *                           (WGS84) checks.
 *   - normaliseGeometry() — coerce a stored JSON-encoded string OR an already
 *                           decoded array into a canonical Feature, so callers
 *                           do not have to care how the value was persisted
 *                           (procest stores geometry JSON-encoded).
 *   - encodeGeometry()    — canonical write encoder (stringify on write).
 *   - buildCaseGeoCollection() — assemble a clustered GeoJSON FeatureCollection
 *                           of case locations for the /api/cases/geo map view,
 *                           filtered by zaaktype/status/bbox and grid-clustered
 *                           at low zoom levels.
 *
 * All persistence is read through {@see WfsExportService}-style location
 * fetching via the OpenRegister object store; this service performs only data
 * transformation and degrades to empty results when OpenRegister is absent
 * (it never hard-fails the calling map view).
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/gis-integration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;

/**
 * Geometry validation, (de)serialisation and clustered case-geo assembly.
 *
 * @spec openspec/specs/gis-integration/spec.md
 */
class GeoService
{

    use SearchesObjects;

    /**
     * GeoJSON geometry types procest accepts on a case/location.
     */
    public const SUPPORTED_GEOMETRY_TYPES = [
        'Point',
        'Polygon',
        'MultiPolygon',
    ];

    /**
     * Default maximum number of case locations to fetch for a map view.
     */
    public const DEFAULT_MAX_FEATURES = 1000;

    /**
     * Hard cap on locations fetched for a single map view.
     */
    public const MAX_FEATURES_HARD_CAP = 5000;

    /**
     * Zoom level at/above which markers are returned individually (no clustering).
     */
    public const CLUSTER_DISABLE_ZOOM = 14;

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Resolves register/schema ids and ObjectService.
     * @param LoggerInterface $logger          Structured logger.
     *
     * @return void
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Validate a GeoJSON geometry/Feature/FeatureCollection per RFC 7946.
     *
     * Accepts a Point, Polygon or MultiPolygon geometry, or a Feature /
     * FeatureCollection wrapping one of those. Returns an array of error codes;
     * an empty array means the value is valid. The codes are stable so the UI
     * can map them to i18n strings.
     *
     * @param mixed $geometry Decoded GeoJSON (array) — bare geometry, Feature
     *                        or FeatureCollection.
     *
     * @return array<int, string> Error codes (empty = valid).
     *
     * @spec openspec/specs/gis-integration/spec.md
     */
    public function validateGeometry(mixed $geometry): array
    {
        if (is_array($geometry) === false) {
            return ['geometry.not_object'];
        }

        $type = '';
        if (isset($geometry['type']) === true && is_string($geometry['type']) === true) {
            $type = $geometry['type'];
        }

        if ($type === '') {
            return ['geometry.type_required'];
        }

        if ($type === 'FeatureCollection') {
            return $this->validateFeatureCollection(collection: $geometry);
        }

        if ($type === 'Feature') {
            return $this->validateFeature(feature: $geometry);
        }

        return $this->validateBareGeometry(geometry: $geometry, type: $type);
    }//end validateGeometry()

    /**
     * Normalise a stored geometry value into a canonical GeoJSON Feature.
     *
     * Procest persists geometry JSON-encoded; this accepts either the raw
     * JSON string OR an already-decoded array and always returns a Feature
     * (geometry wrapped, properties preserved) — or null when the value is
     * empty / structurally invalid. The caller therefore never has to branch
     * on the storage shape.
     *
     * @param mixed $stored Raw stored value (JSON string or array).
     *
     * @return array<string, mixed>|null Canonical Feature, or null when unusable.
     *
     * @spec openspec/specs/gis-integration/spec.md
     */
    public function normaliseGeometry(mixed $stored): ?array
    {
        $decoded = $this->decodeStored(stored: $stored);
        if ($decoded === null) {
            return null;
        }

        if ($this->validateGeometry(geometry: $decoded) !== []) {
            return null;
        }

        $type = (string) ($decoded['type'] ?? '');

        if ($type === 'FeatureCollection') {
            $features = ($decoded['features'] ?? []);
            if (is_array($features) === false || $features === []) {
                return null;
            }

            // Use the first valid feature as the canonical anchor.
            $first = $features[0];
            if (is_array($first) === false) {
                return null;
            }

            return $this->wrapFeature(feature: $first);
        }

        if ($type === 'Feature') {
            return $this->wrapFeature(feature: $decoded);
        }

        // Bare geometry — wrap into a Feature with empty properties.
        return [
            'type'       => 'Feature',
            'geometry'   => [
                'type'        => $type,
                'coordinates' => ($decoded['coordinates'] ?? []),
            ],
            'properties' => [],
        ];
    }//end normaliseGeometry()

    /**
     * Encode a geometry/Feature for persistence (canonical write path).
     *
     * Procest stores geometry JSON-encoded; this is the single write encoder
     * so call sites do not hand-roll json_encode. Returns null when the input
     * is invalid so the caller can refuse the write.
     *
     * @param mixed $geometry Decoded GeoJSON to persist.
     *
     * @return string|null JSON string, or null when invalid.
     *
     * @spec openspec/specs/gis-integration/spec.md
     */
    public function encodeGeometry(mixed $geometry): ?string
    {
        if ($this->validateGeometry(geometry: $geometry) !== []) {
            return null;
        }

        $encoded = json_encode($geometry);
        if ($encoded === false) {
            return null;
        }

        return $encoded;
    }//end encodeGeometry()

    /**
     * Build a clustered GeoJSON FeatureCollection of case locations.
     *
     * Filters by zaaktype/status/bbox, then grid-clusters the points when the
     * requested zoom is below {@see self::CLUSTER_DISABLE_ZOOM}. Degrades to an
     * empty collection (never throws) when OpenRegister is unavailable so the
     * map view stays usable.
     *
     * @param array<string, mixed> $filters Map filters: `zaaktype`, `status`,
     *                                      `bbox` ([minLon,minLat,maxLon,maxLat]), `zoom`, `maxFeatures`,
     *                                      and `readableCaseIds`. `readableCaseIds`, when non-null,
     *                                      restricts the output to those case ids (per-object access
     *                                      guard — the caller passes the set of cases the user may read).
     *
     * @return array<string, mixed> GeoJSON FeatureCollection with metadata.
     *
     * @spec openspec/specs/gis-integration/spec.md
     *
     * @psalm-suppress MixedAssignment
     * @psalm-suppress MixedArrayAccess
     */
    public function buildCaseGeoCollection(array $filters=[]): array
    {
        $zaaktype        = ($filters['zaaktype'] ?? null);
        $status          = ($filters['status'] ?? null);
        $bbox            = ($filters['bbox'] ?? null);
        $zoom            = (int) ($filters['zoom'] ?? 0);
        $maxFeatures     = (int) ($filters['maxFeatures'] ?? self::DEFAULT_MAX_FEATURES);
        $readableCaseIds = ($filters['readableCaseIds'] ?? null);

        $limit     = min(max($maxFeatures, 1), self::MAX_FEATURES_HARD_CAP);
        $locations = $this->fetchLocations(limit: $limit);

        $points = [];
        $total  = count($locations);
        foreach ($locations as $location) {
            $point = $this->locationToPoint(location: $location);
            if ($point === null) {
                continue;
            }

            if ($zaaktype !== null && $zaaktype !== '' && $point['properties']['zaaktype'] !== $zaaktype) {
                continue;
            }

            if ($status !== null && $status !== '' && $point['properties']['status'] !== $status) {
                continue;
            }

            if ($bbox !== null && $this->isOutsideBbox(point: $point, bbox: $bbox) === true) {
                continue;
            }

            // Per-object access guard: when the caller supplies the readable
            // set, drop any location whose case is not in it (no IDOR).
            if (is_array($readableCaseIds) === true
                && in_array($point['properties']['caseId'], $readableCaseIds, true) === false
            ) {
                continue;
            }

            $points[] = $point;
        }//end foreach

        $filtered = count($points);

        $features = $points;
        if ($zoom < self::CLUSTER_DISABLE_ZOOM) {
            $features = $this->clusterPoints(points: $points, zoom: $zoom);
        }

        return [
            'type'     => 'FeatureCollection',
            'features' => $features,
            'total'    => $total,
            'filtered' => $filtered,
            'zoom'     => $zoom,
        ];
    }//end buildCaseGeoCollection()

    /**
     * List the distinct case ids that currently have a location on the map.
     *
     * Used by the map controller to run a per-object access guard: it resolves
     * the readable subset via the case-sharing service and feeds it back into
     * {@see self::buildCaseGeoCollection()} as `readableCaseIds`. Degrades to an
     * empty list (never throws) when OpenRegister is unavailable.
     *
     * @param int $limit Max location records to scan.
     *
     * @return array<int, string> Distinct, non-empty case ids.
     *
     * @spec openspec/specs/gis-integration/spec.md
     */
    public function listCaseIds(int $limit=self::DEFAULT_MAX_FEATURES): array
    {
        $cap       = min(max($limit, 1), self::MAX_FEATURES_HARD_CAP);
        $locations = $this->fetchLocations(limit: $cap);

        $ids = [];
        foreach ($locations as $location) {
            $caseId = (string) ($location['case'] ?? '');
            if ($caseId !== '') {
                $ids[$caseId] = true;
            }
        }

        return array_keys($ids);
    }//end listCaseIds()

    /**
     * Validate a bare geometry object.
     *
     * @param array<string, mixed> $geometry The geometry.
     * @param string               $type     The geometry `type`.
     *
     * @return array<int, string> Error codes.
     */
    private function validateBareGeometry(array $geometry, string $type): array
    {
        if (in_array($type, self::SUPPORTED_GEOMETRY_TYPES, true) === false) {
            return ['geometry.type_unsupported'];
        }

        if (isset($geometry['coordinates']) === false || is_array($geometry['coordinates']) === false) {
            return ['geometry.coordinates_required'];
        }

        $coords = $geometry['coordinates'];

        switch ($type) {
            case 'Point':
                if ($this->isValidPosition(position: $coords) === false) {
                    return ['geometry.coordinates_invalid'];
                }
                break;

            case 'Polygon':
                if ($this->isValidPolygon(coordinates: $coords) === false) {
                    return ['geometry.coordinates_invalid'];
                }
                break;

            case 'MultiPolygon':
                if ($coords === []) {
                    return ['geometry.coordinates_invalid'];
                }

                foreach ($coords as $polygon) {
                    if (is_array($polygon) === false || $this->isValidPolygon(coordinates: $polygon) === false) {
                        return ['geometry.coordinates_invalid'];
                    }
                }
                break;
        }//end switch

        return [];
    }//end validateBareGeometry()

    /**
     * Validate a GeoJSON Feature.
     *
     * @param array<string, mixed> $feature The Feature.
     *
     * @return array<int, string> Error codes.
     */
    private function validateFeature(array $feature): array
    {
        if (isset($feature['geometry']) === false || is_array($feature['geometry']) === false) {
            return ['feature.geometry_required'];
        }

        $geometry = $feature['geometry'];
        $geomType = '';
        if (isset($geometry['type']) === true && is_string($geometry['type']) === true) {
            $geomType = $geometry['type'];
        }

        if ($geomType === '') {
            return ['geometry.type_required'];
        }

        return $this->validateBareGeometry(geometry: $geometry, type: $geomType);
    }//end validateFeature()

    /**
     * Validate a GeoJSON FeatureCollection.
     *
     * @param array<string, mixed> $collection The FeatureCollection.
     *
     * @return array<int, string> Error codes.
     */
    private function validateFeatureCollection(array $collection): array
    {
        if (isset($collection['features']) === false || is_array($collection['features']) === false) {
            return ['featurecollection.features_required'];
        }

        foreach ($collection['features'] as $feature) {
            if (is_array($feature) === false) {
                return ['feature.invalid'];
            }

            $errors = $this->validateFeature(feature: $feature);
            if ($errors !== []) {
                return $errors;
            }
        }

        return [];
    }//end validateFeatureCollection()

    /**
     * Check whether a value is a valid WGS84 [lng, lat(, alt)] position.
     *
     * @param mixed $position The position to check.
     *
     * @return bool True when valid.
     */
    private function isValidPosition(mixed $position): bool
    {
        if (is_array($position) === false || count($position) < 2) {
            return false;
        }

        if (is_numeric($position[0]) === false || is_numeric($position[1]) === false) {
            return false;
        }

        $lng = (float) $position[0];
        $lat = (float) $position[1];

        return ($lng >= -180.0 && $lng <= 180.0 && $lat >= -90.0 && $lat <= 90.0);
    }//end isValidPosition()

    /**
     * Check whether a value is a valid GeoJSON Polygon coordinate array.
     *
     * @param mixed $coordinates The polygon coordinates (array of linear rings).
     *
     * @return bool True when valid.
     */
    private function isValidPolygon(mixed $coordinates): bool
    {
        if (is_array($coordinates) === false || $coordinates === []) {
            return false;
        }

        foreach ($coordinates as $ring) {
            if (is_array($ring) === false || count($ring) < 4) {
                return false;
            }

            foreach ($ring as $position) {
                if ($this->isValidPosition(position: $position) === false) {
                    return false;
                }
            }
        }

        return true;
    }//end isValidPolygon()

    /**
     * Decode a stored geometry value (JSON string or array) into an array.
     *
     * @param mixed $stored Raw stored value.
     *
     * @return array<string, mixed>|null Decoded array, or null.
     */
    private function decodeStored(mixed $stored): ?array
    {
        if (is_array($stored) === true) {
            return $stored;
        }

        if (is_string($stored) === false || trim($stored) === '') {
            return null;
        }

        $decoded = json_decode($stored, true);
        if (is_array($decoded) === false) {
            return null;
        }

        return $decoded;
    }//end decodeStored()

    /**
     * Wrap an arbitrary Feature-shaped array into a canonical Feature.
     *
     * @param array<string, mixed> $feature The feature-ish array.
     *
     * @return array<string, mixed>|null Canonical Feature, or null.
     */
    private function wrapFeature(array $feature): ?array
    {
        $geometry = ($feature['geometry'] ?? null);
        if (is_array($geometry) === false) {
            return null;
        }

        $properties = ($feature['properties'] ?? []);
        if (is_array($properties) === false) {
            $properties = [];
        }

        return [
            'type'       => 'Feature',
            'geometry'   => $geometry,
            'properties' => $properties,
        ];
    }//end wrapFeature()

    /**
     * Fetch location objects from OpenRegister. Degrades to [] when unavailable.
     *
     * @param int $limit Max records to fetch.
     *
     * @return array<int, array<string, mixed>> Location records.
     */
    private function fetchLocations(int $limit): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            $this->logger->warning(
                'Procest GeoService: ObjectService not available; returning empty case-geo collection',
                ['app' => Application::APP_ID]
            );
            return [];
        }

        $register       = $this->settingsService->getConfigValue('register');
        $locationSchema = $this->settingsService->getConfigValue('location_schema');

        if ($register === '' || $locationSchema === '') {
            return [];
        }

        try {
            return $this->searchObjectsAsArrays(
                objectService: $objectService,
                register: $register,
                schema: $locationSchema,
                filters: ['_limit' => $limit]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest GeoService: failed to fetch locations: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            return [];
        }
    }//end fetchLocations()

    /**
     * Convert a location record into a GeoJSON Point Feature for the map view.
     *
     * @param array<string, mixed> $location The location record.
     *
     * @return array<string, mixed>|null Point Feature, or null when no coordinates.
     */
    private function locationToPoint(array $location): ?array
    {
        $lat = null;
        if (isset($location['latitude']) === true && is_numeric($location['latitude']) === true) {
            $lat = (float) $location['latitude'];
        }

        $lng = null;
        if (isset($location['longitude']) === true && is_numeric($location['longitude']) === true) {
            $lng = (float) $location['longitude'];
        }

        if ($lat === null || $lng === null) {
            return null;
        }

        if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
            return null;
        }

        return [
            'type'       => 'Feature',
            'id'         => (string) ($location['@id'] ?? ($location['id'] ?? '')),
            'geometry'   => [
                'type'        => 'Point',
                'coordinates' => [$lng, $lat],
            ],
            'properties' => [
                'caseId'         => (string) ($location['case'] ?? ''),
                'caseIdentifier' => (string) ($location['caseIdentifier'] ?? ''),
                'caseTitle'      => (string) ($location['caseTitle'] ?? ($location['label'] ?? '')),
                'zaaktype'       => (string) ($location['caseType'] ?? ''),
                'status'         => (string) ($location['caseStatus'] ?? ''),
                'cluster'        => false,
            ],
        ];
    }//end locationToPoint()

    /**
     * Check whether a Point Feature falls outside the requested bounding box.
     *
     * @param array<string, mixed> $point The Point Feature.
     * @param array<int, float>    $bbox  [minLon, minLat, maxLon, maxLat].
     *
     * @return bool True when outside.
     */
    private function isOutsideBbox(array $point, array $bbox): bool
    {
        if (count($bbox) < 4) {
            return false;
        }

        $coords = ($point['geometry']['coordinates'] ?? null);
        if (is_array($coords) === false || count($coords) < 2) {
            return true;
        }

        $lng = (float) $coords[0];
        $lat = (float) $coords[1];

        return ($lng < $bbox[0] || $lat < $bbox[1] || $lng > $bbox[2] || $lat > $bbox[3]);
    }//end isOutsideBbox()

    /**
     * Grid-cluster a list of Point Features for a given zoom level.
     *
     * Buckets points into a lat/lng grid whose cell size shrinks as zoom
     * increases. A bucket with a single point is returned as-is; multi-point
     * buckets become a cluster Feature carrying `clusterCount`.
     *
     * @param array<int, array<string, mixed>> $points The Point Features.
     * @param int                              $zoom   The requested zoom level.
     *
     * @return array<int, array<string, mixed>> Clustered Features.
     */
    private function clusterPoints(array $points, int $zoom): array
    {
        if ($points === []) {
            return [];
        }

        // Cell size in degrees: ~standard web-mercator tile heuristic. Larger
        // cells at low zoom, finer cells as the user zooms in.
        $cellSize = (360.0 / pow(2, max($zoom, 1) + 3));

        $buckets = [];
        foreach ($points as $point) {
            $coords = $point['geometry']['coordinates'];
            $lng    = (float) $coords[0];
            $lat    = (float) $coords[1];

            $col = (int) floor($lng / $cellSize);
            $row = (int) floor($lat / $cellSize);
            $key = $col.':'.$row;

            if (isset($buckets[$key]) === false) {
                $buckets[$key] = ['sumLng' => 0.0, 'sumLat' => 0.0, 'points' => []];
            }

            $buckets[$key]['sumLng']  += $lng;
            $buckets[$key]['sumLat']  += $lat;
            $buckets[$key]['points'][] = $point;
        }//end foreach

        $features = [];
        foreach ($buckets as $bucket) {
            $count = count($bucket['points']);
            if ($count === 1) {
                $features[] = $bucket['points'][0];
                continue;
            }

            $features[] = [
                'type'       => 'Feature',
                'id'         => 'cluster-'.count($features),
                'geometry'   => [
                    'type'        => 'Point',
                    'coordinates' => [
                        ($bucket['sumLng'] / $count),
                        ($bucket['sumLat'] / $count),
                    ],
                ],
                'properties' => [
                    'cluster'      => true,
                    'clusterCount' => $count,
                ],
            ];
        }//end foreach

        return $features;
    }//end clusterPoints()
}//end class
