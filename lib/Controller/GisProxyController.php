<?php

/**
 * Procest GIS Proxy Controller
 *
 * Proxies WMS/WFS requests to external GIS services to handle CORS restrictions.
 * Only allows requests to URLs that match configured MapLayer objects.
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
 * @spec openspec/changes/retrofit-2026-05-25-map-component/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\GisProxyService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for proxying WMS/WFS requests to external GIS services.
 */
class GisProxyController extends Controller
{
    /**
     * Constructor for GisProxyController.
     *
     * @param string          $appName         The application name
     * @param IRequest        $request         The request object
     * @param GisProxyService $gisProxyService The GIS proxy service
     * @param IUserSession    $userSession     The user session
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private GisProxyService $gisProxyService,
        private IUserSession $userSession,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Proxy a WMS/WFS request to an external service.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse|Response The proxied response

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function proxy(): JSONResponse|Response
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $url   = $this->request->getParam('url', '');
        $query = $this->request->getParam('query', []);
        $type  = $this->request->getParam('type', 'wms');

        if (empty($url) === true) {
            return new JSONResponse(
                ['error' => 'Missing required parameter: url'],
                400
            );
        }

        try {
            $result = $this->gisProxyService->proxyRequest($url, $query, $type);
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            $code = $e->getCode();
            if ($code === 403) {
                return new JSONResponse(
                    ['error' => 'URL not allowed: '.$e->getMessage()],
                    403
                );
            }

            if ($code === 429) {
                return new JSONResponse(
                    ['error' => 'Rate limit exceeded'],
                    429
                );
            }

            return new JSONResponse(
                ['error' => 'Proxy request failed: '.$e->getMessage()],
                502
            );
        }//end try
    }//end proxy()

    /**
     * Fetch and parse GetCapabilities from a WMS/WFS service.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse Parsed capabilities as JSON

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function capabilities(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $url  = $this->request->getParam('url', '');
        $type = $this->request->getParam('type', 'wms');

        if (empty($url) === true) {
            return new JSONResponse(
                ['error' => 'Missing required parameter: url'],
                400
            );
        }

        try {
            $capabilities = $this->gisProxyService->getCapabilities($url, $type);
            return new JSONResponse($capabilities);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => 'Failed to fetch capabilities: '.$e->getMessage()],
                502
            );
        }
    }//end capabilities()
}//end class
