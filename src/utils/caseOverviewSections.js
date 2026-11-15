// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * One answered property of the case, by the name of its definition. The case
 * stores them as a list of `{ name, value }` entries.
 *
 * @param {object} caseObject The case.
 * @param {string} name The property's name.
 *
 * @return {*} The stored value, or undefined.
 *
 * @spec openspec/specs/case-page/spec.md#requirement-a-case-type-declares-its-overview-cards
 */
function propertyOf(caseObject, name) {
	const stored = caseObject?.properties
	if (Array.isArray(stored)) {
		return stored.find((entry) => entry?.name === name)?.value
	}

	return stored?.[name]
}

/**
 * The value of one row: its `field`, read from the case's properties and then
 * from the case, else its fixed `value`.
 *
 * @param {{field?: string, value?: string}} row The declared row.
 * @param {object} caseObject The case.
 *
 * @return {string} The value, or a dash.
 *
 * @spec openspec/specs/case-page/spec.md#requirement-a-case-type-declares-its-overview-cards
 */
function rowValue(row, caseObject) {
	const read = row.field ? (propertyOf(caseObject, row.field) ?? caseObject[row.field]) : row.value
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
 * @spec openspec/specs/case-page/spec.md#requirement-a-case-type-declares-its-overview-cards
 */
export function resolveOverviewSections(declared, caseObject) {
	if (!Array.isArray(declared) || !caseObject) {
		return []
	}

	return declared
		.filter((section) => section?.id && section.title)
		.map((section) => ({
			...section,
			pill: section.pillField ? (propertyOf(caseObject, section.pillField) ?? '') : '',
			rows: (section.rows || []).map((row) => ({ label: row.label, value: rowValue(row, caseObject) })),
		}))
}
