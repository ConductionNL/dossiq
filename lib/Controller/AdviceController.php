<?php

/**
 * Procest Advice Controller
 *
 * REST API for advice request (adviesAanvraag) management.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/advice-management/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\AdviceService;
use OCA\Procest\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for advice request (adviesAanvraag) management.
 */
class AdviceController extends Controller
{
    /**
     * Constructor.
     *
     * @param string            $appName           The app name
     * @param IRequest          $request           The request
     * @param AdviceService     $adviceService     The advice service
     * @param SettingsService   $settingsService   The settings service
     * @param LoggerInterface   $logger            The logger
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly AdviceService $adviceService,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * List advice requests for a case.
     *
     * @param string $case The case UUID
     *
     * @return JSONResponse List of advice requests
     *
     * @NoAdminRequired
     */
    public function index(string $case): JSONResponse
    {
        try {
            $advice = $this->adviceService->getAdviceForCase($case);
            return new JSONResponse(['results' => $advice]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to list advice: '.$e->getMessage());
            return new JSONResponse(['error' => 'Failed to list advice'], 400);
        }
    }//end index()

    /**
     * Create a new advice request.
     *
     * @param string $case The case UUID
     *
     * @return JSONResponse Created advice request
     *
     * @NoAdminRequired
     */
    public function create(string $case): JSONResponse
    {
        try {
            $content = $this->request->getContent();
            if ($content === '' || $content === false) {
                $content = '{}';
            }

            $decoded = json_decode($content, true);
            if (!is_array($decoded)) {
                $decoded = [];
            }

            $result = $this->adviceService->createAdvice($case, $decoded);
            return new JSONResponse($result, 201);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create advice: '.$e->getMessage());
            return new JSONResponse(['error' => 'Failed to create advice'], 400);
        }
    }//end create()

    /**
     * Get a single advice request.
     *
     * @param string $id The advice UUID
     *
     * @return JSONResponse The advice request
     *
     * @NoAdminRequired
     */
    public function show(string $id): JSONResponse
    {
        try {
            $objectService = $this->settingsService->getObjectService();
            if ($objectService === null) {
                return new JSONResponse(['error' => 'OpenRegister unavailable'], 503);
            }

            $register = $this->settingsService->getConfigValue('register');
            $schema   = $this->settingsService->getConfigValue('advies_aanvraag_schema');

            if (empty($register) || empty($schema)) {
                return new JSONResponse(['error' => 'Advice schema not configured'], 503);
            }

            $advice = $objectService->findObject($register, $schema, $id);
            if ($advice === null) {
                return new JSONResponse(['error' => 'Advice not found'], 404);
            }

            return new JSONResponse($advice->jsonSerialize());
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get advice: '.$e->getMessage());
            return new JSONResponse(['error' => 'Failed to get advice'], 400);
        }
    }//end show()

    /**
     * Update an advice request (mark received, upload document).
     *
     * @param string $id The advice UUID
     *
     * @return JSONResponse Updated advice request
     *
     * @NoAdminRequired
     */
    public function update(string $id): JSONResponse
    {
        try {
            $content = $this->request->getContent();
            if ($content === '' || $content === false) {
                $content = '{}';
            }

            $decoded = json_decode($content, true);
            if (!is_array($decoded)) {
                $decoded = [];
            }

            $fileId = $decoded['adviesDocument'] ?? '';

            if (empty($fileId)) {
                return new JSONResponse(['error' => 'adviesDocument is required'], 400);
            }

            $result = $this->adviceService->receiveAdvice($id, $fileId);
            return new JSONResponse($result);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to update advice: '.$e->getMessage());
            return new JSONResponse(['error' => 'Failed to update advice'], 400);
        }
    }//end update()

    /**
     * Delete an advice request.
     *
     * @param string $id The advice UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     */
    public function destroy(string $id): JSONResponse
    {
        try {
            $objectService = $this->settingsService->getObjectService();
            if ($objectService === null) {
                return new JSONResponse(['error' => 'OpenRegister unavailable'], 503);
            }

            $register = $this->settingsService->getConfigValue('register');
            $schema   = $this->settingsService->getConfigValue('advies_aanvraag_schema');

            if (empty($register) || empty($schema)) {
                return new JSONResponse(['error' => 'Advice schema not configured'], 503);
            }

            $objectService->deleteObject($register, $schema, $id);

            $this->logger->info('Advice deleted: '.$id);
            return new JSONResponse(null, 204);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete advice: '.$e->getMessage());
            return new JSONResponse(['error' => 'Failed to delete advice'], 400);
        }
    }//end destroy()

    /**
     * Send a reminder for an advice request.
     *
     * @param string $id The advice UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     */
    public function remind(string $id): JSONResponse
    {
        try {
            $this->adviceService->sendReminder($id);
            return new JSONResponse(['status' => 'reminder sent']);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send reminder: '.$e->getMessage());
            return new JSONResponse(['error' => 'Failed to send reminder'], 400);
        }
    }//end remind()
}//end class
