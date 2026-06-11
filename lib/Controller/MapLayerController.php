<?php

/**
 * Procest MapLayerController
 *
 * REST API controller for `mapLayer` CRUD. Read endpoints are available to
 * any authenticated user (the layers themselves are admin-curated and not
 * user-private); mutating endpoints require admin/team-lead privilege via
 * the AuthorizedAdminSetting attribute.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/gis-integration/tasks.md#TASK-GIS-08
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\MapLayerService;
use OCA\Procest\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller exposing mapLayer CRUD endpoints.
 *
 * @psalm-suppress UnusedClass
 */
class MapLayerController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest        $request         The request.
     * @param MapLayerService $mapLayerService Layer CRUD service.
     * @param IUserSession    $userSession     User session for guards.
     * @param LoggerInterface $logger          Logger.
     */
    public function __construct(
        IRequest $request,
        private readonly MapLayerService $mapLayerService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List map layers (filterable by type / base / active).
     *
     * @return JSONResponse The layers list.
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @spec openspec/changes/gis-integration/tasks.md#TASK-GIS-08
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        $unauthorized = $this->requireAuthenticated();
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        $filters = [];
        foreach (['type', 'isBase', 'isActive'] as $key) {
            $value = $this->request->getParam($key);
            if ($value !== null && $value !== '') {
                $filters[$key] = $value;
            }
        }

        $layers = $this->mapLayerService->listLayers(filters: $filters);
        return new JSONResponse(['results' => $layers], Http::STATUS_OK);
    }//end index()

    /**
     * Fetch a single map layer by id.
     *
     * @param string $id The layer id.
     *
     * @return JSONResponse The layer.
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @spec openspec/changes/gis-integration/tasks.md#TASK-GIS-08
     */
    #[NoAdminRequired]
    public function show(string $id): JSONResponse
    {
        $unauthorized = $this->requireAuthenticated();
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        $layer = $this->mapLayerService->getLayer(id: $id);
        if ($layer === null) {
            return new JSONResponse(['error' => 'Layer not found'], Http::STATUS_NOT_FOUND);
        }
        return new JSONResponse($layer, Http::STATUS_OK);
    }//end show()

    /**
     * Create a map layer (admin only).
     *
     * @return JSONResponse The created layer.
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @spec openspec/changes/gis-integration/tasks.md#TASK-GIS-08
     */
    #[AuthorizedAdminSetting(settings: AdminSettings::class)]
    public function create(): JSONResponse
    {
        $unauthorized = $this->requireAuthenticated();
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        $payload = $this->bodyParams();

        try {
            $layer = $this->mapLayerService->createLayer(payload: $payload);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (Throwable $e) {
            $this->logger->error(
                'MapLayerController::create failed: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        return new JSONResponse($layer, Http::STATUS_CREATED);
    }//end create()

    /**
     * Update a map layer (admin only).
     *
     * @param string $id The layer id.
     *
     * @return JSONResponse The updated layer.
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @spec openspec/changes/gis-integration/tasks.md#TASK-GIS-08
     */
    #[AuthorizedAdminSetting(settings: AdminSettings::class)]
    public function update(string $id): JSONResponse
    {
        $unauthorized = $this->requireAuthenticated();
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        $payload = $this->bodyParams();

        try {
            $layer = $this->mapLayerService->updateLayer(id: $id, payload: $payload);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (Throwable $e) {
            $this->logger->error(
                'MapLayerController::update failed: '.$e->getMessage(),
                ['app' => Application::APP_ID, 'id' => $id]
            );
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        return new JSONResponse($layer, Http::STATUS_OK);
    }//end update()

    /**
     * Delete a map layer (admin only).
     *
     * @param string $id The layer id.
     *
     * @return JSONResponse Empty success envelope.
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @spec openspec/changes/gis-integration/tasks.md#TASK-GIS-08
     */
    #[AuthorizedAdminSetting(settings: AdminSettings::class)]
    public function destroy(string $id): JSONResponse
    {
        $unauthorized = $this->requireAuthenticated();
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        try {
            $ok = $this->mapLayerService->deleteLayer(id: $id);
        } catch (Throwable $e) {
            $this->logger->error(
                'MapLayerController::destroy failed: '.$e->getMessage(),
                ['app' => Application::APP_ID, 'id' => $id]
            );
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        return new JSONResponse(['success' => $ok], Http::STATUS_OK);
    }//end destroy()

    /**
     * Read body params, excluding routing params.
     *
     * @return array<string, mixed> The body params.
     */
    private function bodyParams(): array
    {
        $params = $this->request->getParams();
        unset($params['id'], $params['_route']);
        return $params;
    }//end bodyParams()

    /**
     * Require an authenticated user; return a response otherwise.
     *
     * @return JSONResponse|null Null when authorised, a response when blocked.
     */
    private function requireAuthenticated(): ?JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(
                ['error' => 'Authenticatie vereist'],
                Http::STATUS_BAD_REQUEST
            );
        }
        return null;
    }//end requireAuthenticated()
}//end class
