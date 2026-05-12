<?php

/**
 * Procest Appointment Service.
 *
 * Orchestrates citizen appointments across pluggable scheduling backends
 * (JCC, Qmatic, or local fallback) and persists appointment records in
 * OpenRegister.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\Service\AppointmentBackend\AppointmentBackendInterface;
use OCA\Procest\Service\AppointmentBackend\LocalBackend;
use OCA\Procest\Service\AppointmentBackend\JccBackend;
use OCA\Procest\Service\AppointmentBackend\QmaticBackend;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing appointments linked to cases.
 *
 * Dispatches to configured backend (JCC, Qmatic, or local fallback)
 * and stores appointment records in OpenRegister.
 */
class AppointmentService
{
    /**
     * Constructor.
     *
     * @param SettingsService    $settingsService The settings service.
     * @param IAppManager        $appManager      The Nextcloud app manager.
     * @param IClientService     $clientService   The HTTP client service.
     * @param ContainerInterface $container       The DI container.
     * @param LoggerInterface    $logger          The logger.
     */
    public function __construct(
        private SettingsService $settingsService,
        private IAppManager $appManager,
        private IClientService $clientService,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get available timeslots via the configured backend.
     *
     * @param string $productId  The product identifier.
     * @param string $locationId The location identifier.
     * @param string $date       The date (YYYY-MM-DD).
     *
     * @return array<int, array<string, mixed>> List of available timeslots.
     */
    public function getTimeslots(string $productId, string $locationId, string $date): array
    {
        return $this->getBackend()->getTimeslots($productId, $locationId, $date);
    }//end getTimeslots()

    /**
     * Book an appointment linked to a case.
     *
     * @param string               $caseId The case UUID.
     * @param array<string, mixed> $data   Appointment data (product, location, dateTime, citizen info).
     *
     * @return array<string, mixed> The stored appointment record or an error payload.
     */
    public function bookAppointment(string $caseId, array $data): array
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return ['error' => 'OpenRegister is not available'];
        }

        // Book in external backend.
        $backendResult = $this->getBackend()->bookAppointment($data);

        // Store in OpenRegister.
        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('appointment_schema');

        $appointmentData = array_merge(
                $data,
                [
                    'caseId'       => $caseId,
                    'status'       => 'scheduled',
                    'externalId'   => $backendResult['externalId'] ?? null,
                    'cancelToken'  => bin2hex(random_bytes(16)),
                    'reminderSent' => false,
                ]
                );

        $result = $objectService->saveObject(
            (int) $register,
            (int) $schema,
            $appointmentData,
        );

        $this->logger->info(
                'Procest: Appointment booked',
                [
                    'caseId'        => $caseId,
                    'appointmentId' => $result->getUuid(),
                ]
                );

        return $result->jsonSerialize();
    }//end bookAppointment()

    /**
     * Cancel an appointment.
     *
     * @param string $appointmentId The appointment UUID.
     *
     * @return array<string, mixed> The updated appointment record.
     */
    public function cancelAppointment(string $appointmentId): array
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return ['error' => 'OpenRegister is not available'];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('appointment_schema');

        $appointment = $objectService->getObject((int) $register, (int) $schema, $appointmentId);
        $data        = $appointment->jsonSerialize();

        // Cancel in backend.
        if (empty($data['externalId']) === false) {
            $this->getBackend()->cancelAppointment($data['externalId']);
        }

        $data['status'] = 'cancelled';
        $result         = $objectService->saveObject((int) $register, (int) $schema, $data);

        return $result->jsonSerialize();
    }//end cancelAppointment()

    /**
     * Mark an appointment as no-show.
     *
     * @param string $appointmentId The appointment UUID.
     *
     * @return array<string, mixed> The updated appointment record.
     */
    public function markNoShow(string $appointmentId): array
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return ['error' => 'OpenRegister is not available'];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('appointment_schema');

        $appointment    = $objectService->getObject((int) $register, (int) $schema, $appointmentId);
        $data           = $appointment->jsonSerialize();
        $data['status'] = 'no_show';

        $result = $objectService->saveObject((int) $register, (int) $schema, $data);
        return $result->jsonSerialize();
    }//end markNoShow()

    /**
     * Get appointments for a case.
     *
     * @param string $caseId The case UUID.
     *
     * @return array<int, mixed> List of appointments for the case.
     */
    public function getAppointmentsForCase(string $caseId): array
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('appointment_schema');

        return $objectService->findAll(
            ['filters' => ['register' => (int) $register, 'schema' => (int) $schema, 'caseId' => $caseId]],
        );
    }//end getAppointmentsForCase()

    /**
     * Validate cancel token and return appointment.
     *
     * @param string $token The appointment public token.
     *
     * @return array<string, mixed>|null The appointment data, or null if not found.
     */
    public function getAppointmentByToken(string $token): ?array
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return null;
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('appointment_schema');

        $appointments = $objectService->findAll(
            ['filters' => ['register' => (int) $register, 'schema' => (int) $schema, 'cancelToken' => $token]],
        );
        if (empty($appointments) === true) {
            return null;
        }

        $apt = reset($appointments);
        if (is_object($apt) === true) {
            return $apt->jsonSerialize();
        }

        return $apt;
    }//end getAppointmentByToken()

    /**
     * Get the configured appointment backend.
     *
     * @return AppointmentBackendInterface The backend instance.
     */
    private function getBackend(): AppointmentBackendInterface
    {
        $backendType = $this->settingsService->getConfigValue('appointment_backend');
        if ($backendType === '') {
            $backendType = 'local';
        }

        $apiUrl = $this->settingsService->getConfigValue('appointment_backend_url');
        $apiKey = $this->settingsService->getConfigValue('appointment_backend_api_key');

        switch ($backendType) {
            case 'jcc':
                return new JccBackend(
                    clientService: $this->clientService,
                    logger: $this->logger,
                    apiUrl: $apiUrl,
                    apiKey: $apiKey
                );
            case 'qmatic':
                return new QmaticBackend(
                    clientService: $this->clientService,
                    logger: $this->logger,
                    apiUrl: $apiUrl,
                    apiKey: $apiKey
                );
            default:
                return new LocalBackend(logger: $this->logger);
        }
    }//end getBackend()

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
