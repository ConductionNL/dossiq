<?php

/**
 * Dossiq Qmatic Orchestra Backend.
 *
 * Integration with the Qmatic Orchestra REST API for appointment scheduling.
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
 * Qmatic Orchestra REST API backend for appointment scheduling.
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class QmaticBackend extends RestAppointmentBackend {
	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService The HTTP client service.
	 * @param LoggerInterface $logger The logger.
	 * @param string $apiUrl The Qmatic API base URL.
	 * @param string $apiKey The Qmatic API auth token.
	 */
	public function __construct(
		protected IClientService $clientService,
		protected LoggerInterface $logger,
		protected string $apiUrl,
		protected string $apiKey,
	) {
	}//end __construct()

	/**
	 * Fetch available timeslots from the Qmatic API.
	 *
	 * @param string $productId The Qmatic service identifier.
	 * @param string $locationId The Qmatic branch identifier.
	 * @param string $date The date (YYYY-MM-DD).
	 *
	 * @return array<int, array<string, mixed>> List of available timeslots.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getTimeslots(string $productId, string $locationId, string $date): array {
		$data = $this->getJson(
			url: $this->apiUrl . "/branches/{$locationId}/services/{$productId}/dates/{$date}/times",
			options: ['headers' => $this->authHeaders()]
		);

		$slots = [];
		foreach (($data['times'] ?? []) as $time) {
			$slots[] = [
				'time' => $time['time'] ?? '',
				'duration' => 30,
				'available' => true,
			];
		}

		return $slots;
	}//end getTimeslots()

	/**
	 * The Qmatic API takes an auth token.
	 *
	 * @return array<string, string>
	 */
	protected function authHeaders(): array {
		return ['auth-token' => $this->apiKey];
	}//end authHeaders()

	/**
	 * The name Qmatic error log lines start with.
	 *
	 * @return string
	 */
	protected function vendor(): string {
		return 'Qmatic';
	}//end vendor()

	/**
	 * Reschedule an appointment via the Qmatic API (cancel then book).
	 *
	 * @param string $externalId The Qmatic appointment id.
	 * @param string $newDateTime The new datetime (ISO 8601).
	 *
	 * @return array<string, mixed> The new booking result.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function rescheduleAppointment(string $externalId, string $newDateTime): array {
		$this->cancelAppointment(externalId: $externalId);
		return $this->bookAppointment(data: ['dateTime' => $newDateTime]);
	}//end rescheduleAppointment()
}//end class
