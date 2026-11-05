/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The attribute catalogue a case type picks its fields from (board
 * DqZaaktypeVelden, "Attribuut kiezen uit de catalogus").
 *
 * Every `propertyDefinition` in the register is an attribute; one attribute
 * name is one catalogue entry, however many case types carry it. Its folder is
 * the attribute's `category`, and its usage is the number of case types that
 * carry it. Picking an entry gives the case type its own copy of the
 * attribute, so a later change on this case type never moves another one.
 *
 * @spec openspec/specs/property-definition-management/spec.md
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const ATTRIBUTES_URL = '/apps/openregister/api/objects/dossiq/propertyDefinition'

/** The keys a copy takes over; the id, the case type and the order are the copy's own. */
const COPIED_KEYS = ['name', 'definition', 'description', 'category', 'propertyType', 'maxLength', 'enumValues', 'format', 'pattern', 'minimum', 'maximum', 'items', 'ref', 'conceptScheme', 'propertySource', 'defaultValue']

/**
 * @param {object} row An attribute row.
 * @return {string} Its id.
 */
function idOf(row) {
	return String(row?.id ?? row?.['@self']?.id ?? '')
}

/**
 * @param {object} row An attribute row.
 * @return {string} The case type it belongs to, '' for a shared one.
 */
function caseTypeOf(row) {
	return String(row?.caseType ?? '')
}

/**
 * The attribute rows grouped by name, each name with the rows that carry it.
 *
 * @param {Array<object>} rows Every attribute row.
 * @return {Map<string, Array<object>>} The rows per name, in first-seen order.
 * @spec openspec/specs/property-definition-management/spec.md
 */
export function attributesByName(rows) {
	const byName = new Map()
	for (const row of Array.isArray(rows) ? rows : []) {
		const name = String(row?.name ?? '').trim()
		if (name === '') {
			continue
		}
		byName.set(name, [...(byName.get(name) || []), row])
	}
	return byName
}

/**
 * One catalogue entry: the shared row of a name when there is one, else its
 * first row, with the number of case types that carry the name.
 *
 * @param {Array<object>} rows The rows of one name.
 * @return {{id: string, name: string, label: string, type: string, folderId: (string|undefined), usageCount: number, row: object}} The entry.
 * @spec openspec/specs/property-definition-management/spec.md
 */
export function catalogueEntry(rows) {
	const row = rows.find((entry) => caseTypeOf(entry) === '') || rows[0]
	const category = String(row.category ?? '').trim()
	return {
		id: idOf(row),
		name: String(row.name),
		label: String(row.definition || row.name),
		type: String(row.propertyType ?? ''),
		folderId: category === '' ? undefined : category,
		usageCount: new Set(rows.map(caseTypeOf).filter((id) => id !== '')).size,
		row,
	}
}

/**
 * The catalogue for one case type: its entries, its folders, and the entries
 * the case type already carries (shown, but not offered again).
 *
 * @param {Array<object>} rows Every attribute row.
 * @param {string} caseTypeId The case type that picks.
 * @return {{fields: Array<object>, folders: Array<{id: string, name: string}>, excludeIds: Array<string>}} The catalogue.
 * @spec openspec/specs/property-definition-management/spec.md
 */
export function buildCatalogue(rows, caseTypeId) {
	const fields = []
	const excludeIds = []
	for (const group of attributesByName(rows).values()) {
		const entry = catalogueEntry(group)
		fields.push(entry)
		if (group.some((row) => caseTypeOf(row) === caseTypeId)) {
			excludeIds.push(entry.id)
		}
	}
	const categories = [...new Set(fields.map((entry) => entry.folderId).filter(Boolean))].sort((a, b) => a.localeCompare(b))
	return { fields, folders: categories.map((name) => ({ id: name, name })), excludeIds }
}

/**
 * The case type's own copy of a catalogue attribute.
 *
 * @param {object} row The attribute row the entry stands for.
 * @param {string} caseTypeId The case type the copy belongs to.
 * @return {object} The new attribute.
 * @spec openspec/specs/property-definition-management/spec.md
 */
export function copyForCaseType(row, caseTypeId) {
	const copy = { caseType: caseTypeId, isRequired: false }
	for (const key of COPIED_KEYS) {
		if (row[key] !== undefined && row[key] !== null) {
			copy[key] = row[key]
		}
	}
	return copy
}

/**
 * Read every attribute in the register.
 *
 * @return {Promise<Array<object>>} The rows.
 * @spec openspec/specs/property-definition-management/spec.md
 */
export async function fetchAttributes() {
	const { data } = await axios.get(generateUrl(ATTRIBUTES_URL), { params: { _limit: 500 } })
	return Array.isArray(data?.results) ? data.results : []
}

/**
 * Give the case type its own copy of each picked attribute, one at a time,
 * so a refusal names the attribute it stopped at.
 *
 * @param {Array<object>} entries The picked catalogue entries.
 * @param {string} caseTypeId The case type.
 * @return {Promise<number>} How many were added.
 * @spec openspec/specs/property-definition-management/spec.md
 */
export async function addToCaseType(entries, caseTypeId) {
	let added = 0
	for (const entry of entries) {
		await axios.post(generateUrl(ATTRIBUTES_URL), copyForCaseType(entry.row, caseTypeId))
		added++
	}
	return added
}
