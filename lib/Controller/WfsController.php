<?php

/**
 * Procest WFS Controller
 *
 * OGC Web Feature Service (WFS) 2.0.0 endpoint exposing case locations as a
 * standard map layer at `GET /wfs/cases`. External GIS applications (QGIS,
 * ArcGIS, MapInfo) can add this endpoint as a feature source.
 *
 * This is the XML face of the GIS export. It REUSES {@see WfsService} (which in
 * turn reuses {@see WfsExportService} for data) and dispatches on the standard
 * WFS `request` parameter: GetCapabilities, DescribeFeatureType, GetFeature.
 *
 * Authorisation: `#[NoAdminRequired]` (any authenticated Nextcloud user;
 * external GIS clients authenticate via HTTP Basic / OIDC). The endpoint is
 * gated by the `geo_wfs_endpoint_enabled` setting (default on). Output is
 * limited to case location + minimal metadata — no protected case detail.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
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

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\WfsExportService;
use OCA\Procest\Service\WfsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller serving the OGC WFS 2.0.0 case-locations endpoint.
 *
 * @spec openspec/specs/gis-integration/spec.md
 *
 * @psalm-suppress UnusedClass
 */
class WfsController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest        $request         The request.
     * @param WfsService      $wfsService      OGC WFS XML renderer.
     * @param SettingsService $settingsService For the endpoint-enabled toggle.
     * @param IUserSession    $userSession     User session guard.
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly WfsService $wfsService,
        private readonly SettingsService $settingsService,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Handle a WFS request, dispatching on the `request` parameter.
     *
     * @return DataDisplayResponse XML (or an OGC ExceptionReport on error).
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @spec openspec/specs/gis-integration/spec.md
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function cases(): DataDisplayResponse
    {
        if ($this->userSession->getUser() === null) {
            return $this->exception(message: 'Authentication required', status: Http::STATUS_UNAUTHORIZED);
        }

        if ($this->isEndpointEnabled() === false) {
            return $this->exception(message: 'WFS endpoint is disabled', status: Http::STATUS_FORBIDDEN);
        }

        $service = strtoupper((string) $this->request->getParam('service', 'WFS'));
        if ($service !== 'WFS') {
            return $this->exception(message: 'Unsupported service: '.$service, status: Http::STATUS_BAD_REQUEST);
        }

        $requestType = strtolower((string) $this->request->getParam('request', 'GetCapabilities'));

        return match ($requestType) {
            'getcapabilities'     => $this->xml(xml: $this->wfsService->getCapabilities(baseUrl: $this->endpointUrl())),
            'describefeaturetype' => $this->xml(xml: $this->wfsService->describeFeatureType()),
            'getfeature'          => $this->renderGetFeature(),
            default               => $this->exception(message: 'Unsupported request: '.$requestType, status: Http::STATUS_BAD_REQUEST),
        };
    }//end cases()

    /**
     * Render a GetFeature response, parsing the BBOX/status/type filters.
     *
     * @return DataDisplayResponse The GML FeatureCollection.
     */
    private function renderGetFeature(): DataDisplayResponse
    {
        $maxFeatures = (int) $this->request->getParam('count', $this->request->getParam('maxFeatures', WfsExportService::DEFAULT_MAX_FEATURES));
        if ($maxFeatures <= 0) {
            $maxFeatures = WfsExportService::DEFAULT_MAX_FEATURES;
        }

        $bbox     = $this->parseBbox(raw: (string) $this->request->getParam('bbox', ''));
        $status   = $this->stringParamOrNull(key: 'status');
        $caseType = $this->stringParamOrNull(key: 'caseType');

        $xml = $this->wfsService->getFeature(
            bbox: $bbox,
            maxFeatures: $maxFeatures,
            status: $status,
            caseType: $caseType,
        );

        return $this->xml(xml: $xml);
    }//end renderGetFeature()

    /**
     * Parse a WFS BBOX parameter into [minLon, minLat, maxLon, maxLat].
     *
     * Accepts "minLon,minLat,maxLon,maxLat" optionally with a trailing CRS
     * token (",EPSG:4326"), which is ignored — coordinates are assumed WGS84.
     *
     * @param string $raw The raw bbox parameter.
     *
     * @return array<int, float>|null Parsed bbox, or null when absent/invalid.
     */
    private function parseBbox(string $raw): ?array
    {
        if (trim($raw) === '') {
            return null;
        }

        $parts = array_map('trim', explode(',', $raw));
        // Drop a trailing CRS token if present.
        $numeric = array_values(
            array_filter($parts, static fn ($p): bool => is_numeric($p))
        );

        if (count($numeric) < 4) {
            return null;
        }

        return [
            (float) $numeric[0],
            (float) $numeric[1],
            (float) $numeric[2],
            (float) $numeric[3],
        ];
    }//end parseBbox()

    /**
     * Whether the WFS endpoint is enabled (default: enabled).
     *
     * @return bool True when enabled.
     */
    private function isEndpointEnabled(): bool
    {
        $value = $this->settingsService->getConfigValue('geo_wfs_endpoint_enabled', '1');
        return ($value !== '0' && strtolower($value) !== 'false');
    }//end isEndpointEnabled()

    /**
     * Build the absolute endpoint URL for capabilities advertising.
     *
     * @return string The endpoint URL.
     */
    private function endpointUrl(): string
    {
        $base = $this->request->getServerProtocol().'://'.$this->request->getServerHost();
        return $base.'/index.php/apps/procest/wfs/cases';
    }//end endpointUrl()

    /**
     * Read a request param as a non-empty string, or null.
     *
     * @param string $key The param name.
     *
     * @return string|null The value, or null when absent/empty.
     */
    private function stringParamOrNull(string $key): ?string
    {
        $raw = $this->request->getParam($key, null);
        if ($raw === null || $raw === '') {
            return null;
        }

        return (string) $raw;
    }//end stringParamOrNull()

    /**
     * Wrap an XML string in a DataDisplayResponse.
     *
     * @param string $xml The XML body.
     *
     * @return DataDisplayResponse The response.
     */
    private function xml(string $xml): DataDisplayResponse
    {
        return new DataDisplayResponse($xml, Http::STATUS_OK, ['Content-Type' => 'text/xml; charset=UTF-8']);
    }//end xml()

    /**
     * Build an OGC WFS ExceptionReport response.
     *
     * @param string $message The error message.
     * @param int    $status  The HTTP status.
     *
     * @return DataDisplayResponse The exception XML.
     */
    private function exception(string $message, int $status): DataDisplayResponse
    {
        $escaped = htmlspecialchars($message, (ENT_QUOTES | ENT_XML1), 'UTF-8');
        $xml     = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<ows:ExceptionReport xmlns:ows="http://www.opengis.net/ows/1.1" version="2.0.0">'."\n"
            .'  <ows:Exception exceptionCode="InvalidParameterValue">'."\n"
            .'    <ows:ExceptionText>'.$escaped.'</ows:ExceptionText>'."\n"
            .'  </ows:Exception>'."\n"
            .'</ows:ExceptionReport>'."\n";

        return new DataDisplayResponse($xml, $status, ['Content-Type' => 'text/xml; charset=UTF-8']);
    }//end exception()
}//end class
