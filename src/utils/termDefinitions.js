/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/** How many term definitions the Integrations tab shows. */
export const NEWEST_TERM_DEFINITIONS = 3

/**
 * The newest term definitions: latest validity first, then highest version.
 *
 * @param {Array<object>} definitions The definitions.
 * @param {number} limit How many to keep.
 * @return {Array<object>} A new list of at most `limit` definitions.
 * @spec openspec/specs/admin-settings/spec.md
 */
export function newestTermDefinitions(definitions, limit = NEWEST_TERM_DEFINITIONS) {
	return [...definitions]
		.sort((a, b) => String(b.validFrom ?? '').localeCompare(String(a.validFrom ?? ''))
			|| Number(b.version ?? 1) - Number(a.version ?? 1))
		.slice(0, limit)
}
