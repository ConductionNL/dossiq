<?php

/**
 * Procest JCC Afspraken Backend.
 *
 * Integration with the JCC Afspraken REST API used by many Dutch
 * municipalities for balie appointment management.
 *
 * @category Service
 * @package  OCA\Procest\Service\AppointmentBackend
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/retrofit-2026-05-25-appointment-booking/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Procest\Service\AppointmentBackend;

use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * JCC Afspraken API backend for appointment scheduling.
 *
 * Integrates with the JCC Afspraken REST API used by many Dutch municipalities
 * for balie appointment management.
 */
class JccBackend implements AppointmentBackendInterface
{
    /**
     * Constructor.
     *
     * @param IClientService  $clientService The HTTP client service.
     * @param LoggerInterface $logger        The logger.
     * @param string          $apiUrl        The JCC API base URL.
     * @param string          $apiKey        The JCC API bearer key.
     */
    public function __construct(
        private IClientService $clientService,
        private LoggerInterface $logger,
        private string $apiUrl,
        private string $apiKey,
    ) {
    }//end __construct()

    /**
     * Fetch available timeslots from the JCC API.
     *
     * @param string $productId  The JCC product identifier.
     * @param string $locationId The JCC location identifier.
     * @param string $date       The date (YYYY-MM-DD).
     *
     * @return array<int, array<string, mixed>> List of available timeslots.
     */
    public function getTimeslots(string $productId, string $locationId, string $date): array
    {
        try {
            $client   = $this->clientService->newClient();
            $response = $client->get(
                $this->apiUrl.'/timeslots',
                [
                    'query'   => [
                        'product'  => $productId,
                        'location' => $locationId,
                        'date'     => $date,
                    ],
                    'headers' => ['Authorization' => 'Bearer '.$this->apiKey],
                ]
            );

            return json_decode($response->getBody(), true) ?? [];
        } catch (\Exception $e) {
            $this->logger->error('JCC API error: '.$e->getMessage());
            return [];
        }
    }//end getTimeslots()

    /**
     * Book an appointment via the JCC API.
     *
     * @param array<string, mixed> $data Appointment data to POST to JCC.
     *
     * @return array<string, mixed> Booking result from JCC, or error payload.
     */
    public function bookAppointment(array $data): array
    {
        try {
            $client   = $this->clientService->newClient();
            $response = $client->post(
                $this->apiUrl.'/appointments',
                [
                    'json'    => $data,
                    'headers' => ['Authorization' => 'Bearer '.$this->apiKey],
                ]
            );

            return json_decode($response->getBody(), true) ?? [];
        } catch (\Exception $e) {
            $this->logger->error('JCC booking error: '.$e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }//end bookAppointment()

    /**
     * Cancel an appointment via the JCC API.
     *
     * @param string $externalId The JCC appointment id.
     *
     * @return bool True on success, false on API error.
     */
    public function cancelAppointment(string $externalId): bool
    {
        try {
            $client = $this->clientService->newClient();
            $client->delete(
                $this->apiUrl.'/appointments/'.$externalId,
                ['headers' => ['Authorization' => 'Bearer '.$this->apiKey]]
            );
            return true;
        } catch (\Exception $e) {
            $this->logger->error('JCC cancel error: '.$e->getMessage());
            return false;
        }
    }//end cancelAppointment()

    /**
     * Reschedule an appointment via the JCC API (cancel then book).
     *
     * @param string $externalId  The JCC appointment id.
     * @param string $newDateTime The new datetime (ISO 8601).
     *
     * @return array<string, mixed> The new booking result.
     */
    public function rescheduleAppointment(string $externalId, string $newDateTime): array
    {
        $this->cancelAppointment(externalId: $externalId);
        return $this->bookAppointment(data: ['dateTime' => $newDateTime, 'externalId' => $externalId]);
    }//end rescheduleAppointment()
}//end class
