<?php

/**
 * Procest WFS Export Service
 *
 * Builds a GeoJSON FeatureCollection from case locations stored in OpenRegister.
 * This service provides the AC 6 requirement from the gis-integration spec:
 * exposing case locations as a standard WFS layer consumable by external GIS
 * applications (QGIS, ArcGIS, etc.).
 *
 * All outbound PDOK/OpenRegister traffic is handled by injected services;
 * this service performs only data transformation.
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
 * @spec openspec/changes/gis-integration/tasks.md#task-gis-04
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use Psr\Log\LoggerInterface;

/**
 * Builds GeoJSON FeatureCollections from case location objects for WFS export.
 *
 * @spec openspec/changes/gis-integration/tasks.md#task-gis-04
 */
class WfsExportService
{

    /**
     * Default maximum number of features to return.
     */
    public const DEFAULT_MAX_FEATURES = 500;

    /**
     * Hard cap on features per request.
     */
    public const MAX_FEATURES_HARD_CAP = 2000;

    /**
     * WFS type name this service handles.
     */
    public const TYPE_NAME_CASES = 'procest:cases';

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService The settings service (resolves register/schema ids and ObjectService)
     * @param LoggerInterface $logger          The logger
     *
     * @return void
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Build a GeoJSON FeatureCollection of case locations.
     *
     * @param int         $maxFeatures Max features to return (capped at MAX_FEATURES_HARD_CAP)
     * @param array|null  $bbox        Optional bounding box [minLon, minLat, maxLon, maxLat]
     * @param string|null $status      Optional case status filter
     * @param string|null $caseType    Optional case type filter
     *
     * @return array<string, mixed> GeoJSON FeatureCollection
     *
     * @spec openspec/changes/gis-integration/tasks.md#task-gis-04
     *
     * @psalm-suppress MixedAssignment
     * @psalm-suppress MixedArrayAccess
     */
    public function buildFeatureCollection(
        int $maxFeatures=self::DEFAULT_MAX_FEATURES,
        ?array $bbox=null,
        ?string $status=null,
        ?string $caseType=null,
    ): array {
        $limit = min($maxFeatures, self::MAX_FEATURES_HARD_CAP);

        $locations = $this->fetchLocations(limit: $limit, status: $status, caseType: $caseType);

        $features = [];
        foreach ($locations as $location) {
            $feature = $this->locationToFeature(location: $location);
            if ($feature === null) {
                continue;
            }

            if ($bbox !== null && $this->isOutsideBbox(feature: $feature, bbox: $bbox) === true) {
                continue;
            }

            $features[] = $feature;
        }

        return [
            'type'     => 'FeatureCollection',
            'name'     => self::TYPE_NAME_CASES,
            'crs'      => [
                'type'       => 'name',
                'properties' => ['name' => 'urn:ogc:def:crs:OGC:1.3:CRS84'],
            ],
            'features' => $features,
        ];
    }//end buildFeatureCollection()

    /**
     * Build a WFS GetCapabilities-style descriptor for this service.
     *
     * @param string $baseUrl The base URL of the WFS endpoint
     *
     * @return array<string, mixed> Capabilities descriptor
     *
     * @spec openspec/changes/gis-integration/tasks.md#task-gis-04
     */
    public function buildCapabilities(string $baseUrl): array
    {
        return [
            'version'      => '2.0.0',
            'title'        => 'Procest Case Locations WFS',
            'abstract'     => 'WFS endpoint exposing Procest case locations as GeoJSON features.',
            'keywords'     => ['procest', 'cases', 'locations', 'GIS', 'WFS'],
            'featureTypes' => [
                [
                    'name'          => self::TYPE_NAME_CASES,
                    'title'         => 'Case Locations',
                    'abstract'      => 'Case locations with metadata (status, type, assignee, address).',
                    'defaultCRS'    => 'urn:ogc:def:crs:OGC:1.3:CRS84',
                    'outputFormats' => ['application/json'],
                    'operations'    => ['GetCapabilities', 'GetFeature'],
                    'getFeatureUrl' => $baseUrl,
                ],
            ],
        ];
    }//end buildCapabilities()

    /**
     * Fetch location objects from OpenRegister, optionally filtered by case status/type.
     *
     * @param int         $limit    Max records to fetch
     * @param string|null $status   Optional case status filter
     * @param string|null $caseType Optional case type filter
     *
     * @return array<int, array<string, mixed>> Location records
     */
    private function fetchLocations(int $limit, ?string $status, ?string $caseType): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            $this->logger->warning(
                'Procest WfsExportService: ObjectService not available',
                ['app' => Application::APP_ID]
            );
            return [];
        }

        $register       = $this->settingsService->getConfigValue('register');
        $locationSchema = $this->settingsService->getConfigValue('location_schema');

        if ($register === '' || $locationSchema === '') {
            return [];
        }

        $params = ['_limit' => $limit];

        try {
            $raw = $objectService->findObjects($register, $locationSchema, $params);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest WfsExportService: failed to fetch locations: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            return [];
        }

        if (is_array($raw) === false) {
            return [];
        }

        // Apply optional case-level filters when status or caseType is set.
        if ($status === null && $caseType === null) {
            return $raw;
        }

        return $this->applyFilters(locations: $raw, status: $status, caseType: $caseType);
    }//end fetchLocations()

    /**
     * Filter locations by their associated case status or type.
     *
     * @param array<int, array<string, mixed>> $locations The location records
     * @param string|null                      $status    Status filter
     * @param string|null                      $caseType  Case type filter
     *
     * @return array<int, array<string, mixed>> Filtered locations
     */
    private function applyFilters(array $locations, ?string $status, ?string $caseType): array
    {
        return array_values(
            array_filter(
                $locations,
                function (array $location) use ($status, $caseType): bool {
                    if ($status !== null) {
                        $locStatus = (string) ($location['caseStatus'] ?? '');
                        if ($locStatus !== $status) {
                            return false;
                        }
                    }

                    if ($caseType !== null) {
                        $locType = (string) ($location['caseType'] ?? '');
                        if ($locType !== $caseType) {
                            return false;
                        }
                    }

                    return true;
                }
            )
        );
    }//end applyFilters()

    /**
     * Convert a single location record to a GeoJSON Feature.
     *
     * Returns null when the location lacks valid coordinates.
     *
     * @param array<string, mixed> $location The location record
     *
     * @return array<string, mixed>|null GeoJSON Feature or null
     */
    private function locationToFeature(array $location): ?array
    {
        $lat = null;
        if (isset($location['latitude']) === true) {
            $lat = (float) $location['latitude'];
        }

        $lng = null;
        if (isset($location['longitude']) === true) {
            $lng = (float) $location['longitude'];
        }

        if ($lat === null || $lng === null) {
            return null;
        }

        // Basic WGS84 sanity check.
        if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
            return null;
        }

        $properties = [
            'id'                 => (string) ($location['@id'] ?? ($location['id'] ?? '')),
            'caseId'             => (string) ($location['case'] ?? ''),
            'caseIdentifier'     => (string) ($location['caseIdentifier'] ?? ''),
            'caseTitle'          => (string) ($location['caseTitle'] ?? ''),
            'caseStatus'         => (string) ($location['caseStatus'] ?? ''),
            'caseType'           => (string) ($location['caseType'] ?? ''),
            'assignee'           => (string) ($location['assignee'] ?? ''),
            'source'             => (string) ($location['source'] ?? ''),
            'label'              => (string) ($location['label'] ?? ''),
            'formattedAddress'   => (string) ($location['formattedAddress'] ?? ''),
            'nummeraanduidingId' => (string) ($location['nummeraanduidingId'] ?? ''),
        ];

        return [
            'type'       => 'Feature',
            'id'         => $properties['id'],
            'geometry'   => [
                'type'        => 'Point',
                'coordinates' => [$lng, $lat],
            ],
            'properties' => $properties,
        ];
    }//end locationToFeature()

    /**
     * Check whether a GeoJSON Feature falls outside the requested bounding box.
     *
     * @param array<string, mixed> $feature The GeoJSON Feature
     * @param array<int, float>    $bbox    [minLon, minLat, maxLon, maxLat]
     *
     * @return bool True when the feature is outside the BBOX
     */
    private function isOutsideBbox(array $feature, array $bbox): bool
    {
        if (count($bbox) < 4) {
            return false;
        }

        $coords = $feature['geometry']['coordinates'] ?? null;
        if (is_array($coords) === false || count($coords) < 2) {
            return true;
        }

        $lng = (float) $coords[0];
        $lat = (float) $coords[1];

        return ($lng < $bbox[0] || $lat < $bbox[1] || $lng > $bbox[2] || $lat > $bbox[3]);
    }//end isOutsideBbox()
}//end class
