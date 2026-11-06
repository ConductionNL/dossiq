// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The value of one row: its `field`, read from the case's properties and then
 * from the case, else its fixed `value`.
 *
 * @param {{field?: string, value?: string}} row The declared row.
 * @param {object} caseObject The case.
 *
 * @return {string} The value, or a dash.
 *
 * @spec openspec/changes/overview-sections-per-case-type/specs/case-page/spec.md#requirement-a-case-type-declares-its-overview-cards
 */
function rowValue(row, caseObject) {
	const read = row.field ? (caseObject.properties?.[row.field] ?? caseObject[row.field]) : row.value
	const scalar = typeof read === 'string' || typeof read === 'number'

	return scalar && read !== '' ? String(read) : '—'
}

/**
 * The declared sections with their rows filled from the case.
 *
 * @param {Array<object>} declared The case type's `overviewSections`.
 * @param {object|null} caseObject The case.
 *
 * @return {Array<object>} The sections to draw.
 *
 * @spec openspec/changes/overview-sections-per-case-type/specs/case-page/spec.md#requirement-a-case-type-declares-its-overview-cards
 */
export function resolveOverviewSections(declared, caseObject) {
	if (!Array.isArray(declared) || !caseObject) {
		return []
	}

	return declared
		.filter((section) => section?.id && section.title)
		.map((section) => ({
			...section,
			pill: section.pillField ? (caseObject.properties?.[section.pillField] ?? '') : '',
			rows: (section.rows || []).map((row) => ({ label: row.label, value: rowValue(row, caseObject) })),
		}))
}
