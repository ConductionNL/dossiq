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
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\ChecklistService;
use OCA\Procest\Service\InspectionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
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
     * @param string            $appName           The app name.
     * @param IRequest          $request           The request object.
     * @param InspectionService $inspectionService The inspection service.
     * @param ChecklistService  $checklistService  The checklist service.
     * @param IUserSession      $userSession       The user session.
     * @param LoggerInterface   $logger            The logger.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly InspectionService $inspectionService,
        private readonly ChecklistService $checklistService,
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
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function index(): JSONResponse
    {
        try {
            $userId = $this->userSession->getUser()?->getUID() ?? '';
            $date   = $this->request->getParam('date');

            // In full implementation, query OpenRegister for inspections.
            $inspections = $this->inspectionService->getInspections($userId, $date, []);

            return new JSONResponse(['results' => $inspections]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to list inspections: '.$e->getMessage());
            return new JSONResponse(
                ['error' => 'Failed to list inspections: '.$e->getMessage()],
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
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function captureLocation(string $id): JSONResponse
    {
        try {
            $body       = $this->getRequestBody();
            $latitude   = (float) ($body['latitude'] ?? 0);
            $longitude  = (float) ($body['longitude'] ?? 0);
            $accuracy   = (float) ($body['accuracy'] ?? 0);
            $inspection = $body['inspection'] ?? [];

            if ($latitude === 0.0 && $longitude === 0.0) {
                return new JSONResponse(
                    ['error' => 'Valid latitude and longitude are required'],
                    Http::STATUS_BAD_REQUEST
                );
            }

            $result = $this->inspectionService->captureLocation(
                $inspection,
                $latitude,
                $longitude,
                $accuracy
            );

            return new JSONResponse($result);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to capture location: '.$e->getMessage());
            return new JSONResponse(
                ['error' => 'Failed to capture location: '.$e->getMessage()],
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
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function completeChecklistItem(string $id, string $itemId): JSONResponse
    {
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
            $this->logger->error('Failed to complete checklist item: '.$e->getMessage());
            return new JSONResponse(
                ['error' => 'Failed to complete checklist item: '.$e->getMessage()],
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
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function addPhoto(string $id): JSONResponse
    {
        try {
            $body          = $this->getRequestBody();
            $inspection    = $body['inspection'] ?? [];
            $photoMetadata = $body['photoMetadata'] ?? [];

            $updatedInspection = $this->inspectionService->addPhoto($inspection, $photoMetadata);

            return new JSONResponse($updatedInspection);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to add photo: '.$e->getMessage());
            return new JSONResponse(
                ['error' => 'Failed to add photo: '.$e->getMessage()],
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
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function complete(string $id): JSONResponse
    {
        try {
            $body       = $this->getRequestBody();
            $inspection = $body['inspection'] ?? [];
            $conclusion = $body['conclusion'] ?? '';

            $result = $this->inspectionService->completeInspection($inspection, $conclusion);

            return new JSONResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_BAD_REQUEST
            );
        } catch (\Throwable $e) {
            $this->logger->error('Failed to complete inspection: '.$e->getMessage());
            return new JSONResponse(
                ['error' => 'Failed to complete inspection: '.$e->getMessage()],
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
}//end class
