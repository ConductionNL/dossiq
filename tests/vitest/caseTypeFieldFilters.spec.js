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
 * So the assertions are about the exact wire shape, measured against
 * openregister `parity/round2`'s `RelatedRowFilterParser`, and about the two
 * places a filter can be dropped: the bar clearing with its case type, and the
 * whole-result bulk act.
 *
 * @spec openspec/changes/case-type-fields-filter-the-case-list/specs/case-search-via-or-unified-search/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import {
	buildRelatedFilters,
	CONTROL_DATE_RANGE,
	CONTROL_RANGE,
	CONTROL_SELECT,
	CONTROL_TEXT,
	controlFor,
	definitionId,
	filterableDefinitions,
	isFilled,
	readRelatedRefusal,
	relatedKeysIn,
} from '../../src/utils/caseTypeFieldFilters.js'
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

/** A case type with three definitions, one of which is filterable. */
const DEFINITIONS = [
	{
		'@self': { uuid: 'pd-1' },
		name: 'bouwkosten',
		propertyType: 'number',
		filterable: true,
	},
	{ '@self': { uuid: 'pd-2' }, name: 'oppervlakte', propertyType: 'number' },
	{
		'@self': { uuid: 'pd-3' },
		name: 'aantalBouwlagen',
		propertyType: 'integer',
		filterable: false,
	},
]

describe('a case type says which of its fields are worth filtering on', () => {
	it('offers only the definition that declares it', () => {
		const offered = filterableDefinitions(DEFINITIONS)

		expect(offered).toHaveLength(1)
		expect(offered[0].name).toBe('bouwkosten')
	})

	it('offers nothing for a case type with no definitions at all', () => {
		expect(filterableDefinitions(undefined)).toEqual([])
		expect(filterableDefinitions([])).toEqual([])
	})

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

describe('the control follows the definition, not the page', () => {
	it('asks for a number as a range', () => {
		expect(controlFor({ propertyType: 'number' })).toBe(CONTROL_RANGE)
		expect(controlFor({ propertyType: 'integer' })).toBe(CONTROL_RANGE)
	})

	it('asks for a date as a date range, in both spellings the register stores', () => {
		expect(controlFor({ propertyType: 'date' })).toBe(CONTROL_DATE_RANGE)
		expect(controlFor({ propertyType: 'string', format: 'date' })).toBe(
			CONTROL_DATE_RANGE,
		)
	})

	it('asks for an enumeration as a select', () => {
		expect(
			controlFor({ propertyType: 'string', enumValues: ['Noord', 'Zuid'] }),
		).toBe(CONTROL_SELECT)
	})

	it('asks for everything else as text', () => {
		expect(controlFor({ propertyType: 'string' })).toBe(CONTROL_TEXT)
		expect(controlFor({})).toBe(CONTROL_TEXT)
		expect(controlFor({ propertyType: 'number', enumValues: [] })).toBe(
			CONTROL_RANGE,
		)
	})
})

describe('the bar compiles to one block per field', () => {
	it('writes a single filled field as one unnumbered block', () => {
		const query = buildRelatedFilters([
			{ definitionId: 'pd-1', value: { gte: '100000' } },
		])

		expect(query).toEqual({
			'_related[caseProperty][case][propertyDefinition]': 'pd-1',
			'_related[caseProperty][case][value][gte]': '100000',
		})
	})

	it('writes two filled fields as TWO numbered blocks, never one merged block', () => {
		const query = buildRelatedFilters([
			{ definitionId: 'pd-1', value: { gte: '100000' } },
			{ definitionId: 'pd-4', value: { eq: 'Noord' } },
		])

		// The number sits AFTER the foreign key. `RelatedRowFilterParser`
		// reads the schema, then the foreign key, and only then counts
		// numbered rows, so `_related[caseProperty][0][case][…]` names a
		// foreign key called `0` and is refused outright with "uses operator
		// 'propertyDefinition'".
		expect(query['_related[caseProperty][case][0][propertyDefinition]']).toBe(
			'pd-1',
		)
		expect(query['_related[caseProperty][case][0][value][gte]']).toBe('100000')
		expect(query['_related[caseProperty][case][1][propertyDefinition]']).toBe(
			'pd-4',
		)
		expect(query['_related[caseProperty][case][1][value]']).toBe('Noord')

		// One block asking for two definitions is a row that cannot exist, so
		// the handler would get an empty list and no reason for it.
		expect(
			Object.keys(query).filter((k) => k.endsWith('[propertyDefinition]')),
		).toHaveLength(2)
		expect(
			query['_related[caseProperty][case][propertyDefinition]'],
		).toBeUndefined()
	})

	it('writes nothing for a bar nobody has filled in', () => {
		expect(buildRelatedFilters([{ definitionId: 'pd-1', value: {} }])).toEqual(
			{},
		)
		expect(
			buildRelatedFilters([{ definitionId: 'pd-1', value: { eq: '' } }]),
		).toEqual({})
		expect(buildRelatedFilters([])).toEqual({})
	})

	it('drops an entry with no definition, which could only be sent as an empty id', () => {
		expect(
			buildRelatedFilters([{ definitionId: '', value: { eq: 'x' } }]),
		).toEqual({})
	})

	it('knows a filled field from an empty one', () => {
		expect(isFilled({ eq: 'Noord' })).toBe(true)
		expect(isFilled({ gte: '0' })).toBe(true)
		expect(isFilled({ in: ['a'] })).toBe(true)
		expect(isFilled({ in: [] })).toBe(false)
		expect(isFilled({})).toBe(false)
		expect(isFilled(undefined)).toBe(false)
	})

	it('reads a definition id in either shape the store answers in', () => {
		expect(definitionId({ '@self': { uuid: 'a' } })).toBe('a')
		expect(definitionId({ id: 'b' })).toBe('b')
		expect(definitionId({})).toBe('')
	})
})

describe('a refused filter is not an empty result', () => {
	it('names the field the refusal is about', () => {
		const refusal = readRelatedRefusal({
			error: {
				response: { data: { error: 'Malformed _related block for pd-1' } },
			},
			definitions: DEFINITIONS,
		})

		expect(refusal).toBeTruthy()
		expect(refusal.name).toBe('bouwkosten')
	})

	it('says nothing when the last fetch was not refused', () => {
		expect(
			readRelatedRefusal({ error: null, definitions: DEFINITIONS }),
		).toBeNull()
	})

	it('says nothing about a refusal that is not a _related one', () => {
		expect(
			readRelatedRefusal({
				error: {
					response: { data: { error: 'Unclosed quote in _search' } },
				},
				definitions: DEFINITIONS,
			}),
			'a refused search term must not be reported as a refused field filter',
		).toBeNull()
	})
})

describe('clearing the case type clears its field filters with it', () => {
	it('finds every _related key on a query', () => {
		const keys = relatedKeysIn({
			caseType: 'ct-1',
			_search: 'dakkapel',
			'_related[caseProperty][case][propertyDefinition]': 'pd-1',
			'_related[caseProperty][case][value][gte]': '100000',
		})

		expect(keys).toHaveLength(2)
		expect(keys.every((k) => k.startsWith('_related['))).toBe(true)
	})

	it('finds none on a query that carries none', () => {
		expect(relatedKeysIn({ caseType: 'ct-1' })).toEqual([])
		expect(relatedKeysIn(undefined)).toEqual([])
	})
})

describe('a whole-result act carries the field filters', () => {
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

	it('is declared on the Cases page in a slot that renders', () => {
		expect(cases.slots['after-search']).toBe('CaseTypeFieldFilters')
	})

	it('does not displace the search refusal, which holds the other slot', () => {
		expect(cases.slots['below-header']).toBe('CaseSearchRefusal')
	})

	it('names a registry key that exists, or the slot renders nothing at all', () => {
		expect(registry).toContain('CaseTypeFieldFilters: {')
		expect(registry).toContain(
			"import CaseTypeFieldFilters from './components/search/CaseTypeFieldFilters.vue'",
		)
	})

	it('hangs off a folder sidebar that narrows by case type', () => {
		expect(cases.config.folderSidebar.filterField).toBe('caseType')
	})
})
