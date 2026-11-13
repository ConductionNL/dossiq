<?php

/**
 * Dossiq JCC Afspraken Backend.
 *
 * Integration with the JCC Afspraken REST API used by many Dutch
 * municipalities for balie appointment management.
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
 * JCC Afspraken API backend for appointment scheduling.
 *
 * Integrates with the JCC Afspraken REST API used by many Dutch municipalities
 * for balie appointment management.
 *
 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
 */
class JccBackend extends RestAppointmentBackend {
	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService The HTTP client service.
	 * @param LoggerInterface $logger The logger.
	 * @param string $apiUrl The JCC API base URL.
	 * @param string $apiKey The JCC API bearer key.
	 */
	public function __construct(
		protected IClientService $clientService,
		protected LoggerInterface $logger,
		protected string $apiUrl,
		protected string $apiKey,
	) {
	}//end __construct()

	/**
	 * Fetch available timeslots from the JCC API.
	 *
	 * @param string $productId The JCC product identifier.
	 * @param string $locationId The JCC location identifier.
	 * @param string $date The date (YYYY-MM-DD).
	 *
	 * @return array<int, array<string, mixed>> List of available timeslots.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getTimeslots(string $productId, string $locationId, string $date): array {
		return $this->getJson(
			url: $this->apiUrl . '/timeslots',
			options: [
				'query' => [
					'product' => $productId,
					'location' => $locationId,
					'date' => $date,
				],
				'headers' => $this->authHeaders(),
			]
		);
	}//end getTimeslots()

	/**
	 * The JCC API takes a bearer key.
	 *
	 * @return array<string, string>
	 */
	protected function authHeaders(): array {
		return ['Authorization' => 'Bearer ' . $this->apiKey];
	}//end authHeaders()

	/**
	 * The name JCC error log lines start with.
	 *
	 * @return string
	 */
	protected function vendor(): string {
		return 'JCC';
	}//end vendor()

	/**
	 * Reschedule an appointment via the JCC API (cancel then book).
	 *
	 * @param string $externalId The JCC appointment id.
	 * @param string $newDateTime The new datetime (ISO 8601).
	 *
	 * @return array<string, mixed> The new booking result.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function rescheduleAppointment(string $externalId, string $newDateTime): array {
		$this->cancelAppointment(externalId: $externalId);
		return $this->bookAppointment(data: ['dateTime' => $newDateTime, 'externalId' => $externalId]);
	}//end rescheduleAppointment()
}//end class
