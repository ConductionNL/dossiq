<?php

/**
 * Procest WMS/WFS Layer Service
 *
 * Resolves wmsLayer subscriptions per case type, validates layer objects, and
 * builds GetMap / GetFeature / GetCapabilities URLs that flow through the
 * existing GisProxyService — no direct outbound HTTP from this class.
 *
 * Implements wms-wfs-layers spec REQ-WMS-3, REQ-WMS-4, REQ-WMS-5, REQ-WMS-8.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-wms-wfs-layers/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for resolving WMS/WFS overlay layers per case type and routing all
 * outbound traffic through {@see GisProxyService}.
 *
 * This service NEVER issues direct outbound HTTP. Every external request is
 * delegated to {@see GisProxyService::proxyRequest()} which enforces the
 * GIS proxy allowlist (REQ-WMS-3) and rate limiting.
 */
class WmsWfsService
{

    /**
     * Maximum allowed tile width/height in pixels (REQ-WMS-5).
     */
    private const MAX_TILE_DIMENSION = 512;

    /**
     * Default WMS version when not specified on the layer.
     */
    private const DEFAULT_WMS_VERSION = '1.3.0';

    /**
     * Default WFS version when not specified on the layer.
     */
    private const DEFAULT_WFS_VERSION = '2.0.0';

    /**
     * Default extent cutoff for WFS requests in km (REQ-WMS-8).
     */
    private const DEFAULT_EXTENT_CUTOFF_KM = 50.0;

    /**
     * Constructor for WmsWfsService.
     *
     * @param GisProxyService    $gisProxyService The GIS proxy service (all outbound HTTP goes through this)
     * @param SettingsService    $settingsService The settings service (resolves register/schema ids)
     * @param ContainerInterface $container       The DI container (lazy ObjectService resolution)
     * @param LoggerInterface    $logger          The logger
     *
     * @return void
     */
    public function __construct(
        private GisProxyService $gisProxyService,
        private SettingsService $settingsService,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Resolve the set of wmsLayer objects active for a given case type.
     *
     * The resolution rules (REQ-WMS-5):
     *  - All `wmsLayer` UUIDs listed in `caseType.layerIds` are included.
     *  - All `wmsLayer` objects with `isDefault: true` are included regardless.
     *  - Inactive layers (`active: false`) are filtered out.
     *
     * @param array|object $caseType The case type object or array with `layerIds`
     *
     * @return array<int, array<string, mixed>> Plain array of layer dicts

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function getLayersForCaseType(array|object $caseType): array
    {
        $caseTypeArr = $caseType;
        if (is_object($caseType) === true && method_exists($caseType, 'jsonSerialize') === true) {
            $caseTypeArr = $caseType->jsonSerialize();
        }

        if (is_array($caseTypeArr) === false) {
            return [];
        }

        $subscribedIds = ($caseTypeArr['layerIds'] ?? []);
        if (is_array($subscribedIds) === false) {
            $subscribedIds = [];
        }

        $allLayers = $this->fetchAllLayers();
        $result    = [];
        $seen      = [];

        foreach ($allLayers as $layer) {
            $layerArr = $layer;
            if (is_object($layer) === true && method_exists($layer, 'jsonSerialize') === true) {
                $layerArr = $layer->jsonSerialize();
            }

            if (is_array($layerArr) === false) {
                continue;
            }

            $id     = (string) ($layerArr['id'] ?? ($layerArr['uuid'] ?? ''));
            $active = ($layerArr['active'] ?? true);
            if ($active === false) {
                continue;
            }

            $isDefault    = ($layerArr['isDefault'] ?? false);
            $isSubscribed = (in_array($id, $subscribedIds, true) === true);
            if ($isSubscribed === false && $isDefault !== true) {
                continue;
            }

            if (isset($seen[$id]) === true) {
                continue;
            }

            $seen[$id] = true;
            $result[]  = $layerArr;
        }//end foreach

        return $result;
    }//end getLayersForCaseType()

    /**
     * Proxy a WMS/WFS request for a specific layer through the GIS proxy.
     *
     * This is the single entry point for outbound traffic. Callers pass the
     * layer object and request parameters (REQUEST, BBOX, WIDTH, HEIGHT, ...)
     * and the service:
     *   1. Caps WIDTH/HEIGHT at 512 (REQ-WMS-5 tile cap).
     *   2. Enforces WFS BBOX extent <= extentCutoffKm (REQ-WMS-8).
     *   3. Forces queryable=false layers to reject GetFeatureInfo (REQ-WMS-7).
     *   4. Delegates the actual HTTP to GisProxyService::proxyRequest().
     *
     * @param array<string, mixed> $layer  The layer object
     * @param array<string, mixed> $params Request parameters (REQUEST, BBOX, ...)
     *
     * @return array{data: mixed, contentType: string} The proxied response
     *
     * @throws \RuntimeException When the request violates a guard rail

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function proxyRequest(array $layer, array $params): array
    {
        $type    = strtoupper((string) ($layer['type'] ?? 'WMS'));
        $request = strtoupper((string) ($params['request'] ?? $params['REQUEST'] ?? 'GetMap'));
        $url     = (string) ($layer['url'] ?? '');

        if ($url === '') {
            throw new RuntimeException('Layer has no URL', 400);
        }

        // REQ-WMS-7: non-queryable layers must not issue GetFeatureInfo.
        $queryable = (bool) ($layer['queryable'] ?? false);
        if ($queryable === false && $request === 'GETFEATUREINFO') {
            throw new RuntimeException('Layer is not queryable', 403);
        }

        // REQ-WMS-5: cap tile dimensions.
        $width  = (int) ($params['width'] ?? $params['WIDTH'] ?? 0);
        $height = (int) ($params['height'] ?? $params['HEIGHT'] ?? 0);
        if ($width > self::MAX_TILE_DIMENSION) {
            $width = self::MAX_TILE_DIMENSION;
        }

        if ($height > self::MAX_TILE_DIMENSION) {
            $height = self::MAX_TILE_DIMENSION;
        }

        // REQ-WMS-8: WFS extent guard. Reject early if bbox spans more than the cutoff.
        if ($type === 'WFS') {
            $bbox = (string) ($params['bbox'] ?? $params['BBOX'] ?? '');
            if ($bbox === '' && $request === 'GETFEATURE') {
                throw new RuntimeException('WFS GetFeature requires BBOX', 400);
            }

            $cutoffKm = (float) ($layer['extentCutoffKm'] ?? self::DEFAULT_EXTENT_CUTOFF_KM);
            if ($bbox !== '' && $this->bboxExceedsCutoff(bbox: $bbox, cutoffKm: $cutoffKm) === true) {
                throw new RuntimeException('Visible extent exceeds layer cutoff; zoom in for details', 413);
            }
        }

        // Build the upstream query.
        $version = (string) ($layer['version'] ?? '');
        if ($version === '' && $type === 'WFS') {
            $version = self::DEFAULT_WFS_VERSION;
        }

        if ($version === '' && $type !== 'WFS') {
            $version = self::DEFAULT_WMS_VERSION;
        }

        $query            = array_change_key_case($params, CASE_UPPER);
        $query['SERVICE'] = $type;
        $query['VERSION'] = $version;
        $query['REQUEST'] = $request;

        if ($type === 'WMS') {
            $query['LAYERS'] = (string) ($layer['layerName'] ?? '');
            $query['FORMAT'] = (string) ($layer['format'] ?? 'image/png');
            $query['SRS']    = (string) ($layer['srs'] ?? 'EPSG:28992');
            $query['CRS']    = $query['SRS'];
            if ($width > 0) {
                $query['WIDTH'] = (string) $width;
            }

            if ($height > 0) {
                $query['HEIGHT'] = (string) $height;
            }
        }

        if ($type !== 'WMS') {
            $query['TYPENAMES'] = (string) ($layer['layerName'] ?? '');
            $query['SRSNAME']   = (string) ($layer['srs'] ?? 'EPSG:28992');
        }

        // Delegate ALL outbound HTTP to GisProxyService — enforces allowlist + rate limit.
        return $this->gisProxyService->proxyRequest($url, $query, strtolower($type));
    }//end proxyRequest()

    /**
     * Build a GetMap URL fragment (delegated to proxy for fetch).
     *
     * Caps WIDTH/HEIGHT at 512 (REQ-WMS-5). Returns the upstream URL so the
     * frontend (Leaflet) can request through the proxy endpoint.
     *
     * @param array<string, mixed> $layer  The layer object
     * @param string               $bbox   The BBOX parameter
     * @param int                  $width  Tile width
     * @param int                  $height Tile height
     *
     * @return string Upstream URL (proxy POST path is /api/wms-wfs/proxy)

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function buildGetMapUrl(array $layer, string $bbox, int $width, int $height): string
    {
        if ($width > self::MAX_TILE_DIMENSION) {
            $width = self::MAX_TILE_DIMENSION;
        }

        if ($height > self::MAX_TILE_DIMENSION) {
            $height = self::MAX_TILE_DIMENSION;
        }

        $version = (string) ($layer['version'] ?? self::DEFAULT_WMS_VERSION);
        $query   = [
            'SERVICE' => 'WMS',
            'VERSION' => $version,
            'REQUEST' => 'GetMap',
            'LAYERS'  => (string) ($layer['layerName'] ?? ''),
            'FORMAT'  => (string) ($layer['format'] ?? 'image/png'),
            'SRS'     => (string) ($layer['srs'] ?? 'EPSG:28992'),
            'BBOX'    => $bbox,
            'WIDTH'   => (string) $width,
            'HEIGHT'  => (string) $height,
        ];

        $url       = (string) ($layer['url'] ?? '');
        $separator = '?';
        if (str_contains($url, '?') === true) {
            $separator = '&';
        }

        return $url.$separator.http_build_query($query);
    }//end buildGetMapUrl()

    /**
     * Build a GetFeature URL fragment with BBOX scoped to the visible extent.
     *
     * Always carries a BBOX (REQ-WMS-8). Caller should suppress the call when
     * the extent exceeds {@see bboxExceedsCutoff()}.
     *
     * @param array<string, mixed> $layer The layer object
     * @param string               $bbox  The BBOX parameter (mandatory)
     *
     * @return string Upstream URL
     *
     * @throws \RuntimeException When BBOX is missing

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function buildGetFeatureUrl(array $layer, string $bbox): string
    {
        if ($bbox === '') {
            throw new RuntimeException('WFS GetFeature requires BBOX', 400);
        }

        $version = (string) ($layer['version'] ?? self::DEFAULT_WFS_VERSION);
        $query   = [
            'SERVICE'   => 'WFS',
            'VERSION'   => $version,
            'REQUEST'   => 'GetFeature',
            'TYPENAMES' => (string) ($layer['layerName'] ?? ''),
            'SRSNAME'   => (string) ($layer['srs'] ?? 'EPSG:28992'),
            'BBOX'      => $bbox,
        ];

        $url       = (string) ($layer['url'] ?? '');
        $separator = '?';
        if (str_contains($url, '?') === true) {
            $separator = '&';
        }

        return $url.$separator.http_build_query($query);
    }//end buildGetFeatureUrl()

    /**
     * Fetch a single wmsLayer object by id from OpenRegister.
     *
     * @param string $layerId The layer UUID
     *
     * @return array<string, mixed>|null The layer dict, or null when not found

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function getLayerById(string $layerId): ?array
    {
        if ($layerId === '') {
            return null;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $schemaId      = $this->settingsService->getConfigValue('wms_layer_schema');
            $registerId    = $this->settingsService->getConfigValue('register');

            if (empty($schemaId) === true || empty($registerId) === true) {
                return null;
            }

            $object = $objectService->find(
                register: (int) $registerId,
                schema: (int) $schemaId,
                id: $layerId,
            );

            if ($object === null) {
                return null;
            }

            if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
                $object = $object->jsonSerialize();
            }

            if (is_array($object) === true) {
                return $object;
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'WmsWfsService::getLayerById failed',
                ['layerId' => $layerId, 'exception' => $e->getMessage()]
            );
        }//end try

        return null;
    }//end getLayerById()

    /**
     * Fetch all wmsLayer objects from OpenRegister.
     *
     * @return array<int, mixed> The layer objects (raw form)
     */
    private function fetchAllLayers(): array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $schemaId      = (int) $this->settingsService->getConfigValue('wms_layer_schema');
            $registerId    = (int) $this->settingsService->getConfigValue('register');

            if ($schemaId === 0 || $registerId === 0) {
                return [];
            }

            $layers = $objectService->findAll(
                schemaId: $schemaId,
                registerId: $registerId,
            );
            if (is_array($layers) === false) {
                return [];
            }

            return $layers;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'WmsWfsService::fetchAllLayers failed',
                ['exception' => $e->getMessage()]
            );
            return [];
        }//end try
    }//end fetchAllLayers()

    /**
     * Check whether a BBOX string spans more than the cutoff distance.
     *
     * BBOX is `minX,minY,maxX,maxY`. For EPSG:28992 (RD, metres) the cutoff
     * is converted directly to metres; for EPSG:4326 / 3857 we apply a rough
     * degree-to-km factor.
     *
     * @param string $bbox     The BBOX string
     * @param float  $cutoffKm The cutoff in km
     *
     * @return bool True when the bbox span exceeds the cutoff in either axis
     */
    private function bboxExceedsCutoff(string $bbox, float $cutoffKm): bool
    {
        $parts = explode(',', $bbox);
        if (count($parts) < 4) {
            return false;
        }

        $minX = (float) $parts[0];
        $minY = (float) $parts[1];
        $maxX = (float) $parts[2];
        $maxY = (float) $parts[3];

        $spanX = abs($maxX - $minX);
        $spanY = abs($maxY - $minY);

        // Heuristic: RD coordinates in NL are 0..300_000 metres in X and 300_000..650_000 in Y.
        // Web Mercator (EPSG:3857) is also metres but much larger absolute values.
        // EPSG:4326 spans roughly -180..180 / -90..90 — use degree factor 111 km/deg.
        $cutoffMetres = ($cutoffKm * 1000.0);
        if ($spanX > 360.0 || $spanY > 360.0) {
            // Treat as metres.
            return ($spanX > $cutoffMetres || $spanY > $cutoffMetres);
        }

        // Treat as degrees — 1 deg ~ 111 km at mid latitudes.
        $cutoffDeg = ($cutoffKm / 111.0);
        return ($spanX > $cutoffDeg || $spanY > $cutoffDeg);
    }//end bboxExceedsCutoff()
}//end class
