/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Webpack loader for the manifest JSON: drops the rationale keys (`_note` and
 * every key that ends in `Note`, such as `_formNote`) before the JSON goes into
 * the bundle. They are written for people reading the source; the page never
 * reads them, and they are about half of the manifest's bytes. Keys that only
 * start with an underscore (`_view`, `_limit`, `_extend`) are data and stay.
 */

const NOTE_KEY = /^_(?:[A-Za-z0-9]*Note|note)$/

/**
 * A copy of the value without rationale keys, at any depth.
 *
 * @param {unknown} value A parsed JSON value.
 * @return {unknown} The value without them.
 */
function withoutNotes(value) {
	if (Array.isArray(value)) {
		return value.map(withoutNotes)
	}
	if (value && typeof value === 'object') {
		const out = {}
		for (const [key, entry] of Object.entries(value)) {
			if (!NOTE_KEY.test(key)) {
				out[key] = withoutNotes(entry)
			}
		}
		return out
	}
	return value
}

module.exports = function stripNotesLoader(source) {
	return JSON.stringify(withoutNotes(JSON.parse(source)))
}

module.exports.withoutNotes = withoutNotes
module.exports.NOTE_KEY = NOTE_KEY
