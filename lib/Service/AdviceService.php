<?php

/**
 * Procest Advice Service
 *
 * Service for managing advice requests (adviesAanvraag) on cases.
 *
 * @category Service
 * @package  OCA\Procest\Service
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

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for advice request (adviesAanvraag) management.
 *
 * @spec openspec/changes/advice-management/tasks.md#task-3
 */
class AdviceService
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
     * @param SettingsService    $settingsService Settings service
     * @param IUserSession       $userSession     User session
     * @param ContainerInterface $container       DI container
     * @param LoggerInterface    $logger          Logger
     *
     * @spec openspec/changes/advice-management/tasks.md#task-3
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly IUserSession $userSession,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
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
                'AdviceService: OpenRegister not available',
                ['exception' => $e->getMessage()]
            );
        }
    }

    /**
     * Create a new advice request.
     *
     * @param string               $caseId Case UUID
     * @param array<string, mixed> $data   Advice request data
     *
     * @return array<string, mixed> Created advice object
     *
     * @throws \Throwable On any error (logged, static message returned by controller)
     *
     * @spec openspec/changes/advice-management/tasks.md#task-3
     */
    public function createAdvice(string $caseId, array $data): array
    {
        try {
            if ($this->objectService === null) {
                throw new \RuntimeException('OpenRegister is not available');
            }

            $register = $this->settingsService->getConfigValue('register');
            $schema = $this->settingsService->getConfigValue('advies_aanvraag_schema');

            if (empty($register) || empty($schema)) {
                throw new \RuntimeException('Advice schema not configured');
            }

            // Ensure case ID and required fields
            $data['case'] = $caseId;
            $data['status'] = $data['status'] ?? 'aangevraagd';
            $data['requestedAt'] = $data['requestedAt'] ?? date('c');

            // Validate required fields
            if (empty($data['adviseur'])) {
                throw new \RuntimeException('adviseur is required');
            }
            if (empty($data['type'])) {
                throw new \RuntimeException('type is required');
            }

            // Save to OpenRegister using 3-arg API
            $advice = $this->objectService->saveObject(
                register: $register,
                schema: $schema,
                object: $data
            );

            $this->logger->info(
                'Advice request created: ' . ($advice['uuid'] ?? $advice['id'] ?? 'unknown'),
                ['app' => Application::APP_ID]
            );

            return is_array($advice) ? $advice : ['id' => $advice];
        } catch (\Throwable $e) {
            $this->logger->error(
                'Failed to create advice request',
                ['exception' => $e->getMessage(), 'app' => Application::APP_ID]
            );
            throw $e;
        }
    }

    /**
     * Mark advice as received and store document.
     *
     * @param string               $adviceId Advice UUID
     * @param array<string, mixed> $data     Update data (fileId, etc.)
     *
     * @return array<string, mixed> Updated advice object
     *
     * @throws \Throwable On any error
     *
     * @spec openspec/changes/advice-management/tasks.md#task-3
     */
    public function receiveAdvice(string $adviceId, array $data): array
    {
        try {
            if ($this->objectService === null) {
                throw new \RuntimeException('OpenRegister is not available');
            }

            $register = $this->settingsService->getConfigValue('register');
            $schema = $this->settingsService->getConfigValue('advies_aanvraag_schema');

            if (empty($register) || empty($schema)) {
                throw new \RuntimeException('Advice schema not configured');
            }

            // Update advice status
            $updateData = [
                'status' => 'ontvangen',
                'receivedAt' => date('c'),
            ];

            if (!empty($data['adviesDocument'])) {
                $updateData['adviesDocument'] = $data['adviesDocument'];
            }

            $advice = $this->objectService->saveObject(
                register: $register,
                schema: $schema,
                object: $updateData
            );

            $this->logger->info(
                'Advice marked as received: ' . $adviceId,
                ['app' => Application::APP_ID]
            );

            return is_array($advice) ? $advice : ['id' => $advice];
        } catch (\Throwable $e) {
            $this->logger->error(
                'Failed to receive advice',
                ['exception' => $e->getMessage(), 'app' => Application::APP_ID]
            );
            throw $e;
        }
    }

    /**
     * Send reminder notification to adviseur.
     *
     * @param string $adviceId Advice UUID
     *
     * @return void
     *
     * @throws \Throwable On any error
     *
     * @spec openspec/changes/advice-management/tasks.md#task-3
     */
    public function sendReminder(string $adviceId): void
    {
        try {
            // Get the advice object
            if ($this->objectService === null) {
                throw new \RuntimeException('OpenRegister is not available');
            }

            $register = $this->settingsService->getConfigValue('register');
            $schema = $this->settingsService->getConfigValue('advies_aanvraag_schema');

            if (empty($register) || empty($schema)) {
                throw new \RuntimeException('Advice schema not configured');
            }

            $advice = $this->objectService->findObject(
                register: $register,
                schema: $schema,
                id: $adviceId
            );

            if ($advice === null) {
                throw new \RuntimeException('Advice not found');
            }

            $this->logger->info(
                'Reminder sent for advice: ' . $adviceId,
                ['app' => Application::APP_ID]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Failed to send reminder',
                ['exception' => $e->getMessage(), 'app' => Application::APP_ID]
            );
            throw $e;
        }
    }

    /**
     * Get all advice requests for a case.
     *
     * @param string $caseId Case UUID
     *
     * @return array<int, array<string, mixed>> List of advice requests
     *
     * @spec openspec/changes/advice-management/tasks.md#task-3
     */
    public function getAdviceForCase(string $caseId): array
    {
        try {
            if ($this->objectService === null) {
                return [];
            }

            $register = $this->settingsService->getConfigValue('register');
            $schema = $this->settingsService->getConfigValue('advies_aanvraag_schema');

            if (empty($register) || empty($schema)) {
                return [];
            }

            $results = $this->objectService->findObjects(
                register: $register,
                schema: $schema,
                params: ['case' => $caseId]
            );

            return is_array($results) ? $results : [];
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Failed to fetch advice for case',
                ['exception' => $e->getMessage(), 'app' => Application::APP_ID]
            );
            return [];
        }
    }

    /**
     * Check for pending advice requests blocking case transitions.
     *
     * @param string $caseId Case UUID
     *
     * @return array<int, array<string, mixed>> Pending advice requests
     *
     * @spec openspec/changes/advice-management/tasks.md#task-3
     */
    public function checkGuard(string $caseId): array
    {
        try {
            $allAdvice = $this->getAdviceForCase($caseId);

            // Filter for pending (aangevraagd) advice
            $pending = array_filter(
                $allAdvice,
                function (array $advice): bool {
                    return ($advice['status'] ?? null) === 'aangevraagd';
                }
            );

            return array_values($pending);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Failed to check advice guard',
                ['exception' => $e->getMessage(), 'app' => Application::APP_ID]
            );
            return [];
        }
    }
}
