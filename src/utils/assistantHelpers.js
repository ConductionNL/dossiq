/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pure presentation helpers for the case-assistant chat panel
 * (case-assistant-via-hermiq). No network, no Vue — vitest-testable.
 */

/**
 * Maximum message length accepted by the composer, mirroring the backend cap
 * (CaseAssistantService::MAX_MESSAGE_LENGTH) so the user gets immediate
 * feedback instead of a 400 round-trip.
 *
 * @type {number}
 */
export const MAX_MESSAGE_LENGTH = 4000

/**
 * Build one chat transcript entry.
 *
 * @param {string} role 'user' or 'assistant'
 * @param {string} content The message text
 * @return {object} `{role, content, at}` transcript entry
 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
 */
export function makeTranscriptEntry(role, content) {
	return { role, content, at: new Date().toISOString() }
}

/**
 * Whether the composer may submit: non-blank, within the length cap, and no
 * request already in flight.
 *
 * @param {string} message The draft message
 * @param {boolean} loading Whether a request is in flight
 * @return {boolean} True when sending is allowed
 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
 */
export function canSend(message, loading) {
	if (loading === true) {
		return false
	}
	const trimmed = (message || '').trim()
	return trimmed.length > 0 && trimmed.length <= MAX_MESSAGE_LENGTH
}

/**
 * Map a failed converse() call to a user-facing message. Keys off the stable
 * `errorCode` / HTTP status — never off backend message text.
 *
 * @param {object|null} error The axios error (reads `error.response`)
 * @return {string} Translated user-facing message
 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
 */
export function assistantErrorMessage(error) {
	const status = error?.response?.status
	const errorCode = error?.response?.data?.errorCode

	if (errorCode === 'guardrail_blocked') {
		return t(
			'dossiq',
			"This message was blocked by your organisation's AI guardrail policy.",
		)
	}

	switch (status) {
		case 400:
			return t(
				'dossiq',
				'The message could not be sent. It may be empty or too long.',
			)
		case 401:
		case 403:
			return t(
				'dossiq',
				'You are not allowed to use the assistant on this case.',
			)
		case 404:
			return t('dossiq', 'This case could not be found.')
		default:
			return t(
				'dossiq',
				'The case assistant is currently unavailable. Please try again later.',
			)
	}
}
