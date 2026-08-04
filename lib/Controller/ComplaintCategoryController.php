<?php

/**
 * Procest Complaint Category Controller
 *
 * CRUD over the complaint category (klachtcategorie) reference list.
 *
 * Split out of ComplaintController along the resource seam — these endpoints
 * address `/api/complaint-categories`, not a complaint, and are the only ones
 * in the complaint surface that talk to OpenRegister directly rather than
 * through ComplaintService. Listing is open to any authenticated user; the two
 * writes require the coordinator (admin) role.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\Complaint\ComplaintAccessGuard;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Support\SearchesObjects;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Controller for complaint categories.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
 */
class ComplaintCategoryController extends Controller
{
    use SearchesObjects;

    /**
     * Constructor.
     *
     * @param string               $appName         App name
     * @param IRequest             $request         Request
     * @param SettingsService      $settingsService Settings service
     * @param ComplaintAccessGuard $accessGuard     Shared complaint authorization guard
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly SettingsService $settingsService,
        private readonly ComplaintAccessGuard $accessGuard,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * List complaint categories.
     *
     * @return JSONResponse List of categories
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
     */
    public function categories(): JSONResponse
    {
        if ($this->accessGuard->currentUid() === '') {
            return $this->accessGuard->notAuthenticated();
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return new JSONResponse(['results' => []]);
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('complaint_category_schema');

        if (empty($register) === true || empty($schema) === true) {
            return new JSONResponse(['results' => []]);
        }

        $list = $this->searchObjectsAsArrays(
            objectService: $objectService,
            register: $register,
            schema: $schema,
            filters: ['_limit' => 200]
        );

        return new JSONResponse(['results' => $list]);
    }//end categories()

    /**
     * Create a complaint category (admin only).
     *
     * @return JSONResponse Created category
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
     */
    public function createCategory(): JSONResponse
    {
        $userId = $this->accessGuard->currentUid();
        if ($userId === '') {
            return $this->accessGuard->notAuthenticated();
        }

        $this->accessGuard->requireCoordinator(userId: $userId);

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return new JSONResponse(['error' => 'OpenRegister not available'], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        try {
            $data     = $this->accessGuard->parseBody();
            $register = $this->settingsService->getConfigValue('register');
            $schema   = $this->settingsService->getConfigValue('complaint_category_schema');
            $category = $objectService->saveObject(object: $data, register: $register, schema: $schema);

            if (is_array($category) === true) {
                return new JSONResponse($category, Http::STATUS_CREATED);
            }

            return new JSONResponse(array_merge($data, ['id' => $category->getUuid()]), Http::STATUS_CREATED);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end createCategory()

    /**
     * Update a complaint category (admin only).
     *
     * @param string $id Category UUID
     *
     * @return JSONResponse Updated category
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
     */
    public function updateCategory(string $id): JSONResponse
    {
        $userId = $this->accessGuard->currentUid();
        if ($userId === '') {
            return $this->accessGuard->notAuthenticated();
        }

        $this->accessGuard->requireCoordinator(userId: $userId);

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return new JSONResponse(['error' => 'OpenRegister not available'], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        try {
            $data     = $this->accessGuard->parseBody();
            $register = $this->settingsService->getConfigValue('register');
            $schema   = $this->settingsService->getConfigValue('complaint_category_schema');
            $result   = $objectService->saveObject(object: $data, register: $register, schema: $schema, uuid: (string) $id);

            if (is_array($result) === true) {
                return new JSONResponse($result);
            }

            return new JSONResponse(array_merge($data, ['id' => $id]));
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end updateCategory()
}//end class
