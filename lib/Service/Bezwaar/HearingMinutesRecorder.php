<?php

/**
 * Procest Bezwaar Hearing Minutes Recorder.
 *
 * Everything the Awb art. 7:7 verslaglegging obligation demands of the
 * record a hoorzitting leaves behind. Split out of HearingService so that
 * service keeps only the persistence orchestration: assembling the
 * minutes patch that promotes a session to `uitgevoerd`, gating an
 * audio recording behind explicit AVG art. 6 consent (and logging the
 * denial to the trail before refusing), and demanding a documented
 * reason for an attendance correction made after the grace window
 * closed — all three are the same concern, "what the hearing produced
 * and who may amend it", and live here and nowhere else.
 *
 * @category Service
 * @package  OCA\Procest\Service\Bezwaar
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/bezwaar-hearing/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Bezwaar;

use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Assembles the verslag patch and guards recording consent + late corrections.
 *
 * @spec openspec/specs/bezwaar-hearing/spec.md
 */
class HearingMinutesRecorder
{
    /**
     * Constructor.
     *
     * @param BezwaarAuditTrail $auditTrail The shared append-only audit writer.
     * @param LoggerInterface   $logger     Logger.
     *
     * @return void
     */
    public function __construct(
        private readonly BezwaarAuditTrail $auditTrail,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Guard the audio-recording upload behind explicit consent, logging
     * a denial to the session audit trail when consent is absent.
     *
     * @param object               $objectService Resolved OR ObjectService.
     * @param string               $sessionId     UUID of the hearingSession.
     * @param array<string, mixed> $payload       Minutes payload.
     * @param array<string, mixed> $current       Current hearingSession record.
     * @param array<int, mixed>    $audit         Existing audit entries.
     * @param string               $register      The register id.
     * @param string               $schema        The hearingSession schema id.
     *
     * @return array<int, mixed> The (unchanged) audit entries.
     *
     * @throws RuntimeException When consent for the recording is absent.
     *
     * @spec openspec/specs/bezwaar-hearing/spec.md
     */
    public function guardRecordingConsent(
        object $objectService,
        string $sessionId,
        array $payload,
        array $current,
        array $audit,
        string $register,
        string $schema
    ): array {
        $hasAudio = isset($payload['audioRecording']) === true
            && (string) $payload['audioRecording'] !== '';
        if ($hasAudio === false) {
            return $audit;
        }

        $consent = (string) (
            $payload['recordingConsent'] ?? ($current['recordingConsent'] ?? 'not_requested')
        );
        if ($consent === 'granted') {
            return $audit;
        }

        $audit = $this->auditTrail->append(
            existing: $audit,
            event: 'audio-upload-denied',
            payload: ['consent' => $consent],
            tag: BezwaarAuditTrail::TAG_RECORDING_CONSENT,
        );

        try {
            $objectService->saveObject(
                object: ['auditTrail' => $audit],
                register: $register,
                schema: $schema,
                uuid: (string) $sessionId
            );
        } catch (Throwable $auditError) {
            $this->logger->error(
                'Procest hearing: failed to log audio-denial: '
                .$auditError->getMessage()
            );
        }

        throw new RuntimeException(
            'Bezwaarmaker heeft geen toestemming gegeven voor audio-opname'
        );
    }//end guardRecordingConsent()

    /**
     * Build the hearingSession update payload for a minutes submission.
     *
     * @param array<string, mixed> $payload  Minutes payload.
     * @param string               $summary  Resolved minutes summary.
     * @param string               $document Resolved minutes document id.
     *
     * @return array<string, mixed> The patch to persist.
     *
     * @spec openspec/specs/bezwaar-hearing/spec.md
     */
    public function buildMinutesUpdate(array $payload, string $summary, string $document): array
    {
        $minutesSummary = null;
        if ($summary !== '') {
            $minutesSummary = $summary;
        }

        $minutesDocument = null;
        if ($document !== '') {
            $minutesDocument = $document;
        }

        $update = [
            'minutesSummary'  => $minutesSummary,
            'minutesDocument' => $minutesDocument,
            'status'          => 'uitgevoerd',
        ];

        if (isset($payload['audioRecording']) === true
            && (string) $payload['audioRecording'] !== ''
        ) {
            $update['audioRecording'] = (string) $payload['audioRecording'];
        }

        if (isset($payload['recordingConsent']) === true) {
            $update['recordingConsent'] = (string) $payload['recordingConsent'];
        }

        return $update;
    }//end buildMinutesUpdate()

    /**
     * Append an awb-art-7:7 audit entry for an attendance correction
     * made after the grace window closed.
     *
     * @param array<int, array<string, mixed>> $audit Existing audit entries.
     * @param mixed                            $entry The attendance entry.
     *
     * @return array<int, array<string, mixed>> The trail with the correction recorded.
     *
     * @throws RuntimeException When the late correction lacks a reason.
     *
     * @spec openspec/specs/bezwaar-hearing/spec.md
     */
    public function appendLateCorrectionAudit(array $audit, mixed $entry): array
    {
        $hasReason = isset($entry['correctionReason'])
            && trim((string) $entry['correctionReason']) !== '';
        if ($hasReason === false) {
            throw new RuntimeException(
                'Aanwezigheidscorrectie vereist toelichting in audit trail'
            );
        }

        return $this->auditTrail->append(
            existing: $audit,
            event: 'attendance-late-correction',
            payload: [
                'invitee'          => (string) ($entry['invitee'] ?? ''),
                'present'          => (bool) ($entry['present'] ?? false),
                'correctionReason' => (string) $entry['correctionReason'],
            ],
            tag: BezwaarAuditTrail::TAG_VERSLAG,
        );
    }//end appendLateCorrectionAudit()
}//end class
