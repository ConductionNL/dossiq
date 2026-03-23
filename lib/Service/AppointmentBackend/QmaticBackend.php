<?php

declare(strict_types=1);

namespace OCA\Procest\Service\AppointmentBackend;

use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Qmatic Orchestra REST API backend for appointment scheduling.
 */
class QmaticBackend implements AppointmentBackendInterface
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
                $this->apiUrl."/branches/{$locationId}/services/{$productId}/dates/{$date}/times",
                ['headers' => ['auth-token' => $this->apiKey]]
            );

            $data  = json_decode($response->getBody(), true) ?? [];
            $slots = [];
            foreach (($data['times'] ?? []) as $time) {
                $slots[] = [
                    'time'      => $time['time'] ?? '',
                    'duration'  => 30,
                    'available' => true,
                ];
            }
            return $slots;
        } catch (\Exception $e) {
            $this->logger->error('Qmatic API error: '.$e->getMessage());
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
                    'headers' => ['auth-token' => $this->apiKey],
                ]
            );

            return json_decode($response->getBody(), true) ?? [];
        } catch (\Exception $e) {
            $this->logger->error('Qmatic booking error: '.$e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    public function cancelAppointment(string $externalId): bool
    {
        try {
            $client = $this->clientService->newClient();
            $client->delete(
                $this->apiUrl.'/appointments/'.$externalId,
                ['headers' => ['auth-token' => $this->apiKey]]
            );
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Qmatic cancel error: '.$e->getMessage());
            return false;
        }
    }

    public function rescheduleAppointment(string $externalId, string $newDateTime): array
    {
        $this->cancelAppointment($externalId);
        return $this->bookAppointment(['dateTime' => $newDateTime]);
    }
}
