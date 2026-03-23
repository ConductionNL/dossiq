<?php

/**
 * Procest Consultation Service
 *
 * Service for managing inter-departmental consultations (adviesaanvragen).
 * Consultations are first-class entities linked to parent cases with their
 * own lifecycle, document exchange, and structured responses.
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
use Psr\Log\LoggerInterface;

/**
 * Service for consultation (adviesaanvraag) management.
 */
class ConsultationService
{

    /**
     * Valid consultation statuses.
     */
    private const VALID_STATUSES = [
        'open',
        'in_behandeling',
        'advies_uitgebracht',
        'afgesloten',
    ];

    /**
     * Valid advice response types.
     */
    private const VALID_RESPONSES = [
        'positief',
        'positief_met_voorwaarden',
        'negatief',
        'niet_van_toepassing',
    ];


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
    }


    /**
     * Create a consultation linked to a parent case.
     *
     * @param array<string, mixed> $data Consultation data
     *
     * @return array<string, mixed> Created consultation with ID
     *
     * @throws \RuntimeException If OpenRegister unavailable
     */
    public function createConsultation(array $data): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('consultation_schema');

        if (empty($register) === true || empty($schema) === true) {
            throw new \RuntimeException('Consultation schema not configured');
        }

        // Ensure required fields.
        if (empty($data['parentZaak']) === true) {
            throw new \RuntimeException('parentZaak is required');
        }

        if (empty($data['adviesInstantie']) === true) {
            throw new \RuntimeException('adviesInstantie is required');
        }

        // Set defaults.
        $data['status']    = 'open';
        $data['createdAt'] = date('Y-m-d\TH:i:s');

        $consultation = $objectService->saveObject($register, $schema, $data);

        $this->logger->info(
            'Consultation created: ' . $consultation->getUuid()
            . ' for case ' . $data['parentZaak'],
            ['app' => Application::APP_ID],
        );

        return [
            'id'     => $consultation->getUuid(),
            'status' => 'open',
        ];
    }


    /**
     * Get all consultations for a case.
     *
     * @param string $caseId The parent case UUID
     *
     * @return array<int, array<string, mixed>> List of consultations
     */
    public function getConsultationsForCase(string $caseId): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('consultation_schema');

        if (empty($register) === true || empty($schema) === true) {
            return [];
        }

        $results = $objectService->findObjects(
            $register,
            $schema,
            ['parentZaak' => $caseId],
            [],
            100,
        );

        return is_array($results) ? $results : [];
    }


    /**
     * Update consultation status.
     *
     * @param string $consultationId The consultation UUID
     * @param string $newStatus      The new status
     *
     * @return array<string, mixed> Updated consultation
     *
     * @throws \RuntimeException If invalid status or OpenRegister unavailable
     */
    public function updateStatus(string $consultationId, string $newStatus): array
    {
        if (in_array($newStatus, self::VALID_STATUSES, true) === false) {
            throw new \RuntimeException('Invalid status: ' . $newStatus);
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('consultation_schema');

        $updateData = ['status' => $newStatus];
        if ($newStatus === 'afgesloten') {
            $updateData['closedAt'] = date('Y-m-d\TH:i:s');
        }

        $result = $objectService->saveObject($register, $schema, $updateData, $consultationId);

        $this->logger->info(
            'Consultation ' . $consultationId . ' status updated to ' . $newStatus,
            ['app' => Application::APP_ID],
        );

        return [
            'id'     => $consultationId,
            'status' => $newStatus,
        ];
    }


    /**
     * Submit advice response to a consultation.
     *
     * @param string               $consultationId The consultation UUID
     * @param array<string, mixed> $response       Response data (advies, toelichting, voorwaarden)
     *
     * @return array<string, mixed> Updated consultation
     *
     * @throws \RuntimeException If invalid response or OpenRegister unavailable
     */
    public function submitResponse(string $consultationId, array $response): array
    {
        $advies = $response['advies'] ?? '';
        if (in_array($advies, self::VALID_RESPONSES, true) === false) {
            throw new \RuntimeException('Invalid advice type: ' . $advies);
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('consultation_schema');

        $updateData = [
            'advies'       => $advies,
            'toelichting'  => $response['toelichting'] ?? '',
            'voorwaarden'  => isset($response['voorwaarden'])
                ? json_encode($response['voorwaarden'])
                : null,
            'adviesDatum'  => date('Y-m-d'),
            'status'       => 'advies_uitgebracht',
        ];

        $result = $objectService->saveObject($register, $schema, $updateData, $consultationId);

        $this->logger->info(
            'Consultation ' . $consultationId . ' advice submitted: ' . $advies,
            ['app' => Application::APP_ID],
        );

        return [
            'id'     => $consultationId,
            'advies' => $advies,
            'status' => 'advies_uitgebracht',
        ];
    }


    /**
     * Get overdue consultations.
     *
     * @return array<int, array<string, mixed>> List of overdue consultations
     */
    public function getOverdueConsultations(): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('consultation_schema');

        if (empty($register) === true || empty($schema) === true) {
            return [];
        }

        // Fetch open/in_behandeling consultations.
        $allOpen = $objectService->findObjects(
            $register,
            $schema,
            ['status' => 'open'],
            [],
            200,
        );

        $allInProgress = $objectService->findObjects(
            $register,
            $schema,
            ['status' => 'in_behandeling'],
            [],
            200,
        );

        $all     = array_merge(
            is_array($allOpen) ? $allOpen : [],
            is_array($allInProgress) ? $allInProgress : [],
        );
        $today   = date('Y-m-d');
        $overdue = [];

        foreach ($all as $consultation) {
            $deadline = $consultation['uiterlijkeReactiedatum'] ?? '';
            if ($deadline !== '' && $deadline < $today) {
                $overdue[] = $consultation;
            }
        }

        return $overdue;
    }
}
