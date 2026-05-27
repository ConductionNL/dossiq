<?php

/**
 * Procest Inspection Controller
 *
 * Handles API endpoints for mobile field inspections: task listing,
 * checklist completion, photo upload, GPS capture, and inspection lifecycle.
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
 * @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\ChecklistService;
use OCA\Procest\Service\InspectionService;
use OCA\Procest\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Controller for mobile field inspection operations.
 *
 * @psalm-suppress UnusedClass
 */
class InspectionController extends Controller
{
    /**
     * Constructor.
     *
     * @param string             $appName           The app name.
     * @param IRequest           $request           The request object.
     * @param InspectionService  $inspectionService The inspection service.
     * @param ChecklistService   $checklistService  The checklist service.
     * @param SettingsService    $settingsService   The settings service.
     * @param IAppManager        $appManager        The app manager.
     * @param ContainerInterface $container         The DI container.
     * @param IUserSession       $userSession       The user session.
     * @param LoggerInterface    $logger            The logger.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly InspectionService $inspectionService,
        private readonly ChecklistService $checklistService,
        private readonly SettingsService $settingsService,
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * List inspections assigned to the current user.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function index(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $userId      = $user->getUID();
            $date        = $this->request->getParam('date');
            $objectService = $this->getObjectService();

            $allInspections = [];
            if ($objectService !== null) {
                $register = $this->settingsService->getConfigValue('register');
                $schema   = $this->settingsService->getConfigValue('inspection_schema');
                $allInspections = $objectService->findAll(
                    ['filters' => ['register' => (int) $register, 'schema' => (int) $schema, 'inspectorId' => $userId]],
                );
                $allInspections = array_map(
                    static function ($item) {
                        return is_object($item) ? $item->jsonSerialize() : (array) $item;
                    },
                    $allInspections
                );
            }

            $inspections = $this->inspectionService->getInspections($userId, $date, $allInspections);

            return new JSONResponse(['results' => $inspections]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to list inspections: {message}', ['message' => $e->getMessage()]);
            return new JSONResponse(
                ['error' => 'Failed to list inspections'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }//end index()

    /**
     * Record GPS location for an inspection.
     *
     * @param string $id The inspection ID.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function captureLocation(string $id): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return new JSONResponse(['error' => 'OpenRegister is not available'], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        try {
            $body      = $this->getRequestBody();
            $latitude  = (float) ($body['latitude'] ?? 0);
            $longitude = (float) ($body['longitude'] ?? 0);
            $accuracy  = (float) ($body['accuracy'] ?? 0);

            if ($latitude === 0.0 && $longitude === 0.0) {
                return new JSONResponse(
                    ['error' => 'Valid latitude and longitude are required'],
                    Http::STATUS_BAD_REQUEST
                );
            }

            $register   = $this->settingsService->getConfigValue('register');
            $schema     = $this->settingsService->getConfigValue('inspection_schema');
            $object     = $objectService->find($id, register: (int) $register, schema: (int) $schema);
            $inspection = is_object($object) ? $object->jsonSerialize() : (array) $object;

            $result     = $this->inspectionService->captureLocation(
                $inspection,
                $latitude,
                $longitude,
                $accuracy
            );

            $saved = $objectService->saveObject((int) $register, (int) $schema, $result['inspection']);

            return new JSONResponse(
                array_merge($result, ['inspection' => $saved->jsonSerialize()])
            );
        } catch (\Throwable $e) {
            $this->logger->error('Failed to capture location: {message}', ['message' => $e->getMessage()]);
            return new JSONResponse(
                ['error' => 'Failed to capture location'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end captureLocation()

    /**
     * Complete a checklist item.
     *
     * @param string $id     The inspection ID.
     * @param string $itemId The checklist item ID.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function completeChecklistItem(string $id, string $itemId): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $body        = $this->getRequestBody();
            $status      = $body['status'] ?? '';
            $toelichting = $body['toelichting'] ?? '';
            $photoRefs   = $body['photoRefs'] ?? [];
            $checklist   = $body['checklist'] ?? [];

            $updatedChecklist = $this->checklistService->completeItem(
                $checklist,
                $itemId,
                $status,
                $toelichting,
                $photoRefs
            );

            $progress = $this->checklistService->getProgress($updatedChecklist);

            return new JSONResponse(
                    [
                        'checklist' => $updatedChecklist,
                        'progress'  => $progress,
                    ]
                    );
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_BAD_REQUEST
            );
        } catch (\Throwable $e) {
            $this->logger->error('Failed to complete checklist item: {message}', ['message' => $e->getMessage()]);
            return new JSONResponse(
                ['error' => 'Failed to complete checklist item'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end completeChecklistItem()

    /**
     * Upload a photo for an inspection.
     *
     * @param string $id The inspection ID.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function addPhoto(string $id): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return new JSONResponse(['error' => 'OpenRegister is not available'], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        try {
            $body          = $this->getRequestBody();
            $photoMetadata = $body['photoMetadata'] ?? [];

            $register   = $this->settingsService->getConfigValue('register');
            $schema     = $this->settingsService->getConfigValue('inspection_schema');
            $object     = $objectService->find($id, register: (int) $register, schema: (int) $schema);
            $inspection = is_object($object) ? $object->jsonSerialize() : (array) $object;

            $updatedInspection = $this->inspectionService->addPhoto($inspection, $photoMetadata);

            $saved = $objectService->saveObject((int) $register, (int) $schema, $updatedInspection);

            return new JSONResponse($saved->jsonSerialize());
        } catch (\Throwable $e) {
            $this->logger->error('Failed to add photo: {message}', ['message' => $e->getMessage()]);
            return new JSONResponse(
                ['error' => 'Failed to add photo'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }//end addPhoto()

    /**
     * Complete an inspection.
     *
     * @param string $id The inspection ID.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function complete(string $id): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return new JSONResponse(['error' => 'OpenRegister is not available'], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        try {
            $body       = $this->getRequestBody();
            $conclusion = $body['conclusion'] ?? '';

            $register   = $this->settingsService->getConfigValue('register');
            $schema     = $this->settingsService->getConfigValue('inspection_schema');
            $object     = $objectService->find($id, register: (int) $register, schema: (int) $schema);
            $inspection = is_object($object) ? $object->jsonSerialize() : (array) $object;

            $result = $this->inspectionService->completeInspection($inspection, $conclusion);

            $saved = $objectService->saveObject((int) $register, (int) $schema, $result);

            return new JSONResponse($saved->jsonSerialize());
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_BAD_REQUEST
            );
        } catch (\Throwable $e) {
            $this->logger->error('Failed to complete inspection: {message}', ['message' => $e->getMessage()]);
            return new JSONResponse(
                ['error' => 'Failed to complete inspection'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }//end complete()

    /**
     * Get the parsed request body.
     *
     * @return array<string, mixed>
     */
    private function getRequestBody(): array
    {
        $body = file_get_contents('php://input');
        if ($body === false || $body === '') {
            return [];
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded) === true) {
            return $decoded;
        }

        return [];
    }//end getRequestBody()

    /**
     * Resolve the OpenRegister ObjectService if OpenRegister is installed.
     *
     * @return \OCA\OpenRegister\Service\ObjectService|null The object service or null.
     */
    private function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService
    {
        if (in_array('openregister', $this->appManager->getInstalledApps()) === false) {
            return null;
        }

        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Exception $e) {
            $this->logger->error('Procest: Could not get ObjectService', ['exception' => $e->getMessage()]);
            return null;
        }
    }//end getObjectService()
}//end class
