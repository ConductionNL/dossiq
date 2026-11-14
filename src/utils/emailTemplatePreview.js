/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pure helper for the email-template editor: detect unresolved
 * {{placeholder}} variables in the subject (the body editor marks its own).
 * No DOM/Vue deps so the logic is unit-testable under the node vitest
 * environment.
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
