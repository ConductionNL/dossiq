<?php

/**
 * Procest Advice Controller
 *
 * REST API for advice request (adviesAanvraag) management.
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

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\AdviceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Controller for advice (adviesAanvraag) REST endpoints.
 *
 * @spec openspec/changes/advice-management/tasks.md#task-4
 */
class AdviceController extends Controller
{

    /**
     * The OpenRegister ObjectService (loaded dynamically).
     *
     * @var object|null
     */
    private $objectService = null;

    /**
     * Constructor.
     *
     * @param string              $appName       The app name
     * @param IRequest            $request       The request
     * @param AdviceService       $adviceService The advice service
     * @param IUserSession        $userSession   User session
     * @param ContainerInterface  $container     DI container
     * @param LoggerInterface     $logger        Logger
     *
     * @spec openspec/changes/advice-management/tasks.md#task-4
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly AdviceService $adviceService,
        private readonly IUserSession $userSession,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
        $this->loadOpenRegisterServices();
    }

    /**
     * Load OpenRegister services dynamically.
     *
     * @return void
     */
    private function loadOpenRegisterServices(): void
    {
        try {
            $this->objectService = \OC::$server->get(
                'OCA\OpenRegister\Service\ObjectService'
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'AdviceController: OpenRegister not available',
                ['exception' => $e->getMessage()]
            );
        }
    }

    /**
     * List advice requests for a case.
     *
     * @param string $caseId The case UUID (query param)
     *
     * @return JSONResponse List of advice requests
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/advice-management/tasks.md#task-4
     */
    public function index(string $caseId = ''): JSONResponse
    {
        try {
            if (empty($caseId)) {
                return new JSONResponse(['error' => 'case parameter is required'], 400);
            }

            $advice = $this->adviceService->getAdviceForCase($caseId);
            return new JSONResponse(['results' => $advice]);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Failed to list advice requests',
                ['exception' => $e->getMessage(), 'app' => Application::APP_ID]
            );
            return new JSONResponse(['error' => 'Failed to list advice requests'], 500);
        }
    }

    /**
     * Create a new advice request.
     *
     * @return JSONResponse Created advice request
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/advice-management/tasks.md#task-4
     */
    public function create(): JSONResponse
    {
        try {
            $data = json_decode($this->request->getContent() ?: '{}', true) ?: [];
            $caseId = $data['case'] ?? '';

            if (empty($caseId)) {
                return new JSONResponse(['error' => 'case is required'], 400);
            }

            $advice = $this->adviceService->createAdvice($caseId, $data);
            return new JSONResponse($advice, 201);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Failed to create advice request',
                ['exception' => $e->getMessage(), 'app' => Application::APP_ID]
            );
            return new JSONResponse(['error' => 'Failed to create advice request'], 500);
        }
    }

    /**
     * Get a single advice request.
     *
     * @param string $id The advice UUID
     *
     * @return JSONResponse The advice request
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/advice-management/tasks.md#task-4
     */
    public function show(string $id): JSONResponse
    {
        try {
            if ($this->objectService === null) {
                return new JSONResponse(['error' => 'OpenRegister not available'], 503);
            }

            $settingsService = \OC::$server->get('OCA\Procest\Service\SettingsService');
            $register = $settingsService->getConfigValue('register');
            $schema = $settingsService->getConfigValue('advies_aanvraag_schema');

            if (empty($register) || empty($schema)) {
                return new JSONResponse(['error' => 'Advice schema not configured'], 503);
            }

            $advice = $this->objectService->findObject(
                register: $register,
                schema: $schema,
                id: $id
            );

            if ($advice === null) {
                return new JSONResponse(['error' => 'Advice not found'], 404);
            }

            return new JSONResponse($advice);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Failed to get advice request',
                ['exception' => $e->getMessage(), 'app' => Application::APP_ID]
            );
            return new JSONResponse(['error' => 'Failed to get advice request'], 500);
        }
    }

    /**
     * Update an advice request.
     *
     * @param string $id The advice UUID
     *
     * @return JSONResponse Updated advice request
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/advice-management/tasks.md#task-4
     */
    public function update(string $id): JSONResponse
    {
        try {
            if ($this->objectService === null) {
                return new JSONResponse(['error' => 'OpenRegister not available'], 503);
            }

            $data = json_decode($this->request->getContent() ?: '{}', true) ?: [];
            $settingsService = \OC::$server->get('OCA\Procest\Service\SettingsService');
            $register = $settingsService->getConfigValue('register');
            $schema = $settingsService->getConfigValue('advies_aanvraag_schema');

            if (empty($register) || empty($schema)) {
                return new JSONResponse(['error' => 'Advice schema not configured'], 503);
            }

            // Merge with existing data
            $existing = $this->objectService->findObject(
                register: $register,
                schema: $schema,
                id: $id
            );

            if ($existing === null) {
                return new JSONResponse(['error' => 'Advice not found'], 404);
            }

            $data['id'] = $id;
            $updated = $this->adviceService->receiveAdvice($id, $data);
            return new JSONResponse($updated);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Failed to update advice request',
                ['exception' => $e->getMessage(), 'app' => Application::APP_ID]
            );
            return new JSONResponse(['error' => 'Failed to update advice request'], 500);
        }
    }

    /**
     * Delete an advice request.
     *
     * @param string $id The advice UUID
     *
     * @return JSONResponse Success message
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/advice-management/tasks.md#task-4
     */
    public function destroy(string $id): JSONResponse
    {
        try {
            if ($this->objectService === null) {
                return new JSONResponse(['error' => 'OpenRegister not available'], 503);
            }

            $settingsService = \OC::$server->get('OCA\Procest\Service\SettingsService');
            $register = $settingsService->getConfigValue('register');
            $schema = $settingsService->getConfigValue('advies_aanvraag_schema');

            if (empty($register) || empty($schema)) {
                return new JSONResponse(['error' => 'Advice schema not configured'], 503);
            }

            $this->objectService->deleteObject(
                register: $register,
                schema: $schema,
                id: $id
            );

            return new JSONResponse(['success' => true], 204);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Failed to delete advice request',
                ['exception' => $e->getMessage(), 'app' => Application::APP_ID]
            );
            return new JSONResponse(['error' => 'Failed to delete advice request'], 500);
        }
    }

    /**
     * Send reminder notification for an advice request.
     *
     * @param string $id The advice UUID
     *
     * @return JSONResponse Success message
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/advice-management/tasks.md#task-4
     */
    public function remind(string $id): JSONResponse
    {
        try {
            $this->adviceService->sendReminder($id);
            return new JSONResponse(['success' => true]);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Failed to send reminder',
                ['exception' => $e->getMessage(), 'app' => Application::APP_ID]
            );
            return new JSONResponse(['error' => 'Failed to send reminder'], 500);
        }
    }
}
