<?php

/**
 * Dossiq Bezwaar Hearing Minutes Recorder.
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
 * @package  OCA\Dossiq\Service\Bezwaar
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/bezwaar-hearing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Bezwaar;

use RuntimeException;

/**
 * Assembles the verslag patch and guards recording consent + late corrections.
 *
 * @spec openspec/specs/bezwaar-hearing/spec.md
 */
class HearingMinutesRecorder {

	/**
	 * Constructor.
	 *
	 * @param BezwaarAuditTrail $auditTrail Writes the consent refusal onto the session's OpenRegister trail.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly BezwaarAuditTrail $auditTrail,
	) {
	}//end __construct()

	/**
	 * Guard the audio-recording upload behind explicit consent (AVG art. 6).
	 *
	 * A denial is recorded on the session's OpenRegister trail and the upload
	 * is refused, whether or not that entry could be written. When it could
	 * not, BezwaarAuditTrail logs the full entry at error level.
	 *
	 * @param string $sessionId UUID of the hearingSession.
	 * @param array<string, mixed> $payload Minutes payload.
	 * @param array<string, mixed> $current Current hearingSession record.
	 * @param string $register The register id.
	 * @param string $schema The hearingSession schema id.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When consent for the recording is absent.
	 *
	 * @spec openspec/changes/bezwaar-audit-onto-openregister-trail/specs/bezwaar-awb-audit-trail/spec.md
	 */
	public function guardRecordingConsent(
		string $sessionId,
		array $payload,
		array $current,
		string $register,
		string $schema,
	): void {
		$hasAudio = isset($payload['audioRecording']) === true
			&& (string)$payload['audioRecording'] !== '';
		if ($hasAudio === false) {
			return;
		}

		$consent = (string)(
			$payload['recordingConsent'] ?? ($current['recordingConsent'] ?? 'not_requested')
		);
		if ($consent === 'granted') {
			return;
		}

		$this->auditTrail->recordRefusal(
			register: $register,
			schema: $schema,
			objectUuid: $sessionId,
			event: 'audio-upload-denied',
			payload: ['consent' => $consent],
			tag: BezwaarAuditTrail::TAG_RECORDING_CONSENT,
		);

		throw new RuntimeException(
			'Bezwaarmaker heeft geen toestemming gegeven voor audio-opname'
		);
	}//end guardRecordingConsent()

	/**
	 * Build the hearingSession update payload for a minutes submission.
	 *
	 * @param array<string, mixed> $payload Minutes payload.
	 * @param string $summary Resolved minutes summary.
	 * @param string $document Resolved minutes document id.
	 *
	 * @return array<string, mixed> The patch to persist.
	 *
	 * @spec openspec/specs/bezwaar-hearing/spec.md
	 */
	public function buildMinutesUpdate(array $payload, string $summary, string $document): array {
		$minutesSummary = null;
		if ($summary !== '') {
			$minutesSummary = $summary;
		}

		$minutesDocument = null;
		if ($document !== '') {
			$minutesDocument = $document;
		}

		$update = [
			'minutesSummary' => $minutesSummary,
			'minutesDocument' => $minutesDocument,
			'status' => 'executed',
		];

		if (isset($payload['audioRecording']) === true
			&& (string)$payload['audioRecording'] !== ''
		) {
			$update['audioRecording'] = (string)$payload['audioRecording'];
		}

		if (isset($payload['recordingConsent']) === true) {
			$update['recordingConsent'] = (string)$payload['recordingConsent'];
		}

		return $update;
	}//end buildMinutesUpdate()

	/**
	 * The payload of an awb-art-7:7 entry for an attendance correction made
	 * after the grace window closed.
	 *
	 * HearingService records it on the session's trail before the attendance
	 * changes. Nothing here writes, and nothing returns a trail to save.
	 *
	 * @param mixed $entry The attendance entry.
	 *
	 * @return array<string, mixed> The entry's payload: invitee, presence and reason.
	 *
	 * @throws RuntimeException When the late correction lacks a reason.
	 *
	 * @spec openspec/changes/bezwaar-audit-onto-openregister-trail/specs/bezwaar-awb-audit-trail/spec.md
	 */
	public function lateCorrectionPayload(mixed $entry): array {
		$hasReason = isset($entry['correctionReason'])
			&& trim((string)$entry['correctionReason']) !== '';
		if ($hasReason === false) {
			throw new RuntimeException(
				'Aanwezigheidscorrectie vereist toelichting in audit trail'
			);
		}

		return [
			'invitee' => (string)($entry['invitee'] ?? ''),
			'present' => (bool)($entry['present'] ?? false),
			'correctionReason' => (string)$entry['correctionReason'],
		];
	}//end lateCorrectionPayload()
}//end class
