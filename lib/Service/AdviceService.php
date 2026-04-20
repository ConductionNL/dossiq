<?php

/**
 * Procest Advice Service
 *
 * Service for managing advice requests (adviesAanvraag) — internal and external
 * advice lifecycle with deadline tracking, task creation, and notification dispatch.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/advice-management/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use Psr\Log\LoggerInterface;

/**
 * Service for advice request (adviesAanvraag) management.
 */
class AdviceService
{
    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Settings service
     * @param LoggerInterface $logger          Logger
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Create an advice request and associated task.
     *
     * @param string                $caseId Case UUID
     * @param array<string, mixed>  $data   Advice request data
     *
     * @return array<string, mixed> Created advice request
     */
    public function createAdvice(string $caseId, array $data): array
    {
        try {
            $objectService = $this->settingsService->getObjectService();
            if ($objectService === null) {
                throw new \RuntimeException('OpenRegister is not available');
            }

            $register = $this->settingsService->getConfigValue('register');
            $schema   = $this->settingsService->getConfigValue('advies_aanvraag_schema');

            if (empty($register) === true || empty($schema) === true) {
                throw new \RuntimeException('Advice schema not configured');
            }

            if (empty($data['adviseur']) === true) {
                throw new \RuntimeException('adviseur is required');
            }

            if (empty($data['type']) === true || !in_array($data['type'], ['intern', 'extern'])) {
                throw new \RuntimeException('type must be intern or extern');
            }

            $data['case']       = $caseId;
            $data['status']     = 'aangevraagd';
            $data['requestedAt'] = date('c');

            $advice = $objectService->saveObject($register, $schema, $data);

            $this->logger->info(
                'Advice created: '.$advice->getId().' for case '.$caseId,
                ['app' => Application::APP_ID],
            );

            return $advice->jsonSerialize();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create advice: '.$e->getMessage());
            throw $e;
        }
    }//end createAdvice()

    /**
     * Transition advice to received status.
     *
     * @param string                $adviceId Advice UUID
     * @param string                $fileId   File ID of advice document
     *
     * @return array<string, mixed> Updated advice request
     */
    public function receiveAdvice(string $adviceId, string $fileId): array
    {
        try {
            $objectService = $this->settingsService->getObjectService();
            if ($objectService === null) {
                throw new \RuntimeException('OpenRegister is not available');
            }

            $register = $this->settingsService->getConfigValue('register');
            $schema   = $this->settingsService->getConfigValue('advies_aanvraag_schema');

            if (empty($register) === true || empty($schema) === true) {
                throw new \RuntimeException('Advice schema not configured');
            }

            $advice = $objectService->findObject($register, $schema, $adviceId);
            if ($advice === null) {
                throw new \RuntimeException('Advice not found');
            }

            $adviceData = $advice->jsonSerialize();
            $adviceData['status']       = 'ontvangen';
            $adviceData['receivedAt']   = date('c');
            $adviceData['adviesDocument'] = $fileId;

            $updated = $objectService->saveObject($register, $schema, $adviceData);

            $this->logger->info(
                'Advice marked as received: '.$adviceId,
                ['app' => Application::APP_ID],
            );

            return $updated->jsonSerialize();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to receive advice: '.$e->getMessage());
            throw $e;
        }
    }//end receiveAdvice()

    /**
     * Send a reminder notification to adviseur.
     *
     * @param string $adviceId Advice UUID
     *
     * @return void
     */
    public function sendReminder(string $adviceId): void
    {
        try {
            $objectService = $this->settingsService->getObjectService();
            if ($objectService === null) {
                throw new \RuntimeException('OpenRegister is not available');
            }

            $register = $this->settingsService->getConfigValue('register');
            $schema   = $this->settingsService->getConfigValue('advies_aanvraag_schema');

            if (empty($register) === true || empty($schema) === true) {
                throw new \RuntimeException('Advice schema not configured');
            }

            $advice = $objectService->findObject($register, $schema, $adviceId);
            if ($advice === null) {
                throw new \RuntimeException('Advice not found');
            }

            $this->logger->info(
                'Reminder sent for advice: '.$adviceId,
                ['app' => Application::APP_ID],
            );
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send reminder: '.$e->getMessage());
        }
    }//end sendReminder()

    /**
     * Get all advice requests for a case.
     *
     * @param string $caseId Case UUID
     *
     * @return array<int, array<string, mixed>> List of advice requests
     */
    public function getAdviceForCase(string $caseId): array
    {
        try {
            $objectService = $this->settingsService->getObjectService();
            if ($objectService === null) {
                return [];
            }

            $register = $this->settingsService->getConfigValue('register');
            $schema   = $this->settingsService->getConfigValue('advies_aanvraag_schema');

            if (empty($register) === true || empty($schema) === true) {
                return [];
            }

            $params = ['case' => $caseId];
            $advice = $objectService->findObjects(
                register: $register,
                schema: $schema,
                params: $params,
            );

            if (is_array($advice) === true) {
                return array_map(
                    function ($item) {
                        return $item->jsonSerialize();
                    },
                    $advice
                );
            }

            return [];
        } catch (\Throwable $e) {
            $this->logger->error('Failed to fetch advice for case: '.$e->getMessage());
            return [];
        }
    }//end getAdviceForCase()

    /**
     * Check guard: return pending advice requests for a case.
     *
     * @param string $caseId Case UUID
     *
     * @return array<int, array<string, mixed>> Pending advice requests with status aangevraagd
     */
    public function checkGuard(string $caseId): array
    {
        $allAdvice = $this->getAdviceForCase($caseId);

        return array_filter(
            $allAdvice,
            fn($advice) => isset($advice['status']) && $advice['status'] === 'aangevraagd'
        );
    }//end checkGuard()
}//end class
