<?php

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
    private const BUSINESS_HOUR_START = 9;
    private const BUSINESS_HOUR_END   = 17;
    private const SLOT_DURATION       = 30;

    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

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
    }

    public function bookAppointment(array $data): array
    {
        return ['externalId' => 'local-'.bin2hex(random_bytes(8))];
    }

    public function cancelAppointment(string $externalId): bool
    {
        $this->logger->info('Local backend: appointment cancelled', ['externalId' => $externalId]);
        return true;
    }

    public function rescheduleAppointment(string $externalId, string $newDateTime): array
    {
        return ['externalId' => $externalId];
    }
}
