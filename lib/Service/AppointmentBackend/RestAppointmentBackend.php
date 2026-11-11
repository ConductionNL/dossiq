<?php

/**
 * Dossiq REST Appointment Backend.
 *
 * The calls the JCC and Qmatic backends share: one HTTP client per call,
 * each vendor's auth header, and the vendor-named error log on failure.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\AppointmentBackend
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\AppointmentBackend;

use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * An appointment backend that talks to a vendor REST API.
 *
 * Booking and cancelling are the same call for every vendor; only the auth
 * header and the name in the error log differ.
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
abstract class RestAppointmentBackend implements AppointmentBackendInterface {
	/**
	 * The HTTP client service.
	 *
	 * @var IClientService
	 */
	protected IClientService $clientService;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface
	 */
	protected LoggerInterface $logger;

	/**
	 * The vendor API base URL.
	 *
	 * @var string
	 */
	protected string $apiUrl;

	/**
	 * The vendor API key.
	 *
	 * @var string
	 */
	protected string $apiKey;

	/**
	 * Book an appointment via the vendor API.
	 *
	 * @param array<string, mixed> $data Appointment data to POST to the vendor.
	 *
	 * @return array<string, mixed> Booking result from the vendor, or error payload.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function bookAppointment(array $data): array {
		try {
			$client = $this->clientService->newClient();
			$response = $client->post(
				$this->apiUrl . '/appointments',
				[
					'json' => $data,
					'headers' => $this->authHeaders(),
				]
			);

			return json_decode($response->getBody(), true) ?? [];
		} catch (\Exception $e) {
			$this->logger->error($this->vendor() . ' booking error: ' . $e->getMessage());
			return ['error' => $e->getMessage()];
		}
	}//end bookAppointment()

	/**
	 * Cancel an appointment via the vendor API.
	 *
	 * @param string $externalId The vendor appointment id.
	 *
	 * @return bool True on success, false on API error.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function cancelAppointment(string $externalId): bool {
		try {
			$client = $this->clientService->newClient();
			$client->delete(
				$this->apiUrl . '/appointments/' . $externalId,
				['headers' => $this->authHeaders()]
			);
			return true;
		} catch (\Exception $e) {
			$this->logger->error($this->vendor() . ' cancel error: ' . $e->getMessage());
			return false;
		}
	}//end cancelAppointment()

	/**
	 * GET a vendor URL and decode its JSON body.
	 *
	 * @param string $url The full URL.
	 * @param array<string, mixed> $options The request options, auth headers included.
	 *
	 * @return mixed The decoded body, or [] when it is not JSON or the call failed (logged).
	 */
	protected function getJson(string $url, array $options): mixed {
		try {
			$client = $this->clientService->newClient();
			$response = $client->get($url, $options);

			return json_decode($response->getBody(), true) ?? [];
		} catch (\Exception $e) {
			$this->logger->error($this->vendor() . ' API error: ' . $e->getMessage());
			return [];
		}
	}//end getJson()

	/**
	 * The vendor name the error log lines start with.
	 *
	 * @return string
	 */
	abstract protected function vendor(): string;

	/**
	 * The headers that authenticate a call to the vendor API.
	 *
	 * @return array<string, string>
	 */
	abstract protected function authHeaders(): array;
}//end class
