<?php

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
     */
    public function getTimeslots(string $productId, string $locationId, string $date): array
    {
        return $this->getBackend()->getTimeslots($productId, $locationId, $date);
    }//end getTimeslots()

    /**
     * Book an appointment linked to a case.
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
     */
    public function getAppointmentsForCase(string $caseId): array
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('appointment_schema');

        $result = $objectService->getObjects(
            (int) $register,
            (int) $schema,
            ['caseId' => $caseId],
        );

        return $result['objects'] ?? [];
    }//end getAppointmentsForCase()

    /**
     * Validate cancel token and return appointment.
     */
    public function getAppointmentByToken(string $token): ?array
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return null;
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('appointment_schema');

        $result = $objectService->getObjects(
            (int) $register,
            (int) $schema,
            ['cancelToken' => $token],
        );

        $appointments = ($result['objects'] ?? []);
        if (empty($appointments)) {
            return null;
        }

        $apt = reset($appointments);
        return is_object($apt) ? $apt->jsonSerialize() : $apt;
    }//end getAppointmentByToken()

    /**
     * Get the configured appointment backend.
     */
    private function getBackend(): AppointmentBackendInterface
    {
        $backendType = $this->settingsService->getConfigValue('appointment_backend') ?? 'local';
        $apiUrl      = $this->settingsService->getConfigValue('appointment_backend_url') ?? '';
        $apiKey      = $this->settingsService->getConfigValue('appointment_backend_api_key') ?? '';

        switch ($backendType) {
            case 'jcc':
                return new JccBackend($this->clientService, $this->logger, $apiUrl, $apiKey);
            case 'qmatic':
                return new QmaticBackend($this->clientService, $this->logger, $apiUrl, $apiKey);
            default:
                return new LocalBackend($this->logger);
        }
    }//end getBackend()

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
