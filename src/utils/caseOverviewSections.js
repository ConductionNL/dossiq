// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { getLanguage } from '@nextcloud/l10n'
import { formatDate } from './caseHelpers.js'

/** An ISO date or date-time as the case stores it ("2026-11-23", "2026-11-23T00:50:43+00:00"). */
const ISO_DATE = /^\d{4}-\d{2}-\d{2}(T[\d:.]+(Z|[+-]\d{2}:?\d{2})?)?$/

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
 * @return {string} The value, a date in words, or a dash.
 *
 * @spec openspec/specs/case-page/spec.md#requirement-a-case-type-declares-its-overview-cards
 */
function rowValue(row, caseObject) {
	const read = row.field ? (propertyOf(caseObject, row.field) ?? caseObject[row.field]) : row.value
	const scalar = typeof read === 'string' || typeof read === 'number'
	if (!scalar || read === '') {
		return '—'
	}

	return typeof read === 'string' && ISO_DATE.test(read) ? formatDate(read) : String(read)
}

/**
 * A label as the reader reads it. A case type may write a section's title,
 * intro, note and its rows' labels once (a string) or per language
 * (`{ "nl": "Gegevens", "en": "Details" }`); a map answers the reader's
 * language, then Dutch, then the first language it has.
 *
 * @param {string|object|undefined} value The declared label.
 * @param {string} [language] The reader's language; Nextcloud's when not given.
 *
 * @return {string} The label, or ''.
 *
 * @spec openspec/specs/case-page/spec.md#requirement-a-case-type-declares-its-overview-cards
 */
export function labelIn(value, language = getLanguage()) {
	if (typeof value === 'string') {
		return value
	}
	if (!value || typeof value !== 'object') {
		return ''
	}
	const base = String(language || '').split(/[-_]/)[0]
	const pick = value[language] ?? value[base] ?? value.nl ?? Object.values(value).find((entry) => typeof entry === 'string')
	return typeof pick === 'string' ? pick : ''
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
		.filter((section) => section?.id && labelIn(section.title))
		.map((section) => ({
			...section,
			title: labelIn(section.title),
			intro: labelIn(section.intro),
			note: labelIn(section.note),
			pill: section.pillField ? (propertyOf(caseObject, section.pillField) ?? '') : '',
			rows: (section.rows || []).map((row) => ({ label: labelIn(row.label), value: rowValue(row, caseObject) })),
		}))
}
