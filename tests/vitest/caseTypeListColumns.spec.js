/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case list shows the columns of the type you picked.
 *
 * Three ways this fails silently, which is why it is worth a test:
 *
 *  - `x-index` is not a declared property of `caseType`. OpenRegister's magic
 *    mapper is a whitelist by omission, so the save answers 200 and stores
 *    nothing, and the case type reads on screen as one that simply has no
 *    columns of its own. The seeder's own comment records the same trap
 *    costing four child collections.
 *  - A scope column names a key the Cases page does not declare. The library
 *    DROPS it rather than rendering an empty column, so the header comes back
 *    one column short with nothing anywhere to say which one or why.
 *  - A `defaultSort` key that is not a column is sent as a sort key the server
 *    cannot order on, which answers an arbitrary order rather than an error.
 *
 * So everything here is checked against the page's own declared set and
 * against the register fragment, never against a list written twice.
 *
 * @spec openspec/changes/columns-follow-the-case-type/specs/case-management/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

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

const manifest = readJson('src', 'manifest.json')
const vthSeed = readJson('lib', 'Settings', 'vth_seed_data.json')
const fragment = readJson(
	'lib',
	'Settings',
	'register.d',
	'39-case-type-list-layout.json',
)

/** The three keys `resolveScopeLayout` reads off a scope, and no others. */
const SCOPE_LAYOUT_KEYS = ['columns', 'defaultSort', 'searchFields']

/**
 * The key of a column entry, in either shape the manifest accepts.
 *
 * @param {(string|object)} column A column entry.
 * @return {string} The column key.
 */
function columnKeyOf(column) {
	if (typeof column === 'string') {
		return column
	}
	return (column && column.key) || ''
}

/** The Cases page, which owns the set every scope selects from. */
const casesPage = (manifest.pages || []).find((p) => p.id === 'Cases')

/** The column keys that page declares. */
const pageColumnKeys = new Set(
	(casesPage.config.columns || []).map(columnKeyOf).filter(Boolean),
)

/** The seeded case types that declare a list layout, by slug. */
const layouts = (vthSeed.caseTypes || [])
	.filter((c) => c['x-index'])
	.map((c) => [c.slug, c['x-index']])

describe('a case type may carry its own list layout', () => {
	it('declares x-index on the caseType schema, or the seed is dropped in silence', () => {
		const props = fragment.components.schemas.caseType.properties
		expect(
			props['x-index'],
			'x-index is not declared on caseType, so the magic mapper drops it and the case type has no columns of its own',
		).toBeTruthy()
		expect(props['x-index'].type).toBe('object')
		expect(Object.keys(props['x-index'].properties).sort()).toEqual(
			[...SCOPE_LAYOUT_KEYS].sort(),
		)
	})

	it('seeds a layout on the case types whose readers ask different questions', () => {
		expect(layouts.map(([slug]) => slug)).toEqual([
			'omgevingsvergunning-bouwactiviteit',
			'toezichtzaak-bouw',
			'handhavingszaak',
		])
	})

	it('leaves the other seeded case types silent, so they inherit the page', () => {
		const silent = (vthSeed.caseTypes || [])
			.filter((c) => !c['x-index'])
			.map((c) => c.slug)
		expect(
			silent.length,
			'every seeded case type overrides the page',
		).toBeGreaterThan(0)
	})
})

describe('a column names a column the page carries', () => {
	it.each(layouts)('%s names only page columns', (slug, layout) => {
		for (const key of layout.columns || []) {
			expect(
				pageColumnKeys.has(key),
				`case type "${slug}" declares the column "${key}", which the Cases page does not carry, so the library drops it and the header comes back short with nothing to say why`,
			).toBe(true)
		}
	})

	it.each(layouts)('%s orders on a column the page carries', (slug, layout) => {
		for (const entry of layout.defaultSort || []) {
			expect(
				pageColumnKeys.has(entry.key),
				`case type "${slug}" orders on "${entry.key}", which is not one of the page's columns`,
			).toBe(true)
			expect(['asc', 'desc']).toContain(entry.order || 'asc')
		}
	})

	it.each(layouts)(
		'%s carries no key the library does not read',
		(slug, layout) => {
			for (const key of Object.keys(layout)) {
				expect(
					SCOPE_LAYOUT_KEYS,
					`case type "${slug}" carries "${key}", which resolveScopeLayout never reads`,
				).toContain(key)
			}
		},
	)

	it('names the three columns the case types added, on the page itself', () => {
		for (const key of ['besluitdatum', 'procedureType', 'riskLevel']) {
			expect(
				pageColumnKeys.has(key),
				`the Cases page dropped "${key}", so every case type naming it loses that column`,
			).toBe(true)
		}
	})
})

describe('the folder sidebar is the scope these layouts hang off', () => {
	it('narrows the Cases page by case type over the register', () => {
		const sidebar = casesPage.config.folderSidebar
		expect(sidebar.source).toBe('register')
		expect(sidebar.schema).toBe('caseType')
		expect(sidebar.filterField).toBe('caseType')
	})

	it('declares no manifest folder entries, which is why the layout rides the record', () => {
		// A `source: 'register'` sidebar builds its folders from rows. There is
		// no folder entry in this file to declare `columns` on, so the row's
		// own `x-index` is the only source, and that is a finding rather than
		// a shortfall: this change's tasks asked for the manifest.
		expect(casesPage.config.folderSidebar.folders).toBeUndefined()
	})
})
