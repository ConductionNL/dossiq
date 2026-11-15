/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Filtering the case list on a case type's own fields.
 *
 * 🔴 EVERY FAILURE HERE IS SILENT, WHICH IS WHY IT IS TESTED AT ALL. A filter
 * built against a guessed grammar is not refused: openregister reads what it
 * understands, and a key it does not understand narrows nothing, so the
 * handler gets the WHOLE REGISTER presented as the answer to a narrow
 * question. Two conditions merged into one block are worse in the opposite
 * direction: one `caseProperty` row cannot be two property definitions, so the
 * answer is an empty list and nothing on screen says why.
 *
 * The bar, its wire shape and its refusal notice are the library's
 * CnRelatedFieldFilters (tests/components/CnRelatedFieldFilters.spec.js and
 * tests/utils/relatedFieldFilters.spec.js). What dossiq owns, and what this
 * asserts, is the declaration that points the bar at the case type's fields,
 * the register fragment that makes a field filterable, and the whole-result
 * bulk act that must carry the filters.
 *
 * @spec openspec/specs/case-search-via-or-unified-search/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { readListFilters } from '../../src/utils/selectionScope.js'

const ROOT = path.resolve(__dirname, '../..')

/**
 * Read a JSON file from the repository.
 *
 * @param {...string} parts Path parts under the repository root.
 * @return {object} The parsed document.
 */
function readJson(...parts) {
	return JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
}

describe('a case type says which of its fields are worth filtering on', () => {
	it('declares filterable on propertyDefinition, defaulting to false', () => {
		const fragment = readJson(
			'lib',
			'Settings',
			'register.d',
			'42-filterable-case-fields.json',
		)
		const property =
			fragment.components.schemas.propertyDefinition.properties.filterable

		expect(property.type).toBe('boolean')
		expect(
			property.default,
			'a definition that declares nothing must not be offered, so the default is off',
		).toBe(false)
	})
})

describe('a whole-result act carries the field filters', () => {
	it('keeps _element alongside _search', () => {
		const filters = readListFilters({
			_page: '2',
			caseType: 'ct-1',
			'_element[properties][propertyDefinition]': 'pd-1',
			'_element[properties][value][gte]': '100000',
		})

		expect(filters['_element[properties][propertyDefinition]']).toBe('pd-1')
		expect(filters['_element[properties][value][gte]']).toBe('100000')
		expect(filters._page).toBeUndefined()
	})

	it('keeps _related alongside _search and drops only paging', () => {
		const filters = readListFilters({
			_page: '3',
			_limit: '25',
			_order: 'title',
			_search: 'dakkapel',
			caseType: 'ct-1',
			'_related[caseProperty][case][propertyDefinition]': 'pd-1',
			'_related[caseProperty][case][value][gte]': '100000',
		})

		expect(
			filters['_related[caseProperty][case][propertyDefinition]'],
			'the bulk job would receive every case in the register while the screen said 43',
		).toBe('pd-1')
		expect(filters['_related[caseProperty][case][value][gte]']).toBe('100000')
		expect(filters._search).toBe('dakkapel')
		expect(filters._page).toBeUndefined()
		expect(filters._limit).toBeUndefined()
		expect(filters._order).toBeUndefined()
	})
})

describe('the bar is placed where it can be reached', () => {
	const manifest = readJson('src', 'manifest.json')
	const registry = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')
	const cases = (manifest.pages || []).find((p) => p.id === 'Cases')

	it('reads the case\'s own answers in properties[], which the case schema holds (DQ-REINT 23 Oct)', () => {
		const register = readJson('lib', 'Settings', 'dossiq_register.json')
		const answers = register.components.schemas.case.properties.properties
		const { element, definitionKey, valueKey } = cases.config.relatedFieldFilters.related
		expect(answers.type).toBe('array')
		expect(Object.keys(answers.items.properties)).toEqual(expect.arrayContaining([definitionKey, valueKey]))
		expect(element).toBe('properties')
	})

	it('is declared on the Cases page, scoped to the case type the route names', () => {
		expect(cases.config.relatedFieldFilters).toEqual({
			definitions: { register: 'dossiq', schema: 'propertyDefinition', filter: { caseType: '$route.query.caseType' } },
			related: { element: 'properties', definitionKey: 'propertyDefinition', valueKey: 'value' },
		})
	})

	it('leaves the search refusal to the index page, which draws it itself', () => {
		expect(cases.slots).toBeUndefined()
	})

	it('no longer ships the dossiq copy of the bar', () => {
		expect(registry).not.toContain('CaseTypeFieldFilters')
		expect(fs.existsSync(path.join(ROOT, 'src', 'components', 'search', 'CaseTypeFieldFilters.vue'))).toBe(false)
	})

	it('hangs off a folder sidebar that narrows by case type', () => {
		expect(cases.config.folderSidebar.filterField).toBe('caseType')
	})
})
