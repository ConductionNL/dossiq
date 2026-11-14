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
	 * @throws RuntimeException When consent for the recording is absent, or the minutes would change a stored denial.
	 *
	 * @spec openspec/specs/bezwaar-awb-audit-trail/spec.md
	 */
	public function guardRecordingConsent(string $sessionId, array $payload, array $current, string $register, string $schema): void {
		$session = ['id' => $sessionId, 'register' => $register, 'schema' => $schema];
		$this->guardStoredDenial(payload: $payload, current: $current, session: $session);

		if (self::hasAudio(payload: $payload) === false) {
			return;
		}

		$consent = self::consentOf(payload: $payload, current: $current);
		if ($consent === 'granted') {
			return;
		}

		$this->refuseAudioUpload(session: $session, consent: $consent);
	}//end guardRecordingConsent()

	/**
	 * Whether the minutes carry an audio recording.
	 *
	 * @param array<string, mixed> $payload Minutes payload.
	 *
	 * @return bool True when an audio recording is uploaded.
	 *
	 * @spec openspec/specs/bezwaar-awb-audit-trail/spec.md
	 */
	private static function hasAudio(array $payload): bool {
		return isset($payload['audioRecording']) === true
			&& (string)$payload['audioRecording'] !== '';
	}//end hasAudio()

	/**
	 * The recording consent the minutes carry, else the stored one, else `not_requested`.
	 *
	 * @param array<string, mixed> $payload Minutes payload.
	 * @param array<string, mixed> $current Current hearingSession record.
	 *
	 * @return string The consent.
	 *
	 * @spec openspec/specs/bezwaar-awb-audit-trail/spec.md
	 */
	private static function consentOf(array $payload, array $current): string {
		return (string)(
			$payload['recordingConsent'] ?? ($current['recordingConsent'] ?? 'not_requested')
		);
	}//end consentOf()

	/**
	 * Record a refused audio upload on the session's trail, and refuse it.
	 *
	 * @param array<string, string> $session The session's `id`, `register` and `schema`.
	 * @param string                $consent The consent the upload lacks.
	 *
	 * @return void
	 *
	 * @throws RuntimeException Always: consent for the recording is absent.
	 *
	 * @spec openspec/specs/bezwaar-awb-audit-trail/spec.md
	 */
	private function refuseAudioUpload(array $session, string $consent): void {
		$this->auditTrail->recordRefusal(
			register: $session['register'],
			schema: $session['schema'],
			objectUuid: $session['id'],
			event: 'audio-upload-denied',
			payload: ['consent' => $consent],
			tag: BezwaarAuditTrail::TAG_RECORDING_CONSENT,
		);

		throw new RuntimeException(
			'Bezwaarmaker heeft geen toestemming gegeven voor audio-opname'
		);
	}//end refuseAudioUpload()

	/**
	 * Refuse minutes that would change a stored recording consent denial.
	 *
	 * The refusal is recorded on the session's OpenRegister trail.
	 *
	 * @param array<string, mixed>  $payload Minutes payload.
	 * @param array<string, mixed>  $current Current hearingSession record.
	 * @param array<string, string> $session The session's `id`, `register` and `schema`.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the payload carries another consent than a stored denial.
	 */
	private function guardStoredDenial(array $payload, array $current, array $session): void {
		if (($current['recordingConsent'] ?? null) !== 'denied'
			|| array_key_exists('recordingConsent', $payload) === false
			|| (string)$payload['recordingConsent'] === 'denied'
		) {
			return;
		}

		$this->auditTrail->recordRefusal(
			register: $session['register'],
			schema: $session['schema'],
			objectUuid: $session['id'],
			event: 'recording-consent-change-refused',
			payload: ['consent' => (string)$payload['recordingConsent']],
			tag: BezwaarAuditTrail::TAG_RECORDING_CONSENT,
		);

		throw new RuntimeException(
			'De geweigerde toestemming voor audio-opname kan niet met het verslag worden gewijzigd'
		);
	}//end guardStoredDenial()

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
		$update = [
			'minutesSummary' => $this->nullWhenEmpty(value: $summary),
			'minutesDocument' => $this->nullWhenEmpty(value: $document),
			'status' => 'executed',
		];

		return array_merge($update, $this->recordingFields(payload: $payload));
	}//end buildMinutesUpdate()

	/**
	 * The value, or null when it is empty.
	 *
	 * @param string $value The resolved value.
	 *
	 * @return string|null
	 */
	private function nullWhenEmpty(string $value): string|null {
		$result = null;
		if ($value !== '') {
			$result = $value;
		}

		return $result;
	}//end nullWhenEmpty()

	/**
	 * The audio recording and consent fields a minutes payload sets.
	 *
	 * @param array<string, mixed> $payload Minutes payload.
	 *
	 * @return array<string, string>
	 */
	private function recordingFields(array $payload): array {
		$fields = [];
		if (isset($payload['audioRecording']) === true
			&& (string)$payload['audioRecording'] !== ''
		) {
			$fields['audioRecording'] = (string)$payload['audioRecording'];
		}

		if (isset($payload['recordingConsent']) === true) {
			$fields['recordingConsent'] = (string)$payload['recordingConsent'];
		}

		return $fields;
	}//end recordingFields()

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
	 * @spec openspec/specs/bezwaar-awb-audit-trail/spec.md
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

	/**
	 * The audit payload of each late correction; none inside the window.
	 * Validates every reason before anything is written.
	 *
	 * @param array<int, array<string, mixed>> $entries  The attendance entries.
	 * @param bool                             $isFrozen Whether the window has passed.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws RuntimeException When a late correction lacks a reason.
	 *
	 * @spec openspec/specs/bezwaar-hearing/spec.md
	 */
	public function lateCorrections(array $entries, bool $isFrozen): array {
		if ($isFrozen === false) {
			return [];
		}

		$corrections = [];
		foreach ($entries as $entry) {
			$corrections[] = $this->lateCorrectionPayload(entry: $entry);
		}

		return $corrections;
	}//end lateCorrections()

	/**
	 * The minutes summary and document, from the payload or the stored session.
	 *
	 * @param array<string, mixed> $payload The minutes payload.
	 * @param array<string, mixed> $current The stored session.
	 *
	 * @return array{0: string, 1: string}
	 *
	 * @throws RuntimeException When both are blank.
	 *
	 * @spec openspec/specs/bezwaar-hearing/spec.md
	 */
	public function minutesTexts(array $payload, array $current): array {
		$summary = (string)(
			$payload['minutesSummary'] ?? ($current['minutesSummary'] ?? '')
		);
		$document = (string)(
			$payload['minutesDocument'] ?? ($current['minutesDocument'] ?? '')
		);

		if (trim($summary) === '' && trim($document) === '') {
			throw new RuntimeException(
				'Verslag (art. 7:7) ontbreekt — vul minutesSummary of upload minutesDocument'
			);
		}

		return [$summary, $document];
	}//end minutesTexts()
}//end class
