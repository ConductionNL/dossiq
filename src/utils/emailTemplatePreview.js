/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pure helpers for the email-template editor preview: detect unresolved
 * {{placeholder}} variables and render an HTML preview that highlights known
 * variables green and unknown (unresolved) variables red. No DOM/Vue deps so
 * the logic is unit-testable under the node vitest environment.
 *
 * @spec openspec/specs/case-email-integration/spec.md
 */

const PLACEHOLDER_RE = /{{\s*([a-zA-Z][a-zA-Z0-9_]*)\s*}}/g

/**
 * Collect placeholder names in `text` that are NOT present in `knownNames`.
 *
 * @param {string}   text       Source text (subject and/or body).
 * @param {string[]} knownNames Catalog of supported variable names.
 * @return {string[]} Unique unresolved variable names in first-seen order.
 */
export function collectUnresolved(text, knownNames) {
	const known = new Set(knownNames || [])
	const found = []
	const re = new RegExp(PLACEHOLDER_RE.source, 'g')
	let m
	while ((m = re.exec(String(text || ''))) !== null) {
		if (!known.has(m[1]) && !found.includes(m[1])) {
			found.push(m[1])
		}
	}
	return found
}

/**
 * Render an HTML preview of `body`, wrapping each placeholder in a span:
 * `etpl-var-ok` when known, `etpl-var-bad` when unresolved. Input is escaped
 * first so raw HTML cannot break out; newlines become `<br>`.
 *
 * @param {string}   body       Template body.
 * @param {string[]} knownNames Catalog of supported variable names.
 * @return {string} Safe HTML string.
 */
export function renderPreview(body, knownNames) {
	const known = new Set(knownNames || [])
	const escape = (s) =>
		String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
	const re = new RegExp(PLACEHOLDER_RE.source, 'g')
	const rendered = escape(body || '').replace(re, (full, name) => {
		const cls = known.has(name) ? 'etpl-var-ok' : 'etpl-var-bad'
		return `<span class="${cls}">${escape(full)}</span>`
	})
	return rendered.replace(/\n/g, '<br>')
}
