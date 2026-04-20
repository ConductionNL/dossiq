<?php

/**
 * Procest Inspection Service
 *
 * Service for managing field inspections: task listing, GPS location
 * capture and validation, and inspection lifecycle management.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use Psr\Log\LoggerInterface;

/**
 * Service for managing field inspections.
 *
 * Handles inspection task listing, GPS location capture with distance
 * validation, photo metadata management, and inspection completion.
 *
 * @psalm-suppress UnusedClass
 */
class InspectionService
{
    /**
     * Inspection status: planned.
     */
    public const STATUS_PLANNED = 'planned';

    /**
     * Inspection status: in progress.
     */
    public const STATUS_IN_PROGRESS = 'in_progress';

    /**
     * Inspection status: completed.
     */
    public const STATUS_COMPLETED = 'completed';

    /**
     * Maximum distance (in meters) before showing a location mismatch warning.
     */
    private const LOCATION_WARNING_THRESHOLD = 500;

    /**
     * Earth radius in meters for Haversine calculation.
     */
    private const EARTH_RADIUS = 6371000;

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Settings service
     * @param LoggerInterface $logger          Logger
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get inspections assigned to an inspector, optionally filtered by date.
     *
     * @param string                           $inspectorId    The inspector's user ID.
     * @param string|null                      $date           Optional date filter (Y-m-d format).
     * @param array<int, array<string, mixed>> $allInspections All inspection data (from OpenRegister).
     *
     * @return array<int, array<string, mixed>> Filtered and sorted inspections.
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function getInspections(
        string $inspectorId,
        ?string $date,
        array $allInspections,
    ): array {
        $filtered = array_filter(
                $allInspections,
                function (array $inspection) use ($inspectorId, $date): bool {
                    if (($inspection['inspectorId'] ?? '') !== $inspectorId) {
                        return false;
                    }

                    if ($date !== null) {
                        $inspectionDate = substr($inspection['plannedDateTime'] ?? '', 0, 10);
                        if ($inspectionDate !== $date) {
                            return false;
                        }
                    }

                    return true;
                }
                );

        // Sort by planned time.
        usort(
                $filtered,
                function (array $a, array $b): int {
                    return ($a['plannedDateTime'] ?? '') <=> ($b['plannedDateTime'] ?? '');
                }
                );

        return array_values($filtered);
    }//end getInspections()

    /**
     * Capture GPS location for an inspection and validate against planned location.
     *
     * @param array<string, mixed> $inspection The inspection data.
     * @param float                $latitude   The captured latitude.
     * @param float                $longitude  The captured longitude.
     * @param float                $accuracy   The GPS accuracy in meters.
     *
     * @return array{
     *     inspection: array<string, mixed>,
     *     warning: string|null,
     *     distance: float
     * }
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function captureLocation(
        array $inspection,
        float $latitude,
        float $longitude,
        float $accuracy,
    ): array {
        $inspection['capturedLocation'] = [
            'latitude'   => $latitude,
            'longitude'  => $longitude,
            'accuracy'   => $accuracy,
            'capturedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        $warning  = null;
        $distance = 0.0;

        // Check distance from planned location.
        $plannedLat = (float) ($inspection['plannedLatitude'] ?? 0.0);
        $plannedLon = (float) ($inspection['plannedLongitude'] ?? 0.0);

        if ($plannedLat !== 0.0 && $plannedLon !== 0.0) {
            $distance = $this->calculateDistance(lat1: $latitude, lon1: $longitude, lat2: $plannedLat, lon2: $plannedLon);

            if ($distance > self::LOCATION_WARNING_THRESHOLD) {
                $warning = sprintf(
                    'Uw locatie wijkt af van het inspectieadres (%.0f meter afstand)',
                    $distance
                );
                $this->logger->warning(
                    'Location mismatch for inspection {id}: {distance}m from planned',
                    [
                        'id'       => $inspection['id'] ?? 'unknown',
                        'distance' => round($distance),
                    ]
                );
            }
        }

        if ($inspection['status'] === self::STATUS_PLANNED) {
            $inspection['status'] = self::STATUS_IN_PROGRESS;
        }

        return [
            'inspection' => $inspection,
            'warning'    => $warning,
            'distance'   => round($distance, 1),
        ];
    }//end captureLocation()

    /**
     * Record photo metadata for an inspection.
     *
     * @param array<string, mixed> $inspection    The inspection data.
     * @param array<string, mixed> $photoMetadata Photo info (fileRef, latitude, longitude, checklistItemId).
     *
     * @return array<string, mixed> The updated inspection with photo added.
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function addPhoto(array $inspection, array $photoMetadata): array
    {
        $photo = [
            'id'              => $photoMetadata['id'] ?? uniqid('photo_', true),
            'fileRef'         => $photoMetadata['fileRef'] ?? '',
            'latitude'        => $photoMetadata['latitude'] ?? null,
            'longitude'       => $photoMetadata['longitude'] ?? null,
            'checklistItemId' => $photoMetadata['checklistItemId'] ?? null,
            'capturedAt'      => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        $inspection['photos']   = $inspection['photos'] ?? [];
        $inspection['photos'][] = $photo;

        return $inspection;
    }//end addPhoto()

    /**
     * Complete an inspection.
     *
     * @param array<string, mixed> $inspection The inspection data.
     * @param string               $conclusion Overall conclusion text.
     *
     * @return array<string, mixed> The completed inspection.
     *
     * @throws \InvalidArgumentException If not all checklist items are completed.
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function completeInspection(array $inspection, string $conclusion=''): array
    {
        $checklist = $inspection['checklist'] ?? [];
        $items     = $checklist['items'] ?? [];

        // Check if all items are completed.
        foreach ($items as $item) {
            if (empty($item['status']) === true) {
                throw new \InvalidArgumentException(
                    'Not all checklist items are completed. Item: '.($item['description'] ?? 'unknown')
                );
            }
        }

        $inspection['status']      = self::STATUS_COMPLETED;
        $inspection['conclusion']  = $conclusion;
        $inspection['completedAt'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        $this->logger->info(
            'Inspection {id} completed',
            ['id' => $inspection['id'] ?? 'unknown']
        );

        return $inspection;
    }//end completeInspection()

    /**
     * Calculate distance between two GPS coordinates using Haversine formula.
     *
     * @param float $lat1 Latitude of point 1.
     * @param float $lon1 Longitude of point 1.
     * @param float $lat2 Latitude of point 2.
     * @param float $lon2 Longitude of point 2.
     *
     * @return float Distance in meters.
     */
    private function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS * $c;
    }//end calculateDistance()
}//end class
