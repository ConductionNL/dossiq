<?php

/**
 * Procest WMS/WFS Action Controller
 *
 * Exposes the per-layer WMS/WFS proxy endpoint required by wms-wfs-layers
 * (REQ-WMS-3, REQ-WMS-5, REQ-WMS-7, REQ-WMS-8). This controller is
 * deliberately action-only — CRUD on `wmsLayer` objects is served by
 * OpenRegister's auto-exposed /api/objects/<register>/<schema> endpoints
 * (manifest-first, per ADR-008).
 *
 * @category Controller
 * @package  OCA\Procest\Controller
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
 * @spec openspec/changes/retrofit-2026-05-24-wms-wfs-layers/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\WmsWfsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Action endpoint that proxies WMS/WFS requests for a configured wmsLayer.
 */
class WmsWfsController extends Controller
{
    /**
     * Constructor for WmsWfsController.
     *
     * @param string        $appName       The application name
     * @param IRequest      $request       The request object
     * @param WmsWfsService $wmsWfsService The WMS/WFS service
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private WmsWfsService $wmsWfsService,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Proxy a request to a configured wmsLayer's upstream endpoint.
     *
     * This is the single action endpoint for wms-wfs-layers. It looks up the
     * layer by id, then delegates to {@see WmsWfsService::proxyRequest()}
     * which delegates to {@see GisProxyService::proxyRequest()} which enforces
     * the GIS proxy allowlist.
     *
     * Query parameters:
     *   - layerId: UUID of the wmsLayer object (required)
     *   - request: WMS/WFS REQUEST verb (GetMap, GetFeature, GetCapabilities, GetFeatureInfo)
     *   - bbox:    BBOX parameter for GetMap / GetFeature
     *   - width:   tile width (capped at 512)
     *   - height:  tile height (capped at 512)
     *   - any other params passed straight through to the upstream service
     *
     * @NoAdminRequired
     *
     * @return JSONResponse Proxied response or error envelope
     */
    public function proxy(): JSONResponse
    {
        $layerId = (string) $this->request->getParam('layerId', '');
        if ($layerId === '') {
            return new JSONResponse(
                ['error' => 'Missing required parameter: layerId'],
                400
            );
        }

        $layer = $this->wmsWfsService->getLayerById($layerId);
        if ($layer === null) {
            return new JSONResponse(
                ['error' => 'Layer not found'],
                404
            );
        }

        // Collect all incoming params except `layerId`.
        $params = [];
        foreach ($this->request->getParams() as $name => $value) {
            if ($name === 'layerId') {
                continue;
            }

            $params[$name] = $value;
        }

        try {
            $result = $this->wmsWfsService->proxyRequest($layer, $params);
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            $code = $e->getCode();
            if ($code < 400 || $code > 599) {
                $code = 502;
            }

            return new JSONResponse(
                ['error' => $e->getMessage()],
                $code
            );
        }
    }//end proxy()
}//end class
