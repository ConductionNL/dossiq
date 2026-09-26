// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The three parity declarations, checked against the library that has to read
 * them.
 *
 * 🔴 A DECLARATION THAT VALIDATES IS NOT A DECLARATION THAT ARRIVES. The
 * manifest schema sets `additionalProperties: true` on `pages[].config`, so a
 * misspelt key passes `check:schema` and every gate, reaches a component that
 * never looks for it, and renders a page that behaves exactly as it did
 * before. Two of these three shipped that way, and both were found by asking
 * the installed library rather than by reading the manifest:
 *
 *  - `userLayout: true` with no `appId` makes `CnDashboardPage` return early
 *    from both the load and the save, so nobody's arrangement is ever stored.
 *  - `_related[…]` written to the route query is dropped by
 *    `resolveQueryFilters`, which skips the whole underscore namespace, so the
 *    case list answered the UNFILTERED register under a filtered URL.
 *
 * So every assertion here goes through the real code in `node_modules`, not
 * through the vitest stub. The `@conduction/nextcloud-vue` alias in
 * `vitest.config.js` matches the bare package name only, so a deep import is
 * the published file itself.
 *
 * @spec openspec/changes/archive/2026-09-20-case-type-fields-filter-the-case-list/specs/case-search-via-or-unified-search/spec.md
 * @spec openspec/changes/archive/2026-09-20-columns-follow-the-case-type/specs/case-management/spec.md
 * @spec openspec/changes/archive/2026-09-20-a-dashboard-the-reader-arranges/specs/dashboard/spec.md
 */

import { userWidgetPresets } from '@conduction/nextcloud-vue/src/components/CnWidgetGrid/dashboardWidgetRegistry.js'
import { buildQueryString } from '@conduction/nextcloud-vue/src/utils/headers.js'
import { resolveQueryFilters } from '@conduction/nextcloud-vue/src/utils/routeFilters.js'
import { resolveScopeLayout } from '@conduction/nextcloud-vue/src/utils/scopeListLayout.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { buildRelatedFilters } from '../../src/utils/caseTypeFieldFilters.js'

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

/** The page whose columns a case type selects from. */
const casesPage = manifest.pages.find((page) => page.id === 'Cases')

/** The pages that hand their geometry to the reader. */
const DASHBOARD_PAGES = [
	'Dashboard',
	'MyWorkHome',
	'Doorlooptijd',
	'ProcessMiningDashboard',
	'TermijnDashboard',
]

/**
 * The key of a column entry, in either shape the manifest accepts.
 *
 * @param {(string|object)} column A column entry.
 * @return {string} The column key.
 */
function keyOf(column) {
	return typeof column === 'string' ? column : (column && column.key) || ''
}

describe('row 11.9: a case type layout survives the library that reads it', () => {
	const pageColumns = casesPage.config.columns

	const seeded = vthSeed.caseTypes.filter((caseType) => caseType['x-index'])

	it('has at least one seeded case type to check', () => {
		expect(seeded.length).toBeGreaterThan(0)
	})

	it.each(seeded.map((caseType) => [caseType.title, caseType]))(
		'%s keeps every column it declared',
		(title, caseType) => {
			const declared = caseType['x-index'].columns

			const resolved = resolveScopeLayout({
				// A register-derived folder is mapped to `{ id, name }` before
				// it reaches the resolver, so the LAYOUT can only come off the
				// row. A scope object carrying columns would never be read
				// here, which is why the declaration lives on the record.
				scope: { id: 'folder-1', name: title },
				row: { 'x-index': caseType['x-index'] },
				pageColumns,
			})

			expect(resolved.columns.map(keyOf)).toEqual(declared)
		},
	)

	it('carries the declared order through as sort keys', () => {
		const handhaving = seeded.find((caseType) =>
			caseType['x-index'].defaultSort?.some((entry) => entry.order === 'desc'),
		)

		const resolved = resolveScopeLayout({
			scope: { id: 'folder-1' },
			row: { 'x-index': handhaving['x-index'] },
			pageColumns,
		})

		expect(resolved.sortKeys).toEqual(handhaving['x-index'].defaultSort)
	})

	it('drops a column the page does not declare, which is why the page has to', () => {
		const resolved = resolveScopeLayout({
			scope: { id: 'folder-1' },
			row: { 'x-index': { columns: ['identifier', 'vervaldatum'] } },
			pageColumns,
		})

		expect(resolved.columns.map(keyOf)).toEqual(['identifier'])
	})

	it('leaves the page columns alone for a case type that declares none', () => {
		const resolved = resolveScopeLayout({
			scope: { id: 'folder-1' },
			row: null,
			pageColumns,
		})

		expect(resolved.columns).toBeNull()
	})
})

describe('row 9.2: the field filters have to leave the browser', () => {
	const compiled = buildRelatedFilters([
		{ definitionId: 'pd-1', value: { gte: '100000' } },
		{ definitionId: 'pd-4', value: { eq: 'Noord' } },
	])

	it('is dropped by the route-query reader, so the route cannot be the transport', () => {
		const query = { caseType: 'ct-1', ...compiled }

		// Every `_related` key is gone and only the case type survives. This
		// is the assertion the bar's second channel exists for: if a later
		// library forwards the underscore namespace, this reddens and the
		// `sendToList` hop can be taken out again.
		expect(Object.keys(resolveQueryFilters(query, {}))).toEqual(['caseType'])
	})

	it('survives the list fetch when it goes through the filter channel', () => {
		// `useListView.buildParams` copies an active filter's key into the
		// request verbatim, and `buildQueryString` is what the object store
		// turns that into. Round-tripping the result back through
		// URLSearchParams is the closest a unit test gets to reading the
		// request openregister receives.
		const params = {}
		Object.entries(compiled).forEach(([key, value]) => {
			params[key] = value
		})

		const parsed = new URLSearchParams(buildQueryString(params).slice(1))

		expect(
			parsed.get('_related[caseProperty][case][0][propertyDefinition]'),
		).toBe('pd-1')
		expect(parsed.get('_related[caseProperty][case][0][value][gte]')).toBe(
			'100000',
		)
		expect(
			parsed.get('_related[caseProperty][case][1][propertyDefinition]'),
		).toBe('pd-4')
		expect(parsed.get('_related[caseProperty][case][1][value]')).toBe('Noord')
	})

	it('nests under the schema and the foreign key the way the parser reads it', () => {
		// The shape PHP builds out of that query string: `_related` holds one
		// schema, the schema holds one foreign key, and the foreign key holds
		// the numbered rows. A number one level higher names a foreign key
		// called `0`, which openregister refuses.
		Object.keys(compiled).forEach((key) => {
			expect(key.startsWith('_related[caseProperty][case][')).toBe(true)
		})
	})
})

describe('row 10.1: a dashboard needs more than the one key', () => {
	const pages = DASHBOARD_PAGES.map((id) =>
		manifest.pages.find((page) => page.id === id),
	)

	it.each(DASHBOARD_PAGES.map((id, index) => [id, pages[index]]))(
		'%s declares the app id and page id its layout is stored under',
		(id, page) => {
			expect(page.config.userLayout).toBe(true)
			expect(page.config.appId).toBe('dossiq')
			expect(page.config.pageId).toBe(id)
		},
	)

	it('is the whole set: no other page asks for a user layout', () => {
		const asking = manifest.pages
			.filter((page) => page.config && page.config.userLayout)
			.map((page) => page.id)

		expect(asking.sort()).toEqual([...DASHBOARD_PAGES].sort())
	})

	it('names a page id that cannot move when somebody edits a title', () => {
		// Left undeclared, `resolvedPageId` slugifies the page TITLE, so
		// renaming "Processing time" would orphan every arrangement stored
		// under `processing-time` with nothing on screen to say why.
		const slugs = pages.map((page) =>
			String(page.title || '')
				.toLowerCase()
				.replace(/[^a-z0-9]+/g, '-')
				.replace(/^-|-$/g, ''),
		)

		expect(slugs).not.toEqual(pages.map((page) => page.config.pageId))
	})

	it('guards both the load and the save on the app id', () => {
		// Read out of the installed library rather than asserted from memory:
		// this is the line that makes `appId` load-bearing, and a release that
		// changes it should be read again before this test is edited.
		const source = fs.readFileSync(
			path.join(
				ROOT,
				'node_modules/@conduction/nextcloud-vue/src/components',
				'CnDashboardPage/CnDashboardPage.vue',
			),
			'utf8',
		)

		expect(source).toContain('if (!this.userLayout || !this.appId)')
		expect(
			source.match(/if \(!this\.userLayout \|\| !this\.appId/g).length,
		).toBeGreaterThanOrEqual(2)
	})

	it('offers the two preset lists through the library that reads them', () => {
		const dashboard = manifest.pages.find((page) => page.id === 'Dashboard')

		const presets = userWidgetPresets(dashboard.config)

		expect(presets.map((preset) => preset.label)).toEqual([
			'Cases you follow',
			"Your team's queue",
		])
		presets.forEach((preset) => {
			// A preset carries its own register, schema and filter, because a
			// reader has none of the three in front of them and a widget that
			// asks for one arrives as a blank card rather than as a refusal.
			expect(preset.widget.content.register).toBeTruthy()
			expect(preset.widget.content.schema).toBeTruthy()
			expect(Object.keys(preset.widget.content.filter).length).toBeGreaterThan(
				0,
			)

			// And no unresolved token: nothing resolves "my team", so a
			// preset filtering on one would send the literal string and be a
			// permanently empty card.
			Object.values(preset.widget.content.filter).forEach((value) => {
				expect(String(value).startsWith('@')).toBe(false)
			})
		})
	})
})
