// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// Which screen a case of this type is registered on.
//
// 🔑 THE CASE TYPE NAMES IT, AND NOTHING ELSE DOES. Before the handling block
// there was no intake-screen key at all: every case type opened the same
// registration screen, and a gemeente that wanted a different one for
// vergunningen had nowhere to say so. The name resolves against the manifest's
// pages, and a case type naming a page the manifest does not have falls back to
// the standard screen rather than routing the handler nowhere.

/**
 * The manifest page id a case of this type is registered on.
 *
 * @param {object} caseType The case type row.
 * @param {Array} pages The manifest pages, each with an `id`.
 * @param {string} fallback The standard intake screen's page id.
 * @return {string} The page id to open.
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */
export function intakeScreenFor(caseType, pages = [], fallback = 'case-intake') {
	const declared =
		caseType && caseType.handling ? caseType.handling.intakeScreen : ''
	if (!declared) {
		return fallback
	}

	const known =
		Array.isArray(pages) && pages.some((page) => page && page.id === declared)

	return known ? declared : fallback
}

export default { intakeScreenFor }
