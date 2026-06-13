/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pure helpers for the mobiel-inspectie-offline field workflow.
 *
 * GPS-accuracy classification, photo/voice-memo limit validation, checklist
 * required-field validation, and the user-facing status copy. These mirror the
 * server-side `EvidenceMetadataService` validators so the client refuses
 * invalid input before it is ever queued. DOM-free and unit-tested.
 *
 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-automatic-gps-geolocation-tagging-on-all-fieldwork
 */

/** Accuracy worse than this (metres) triggers the "poor signal" warning. */
export const GPS_POOR_ACCURACY_M = 50

/** Photo upload target after client-side compression (bytes). */
export const PHOTO_MAX_BYTES = 2 * 1024 * 1024

/** Voice-memo recording cap (seconds). */
export const VOICE_MEMO_MAX_SECONDS = 5 * 60

/**
 * Classify a GPS fix into good / poor / sensorless and supply warning copy.
 *
 * @param {object|null} fix          The Geolocation reading.
 * @param {number}      [fix.accuracy] Accuracy in metres.
 * @param {boolean}     [available] Whether the sensor produced any fix.
 *
 * @return {{ quality: ('good'|'poor'|'sensorless'), source: ('sensor'|'sensorless'), warning: (string|null) }}
 *
 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-automatic-gps-geolocation-tagging-on-all-fieldwork
 */
export function classifyGps(fix, available = true) {
	if (available === false || fix === null || fix === undefined) {
		return { quality: 'sensorless', source: 'sensorless', warning: null }
	}

	const accuracy = Number(fix.accuracy ?? Number.POSITIVE_INFINITY)
	if (accuracy > GPS_POOR_ACCURACY_M) {
		const rounded = Number.isFinite(accuracy) ? Math.round(accuracy) : '?'
		return {
			quality: 'poor',
			source: 'sensor',
			warning: t('procest', 'Location imprecise (±{m}m) — wait for a better signal or add the address manually', { m: rounded }),
		}
	}

	return { quality: 'good', source: 'sensor', warning: null }
}

/**
 * Validate that a compressed photo is within the upload target.
 *
 * @param {number} byteSize The compressed byte size.
 *
 * @return {boolean} True when ≤ the 2MB target.
 *
 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-photo-capture-with-client-side-compression-and-exif-metadata
 */
export function isPhotoWithinTarget(byteSize) {
	return Number(byteSize) <= PHOTO_MAX_BYTES
}

/**
 * Validate that a voice memo is within the 5-minute limit.
 *
 * @param {number} durationSeconds The recording duration.
 *
 * @return {boolean} True when ≤ 5 minutes.
 *
 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-voice-memo-recording-and-transcription-queueing
 */
export function isVoiceMemoWithinLimit(durationSeconds) {
	return Number(durationSeconds) <= VOICE_MEMO_MAX_SECONDS && Number(durationSeconds) > 0
}

/**
 * Validate a set of checklist answers against the template's required items.
 *
 * A `required` item must have a non-empty answer; a `photo_required` item must
 * additionally have at least one evidence reference. Returns the per-question
 * blocking errors so the UI can prevent save (and the engine never queues an
 * invalid result).
 *
 * @param {object} template          The checklist template.
 * @param {Array}  template.items    Template items (questionId, type, required).
 * @param {object} answersByQuestion Map of questionId → { answer, evidenceRefs }.
 *
 * @return {{ valid: boolean, errors: Array<{ questionId: string, message: string }> }}
 *
 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-checklist-completion-and-storage
 */
export function validateChecklistAnswers(template, answersByQuestion) {
	const items = Array.isArray(template?.items) ? template.items : []
	const answers = answersByQuestion ?? {}
	const errors = []

	for (const item of items) {
		if (item?.required !== true) {
			continue
		}
		const entry = answers[item.questionId] ?? {}
		const answer = entry.answer
		const evidenceRefs = Array.isArray(entry.evidenceRefs) ? entry.evidenceRefs : []

		if (item.type === 'photo_required') {
			if (evidenceRefs.length === 0) {
				errors.push({ questionId: item.questionId, message: t('procest', 'Photo required for this question') })
			}
			continue
		}

		if (answer === undefined || answer === null || String(answer).trim() === '') {
			errors.push({ questionId: item.questionId, message: t('procest', 'This question is required') })
		}
	}

	return { valid: errors.length === 0, errors }
}

/**
 * Count completed items in a checklist for the N/M progress indicator.
 *
 * @param {object} template          The checklist template.
 * @param {object} answersByQuestion Map of questionId → { answer, evidenceRefs }.
 *
 * @return {{ done: number, total: number }} Completed and total item counts.
 *
 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-checklist-completion-and-storage
 */
export function checklistProgress(template, answersByQuestion) {
	const items = Array.isArray(template?.items) ? template.items : []
	const answers = answersByQuestion ?? {}
	let done = 0

	for (const item of items) {
		const entry = answers[item.questionId] ?? {}
		const hasAnswer = entry.answer !== undefined && entry.answer !== null && String(entry.answer).trim() !== ''
		const hasEvidence = Array.isArray(entry.evidenceRefs) && entry.evidenceRefs.length > 0
		if (item.type === 'photo_required' ? hasEvidence : hasAnswer) {
			done += 1
		}
	}

	return { done, total: items.length }
}

/**
 * Human-readable sync-status copy for the green/amber/red indicator.
 *
 * @param {number} pendingCount The number of pending operations.
 * @param {boolean} online      Whether the device is online.
 *
 * @return {{ tone: ('success'|'warning'|'error'), text: string }} Indicator state.
 *
 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-checklist-completion-and-storage
 */
export function syncIndicator(pendingCount, online) {
	if (online === false) {
		return { tone: 'error', text: t('procest', 'Offline — {n} changes waiting for sync', { n: pendingCount }) }
	}
	if (pendingCount > 0) {
		return { tone: 'warning', text: t('procest', '{n} changes waiting for sync', { n: pendingCount }) }
	}
	return { tone: 'success', text: t('procest', 'All changes synced') }
}
