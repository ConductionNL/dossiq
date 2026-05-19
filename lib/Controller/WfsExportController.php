<?php

/**
 * Procest WFS Export Controller
 *
 * Exposes case locations as a standard WFS endpoint so external GIS
 * applications (QGIS, ArcGIS, MapInfo, etc.) can consume Procest case data
 * as a map layer. Implements AC 6 of the gis-integration spec.
 *
 * Endpoint: GET /api/gis/wfs
 * Auth: NoAdminRequired — any authenticated Nextcloud user (external GIS apps
 * authenticate via HTTP Basic Auth or OIDC).
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
 * @spec openspec/changes/gis-integration/tasks.md#task-19
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\WfsExportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller exposing case locations as a WFS GeoJSON endpoint.
 *
 * @spec openspec/changes/gis-integration/tasks.md#task-19
 */
class WfsExportController extends Controller
{
    /**
     * Constructor.
     *
     * @param string           $appName          The application name
     * @param IRequest         $request          The request object
     * @param WfsExportService $wfsExportService The WFS export service
     * @param IUserSession     $userSession      The user session
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private WfsExportService $wfsExportService,
        private IUserSession $userSession,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Return case locations as a GeoJSON FeatureCollection (WFS GetFeature).
     *
     * Query parameters:
     *   - typeName:     Feature type to return (default: procest:cases)
     *   - outputFormat: Output format, only application/json supported (default: application/json)
     *   - maxFeatures:  Maximum features to return (default: 500, hard cap: 2000)
     *   - bbox:         Bounding box filter as "minLon,minLat,maxLon,maxLat" in WGS84 (optional)
     *   - status:       Filter by case status (optional)
     *   - caseType:     Filter by case type name (optional)
     *
     * @NoAdminRequired
     *
     * @return JSONResponse GeoJSON FeatureCollection
     *
     * @throws OCSForbiddenException When user session is not authenticated
     *
     * @spec openspec/changes/gis-integration/tasks.md#task-19
     */
    public function getFeatures(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            throw new OCSForbiddenException('Authentication required');
        }

        $typeName     = (string) $this->request->getParam('typeName', WfsExportService::TYPE_NAME_CASES);
        $outputFormat = (string) $this->request->getParam('outputFormat', 'application/json');
        $maxFeatures  = (int) $this->request->getParam('maxFeatures', WfsExportService::DEFAULT_MAX_FEATURES);
        $bboxParam    = $this->request->getParam('bbox', null);
        $statusRaw    = $this->request->getParam('status', null);
        $caseTypeRaw  = $this->request->getParam('caseType', null);

        $status = null;
        if ($statusRaw !== null) {
            $status = (string) $statusRaw;
        }

        $caseType = null;
        if ($caseTypeRaw !== null) {
            $caseType = (string) $caseTypeRaw;
        }

        if ($typeName !== WfsExportService::TYPE_NAME_CASES) {
            return new JSONResponse(
                ['error' => 'Unsupported typeName: '.$typeName.'. Supported: '.WfsExportService::TYPE_NAME_CASES],
                400
            );
        }

        if ($outputFormat !== 'application/json') {
            return new JSONResponse(
                ['error' => 'Unsupported outputFormat: '.$outputFormat.'. Supported: application/json'],
                400
            );
        }

        if ($maxFeatures <= 0) {
            $maxFeatures = WfsExportService::DEFAULT_MAX_FEATURES;
        }

        $bbox = null;
        if ($bboxParam !== null && $bboxParam !== '') {
            $parts = array_map('floatval', explode(',', (string) $bboxParam));
            if (count($parts) === 4) {
                $bbox = $parts;
            }
        }

        $collection = $this->wfsExportService->buildFeatureCollection(
            maxFeatures: $maxFeatures,
            bbox: $bbox,
            status: $status,
            caseType: $caseType,
        );

        return new JSONResponse($collection);
    }//end getFeatures()

    /**
     * Return WFS GetCapabilities descriptor for this endpoint.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse WFS capabilities descriptor
     *
     * @throws OCSForbiddenException When user session is not authenticated
     *
     * @spec openspec/changes/gis-integration/tasks.md#task-19
     */
    public function getCapabilities(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            throw new OCSForbiddenException('Authentication required');
        }

        $baseUrl      = $this->request->getServerProtocol().'://'.$this->request->getServerHost();
        $capabilities = $this->wfsExportService->buildCapabilities(
            baseUrl: $baseUrl.'/index.php/apps/procest/api/gis/wfs'
        );

        return new JSONResponse($capabilities);
    }//end getCapabilities()
}//end class
