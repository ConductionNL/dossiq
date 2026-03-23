<?php

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
    public function __construct(
        private IClientService $clientService,
        private LoggerInterface $logger,
        private string $apiUrl,
        private string $apiKey,
    ) {
    }

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
    }

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
    }

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
    }

    public function rescheduleAppointment(string $externalId, string $newDateTime): array
    {
        $this->cancelAppointment($externalId);
        return $this->bookAppointment(['dateTime' => $newDateTime, 'externalId' => $externalId]);
    }
}
