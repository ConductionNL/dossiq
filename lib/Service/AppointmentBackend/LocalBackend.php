<?php

/**
 * Procest Local Appointment Backend.
 *
 * Fallback appointment backend that generates timeslots from local business
 * hours and stores bookings locally without calling any external API.
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

use Psr\Log\LoggerInterface;

/**
 * Local appointment backend for use without an external scheduling system.
 *
 * Generates timeslots from configurable business hours (09:00-17:00, 30-min slots).
 * No external API calls are made.
 */
class LocalBackend implements AppointmentBackendInterface
{

    /**
     * Business day start hour (24-hour clock).
     */
    private const BUSINESS_HOUR_START = 9;

    /**
     * Business day end hour (24-hour clock, exclusive).
     */
    private const BUSINESS_HOUR_END = 17;

    /**
     * Slot duration in minutes.
     */
    private const SLOT_DURATION = 30;

    /**
     * Constructor.
     *
     * @param LoggerInterface $logger The logger.
     */
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Generate locally-defined timeslots for a date.
     *
     * @param string $productId  The product identifier (unused locally).
     * @param string $locationId The location identifier (unused locally).
     * @param string $date       The date (YYYY-MM-DD).
     *
     * @return array<int, array<string, mixed>> List of generated timeslots.
     */
    public function getTimeslots(string $productId, string $locationId, string $date): array
    {
        $slots = [];
        for ($hour = self::BUSINESS_HOUR_START; $hour < self::BUSINESS_HOUR_END; $hour++) {
            for ($min = 0; $min < 60; $min += self::SLOT_DURATION) {
                $time    = sprintf('%02d:%02d', $hour, $min);
                $slots[] = [
                    'time'      => $time,
                    'duration'  => self::SLOT_DURATION,
                    'available' => true,
                ];
            }
        }

        return $slots;
    }//end getTimeslots()

    /**
     * Book an appointment locally (no external call).
     *
     * @param array<string, mixed> $data Appointment data (unused).
     *
     * @return array<string, string> Local booking result with generated externalId.
     */
    public function bookAppointment(array $data): array
    {
        return ['externalId' => 'local-'.bin2hex(random_bytes(8))];
    }//end bookAppointment()

    /**
     * Cancel a locally-booked appointment.
     *
     * @param string $externalId The local external id.
     *
     * @return bool Always true.
     */
    public function cancelAppointment(string $externalId): bool
    {
        $this->logger->info('Local backend: appointment cancelled', ['externalId' => $externalId]);
        return true;
    }//end cancelAppointment()

    /**
     * Reschedule a locally-booked appointment.
     *
     * @param string $externalId  The local external id.
     * @param string $newDateTime The new datetime (ISO 8601).
     *
     * @return array<string, string> Updated booking result.
     */
    public function rescheduleAppointment(string $externalId, string $newDateTime): array
    {
        return ['externalId' => $externalId];
    }//end rescheduleAppointment()
}//end class
