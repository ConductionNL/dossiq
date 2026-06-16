<?php

/**
 * Procest Evidence Metadata Service.
 *
 * Pure-logic helper that validates and enriches field-evidence metadata on the
 * server side: it builds the EXIF UserComment context block embedded in photos,
 * classifies GPS accuracy (good / poor / sensorless fallback to case address),
 * enforces the client-side photo compression target, and derives the AVG/GDPR
 * sensitivity default. The actual blob compression and EXIF writing happen in
 * the PWA client; this service is the server-side contract and validator.
 *
 * Contains no I/O; persistence of fieldEvidence objects is the caller's job.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-8
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Validates and enriches offline field-evidence metadata.
 *
 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-8
 *
 * @psalm-suppress UnusedClass
 */
class EvidenceMetadataService
{
    /**
     * GPS accuracy threshold (metres) above which a warning is raised.
     */
    public const GPS_ACCURACY_WARN_THRESHOLD = 50.0;

    /**
     * GPS quality: accurate fix within the threshold.
     */
    public const GPS_QUALITY_GOOD = 'good';

    /**
     * GPS quality: a fix worse than the warning threshold.
     */
    public const GPS_QUALITY_POOR = 'poor';

    /**
     * GPS quality: no sensor fix; fell back to the case address.
     */
    public const GPS_QUALITY_SENSORLESS = 'sensorless';

    /**
     * Maximum compressed photo size in bytes (2 MB).
     */
    public const MAX_PHOTO_BYTES = (2 * 1024 * 1024);

    /**
     * Maximum voice-memo duration in seconds (5 minutes).
     */
    public const MAX_VOICE_MEMO_SECONDS = 300;

    /**
     * Classify a GPS reading and resolve the effective location.
     *
     * When no sensor reading is available, falls back to the supplied case
     * address coordinates and flags the result as sensorless. A reading worse
     * than the warning threshold is flagged poor but still used.
     *
     * @param array{lat?: float, lon?: float, accuracy?: float}|null $reading     The sensor reading, or null.
     * @param array{lat?: float, lon?: float}|null                   $caseAddress Fallback case-address coordinates.
     *
     * @return array{
     *     quality: string,
     *     warning: string|null,
     *     location: array{lat: float|null, lon: float|null, accuracy: float|null, source: string}
     * }
     *
     * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-7
     */
    public function classifyGps(?array $reading, ?array $caseAddress=null): array
    {
        // No sensor reading at all: fall back to the case address silently.
        if ($reading === null
            || isset($reading['lat']) === false
            || isset($reading['lon']) === false
        ) {
            return [
                'quality'  => self::GPS_QUALITY_SENSORLESS,
                'warning'  => null,
                'location' => [
                    'lat'      => ($caseAddress['lat'] ?? null),
                    'lon'      => ($caseAddress['lon'] ?? null),
                    'accuracy' => null,
                    'source'   => self::GPS_QUALITY_SENSORLESS,
                ],
            ];
        }

        $accuracy = ((float) ($reading['accuracy'] ?? 0.0));
        $quality  = self::GPS_QUALITY_GOOD;
        $warning  = null;

        if ($accuracy > self::GPS_ACCURACY_WARN_THRESHOLD) {
            $quality = self::GPS_QUALITY_POOR;
            $warning = sprintf(
                'Locatie onnauwkeurig (±%dm) — wacht op beter signaal of voeg handmatig adres toe',
                (int) round($accuracy)
            );
        }

        return [
            'quality'  => $quality,
            'warning'  => $warning,
            'location' => [
                'lat'      => ((float) $reading['lat']),
                'lon'      => ((float) $reading['lon']),
                'accuracy' => $accuracy,
                'source'   => 'sensor',
            ],
        ];
    }//end classifyGps()

    /**
     * Build the EXIF UserComment context block embedded in captured photos.
     *
     * The block links the photo back to the inspector, case, device and
     * checklist template for chain-of-evidence purposes. BSN or other special
     * category identifiers are never included here.
     *
     * @param array<string, string> $context    The contextual references (inspectorRef, caseRef, deviceId, checklistTemplateRef).
     * @param string|null           $capturedAt ISO-8601 capture timestamp (defaults to now).
     *
     * @return array<string, string> The EXIF context map.
     *
     * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-8
     */
    public function buildExifContext(array $context, ?string $capturedAt=null): array
    {
        return [
            'inspectorId'          => ((string) ($context['inspectorRef'] ?? '')),
            'caseRef'              => ((string) ($context['caseRef'] ?? '')),
            'deviceId'             => ((string) ($context['deviceId'] ?? '')),
            'checklistTemplateRef' => ((string) ($context['checklistTemplateRef'] ?? '')),
            'capturedAt'           => ($capturedAt ?? (new DateTimeImmutable())->format(DateTimeInterface::ATOM)),
        ];
    }//end buildExifContext()

    /**
     * Validate that a compressed photo meets the size target.
     *
     * @param int $byteSize The compressed photo size in bytes.
     *
     * @return bool True when within the 2 MB target.
     *
     * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-8
     */
    public function isPhotoWithinTarget(int $byteSize): bool
    {
        return $byteSize > 0 && $byteSize <= self::MAX_PHOTO_BYTES;
    }//end isPhotoWithinTarget()

    /**
     * Validate that a voice memo does not exceed the maximum duration.
     *
     * @param int $durationSeconds The recorded duration in seconds.
     *
     * @return bool True when within the 5-minute limit.
     *
     * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-9
     */
    public function isVoiceMemoWithinLimit(int $durationSeconds): bool
    {
        return $durationSeconds > 0 && $durationSeconds <= self::MAX_VOICE_MEMO_SECONDS;
    }//end isVoiceMemoWithinLimit()

    /**
     * Build a normalized fieldEvidence payload for an offline capture.
     *
     * Applies sane defaults: a voice_memo starts with transcriptionStatus
     * "pending"; all other types are "not_applicable". The sensitivity level
     * defaults to "internal" unless explicitly overridden.
     *
     * @param string                                                 $inspectionRef The owning inspection.
     * @param string                                                 $type          One of photo/voice_memo/document/sketch.
     * @param array<string, mixed>                                   $extra         Additional fields (localBlobRef, tags, etc.).
     * @param array{lat?: float, lon?: float}|null                   $caseAddress   Fallback for sensorless GPS.
     * @param array{lat?: float, lon?: float, accuracy?: float}|null $gpsReading    The sensor reading.
     *
     * @return array<string, mixed> The fieldEvidence payload.
     *
     * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-8
     */
    public function buildEvidencePayload(
        string $inspectionRef,
        string $type,
        array $extra=[],
        ?array $caseAddress=null,
        ?array $gpsReading=null
    ): array {
        if ($type === 'photo' && isset($extra['byteSize']) === true
            && $this->isPhotoWithinTarget(byteSize: (int) $extra['byteSize']) === false
        ) {
            throw new InvalidArgumentException('Photo size exceeds 2 MB compression target');
        }

        if ($type === 'voice_memo' && isset($extra['durationSeconds']) === true
            && $this->isVoiceMemoWithinLimit(durationSeconds: (int) $extra['durationSeconds']) === false
        ) {
            throw new InvalidArgumentException('Voice memo duration exceeds 5-minute limit');
        }

        $gps = $this->classifyGps(reading: $gpsReading, caseAddress: $caseAddress);

        $transcriptionStatus = 'not_applicable';
        if ($type === 'voice_memo') {
            $transcriptionStatus = 'pending';
        }

        $payload = [
            'inspectionRef'       => $inspectionRef,
            'type'                => $type,
            'localBlobRef'        => ((string) ($extra['localBlobRef'] ?? '')),
            'cloudUrl'            => null,
            'gpsLocation'         => [
                'lat'       => $gps['location']['lat'],
                'lon'       => $gps['location']['lon'],
                'accuracy'  => $gps['location']['accuracy'],
                'timestamp' => ($extra['capturedAt'] ?? (new DateTimeImmutable())->format(DateTimeInterface::ATOM)),
            ],
            'capturedAt'          => ($extra['capturedAt'] ?? (new DateTimeImmutable())->format(DateTimeInterface::ATOM)),
            'transcription'       => null,
            'transcriptionStatus' => $transcriptionStatus,
            'tags'                => ($extra['tags'] ?? []),
            'sensitivityLevel'    => ((string) ($extra['sensitivityLevel'] ?? 'internal')),
        ];

        return $payload;
    }//end buildEvidencePayload()
}//end class
