/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * KCC-werkplek sentiment trigger-word (de)serialisation helpers.
 *
 * The sentiment trigger words are stored as a JSON-encoded string in the
 * Dossiq settings config (key `sentiment_trigger_words`) but edited as a
 * newline-separated textarea in the admin form. These pure helpers convert
 * between the two representations losslessly and defensively.
 *
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/specs.md
 */

/**
 * Parse a stored JSON trigger-word value into a textarea string (one per line).
 *
 * @param {string} raw The stored value (expected to be a JSON array string).
 * @return {string} Newline-separated trigger words, or '' when not parseable.
 */
export function triggerWordsToText(raw) {
	try {
		const parsed = JSON.parse(raw)
		if (Array.isArray(parsed)) {
			return parsed
				.map((w) => String(w).trim())
				.filter((w) => w.length > 0)
				.join('\n')
		}
	} catch (error) {
		// Fall through to empty string when the stored value is not JSON.
	}
	return ''
}

/**
 * Serialise textarea content into a JSON trigger-word array string.
 *
 * Trims each line, drops blank lines, and de-duplicates while preserving
 * first-seen order.
 *
 * @param {string} text Newline-separated trigger words.
 * @return {string} JSON-encoded array of trigger words.
 */
export function textToTriggerWords(text) {
	const seen = new Set()
	const words = String(text || '')
		.split('\n')
		.map((w) => w.trim())
		.filter((w) => {
			if (w.length === 0 || seen.has(w)) {
				return false
			}
			seen.add(w)
			return true
		})
	return JSON.stringify(words)
}
