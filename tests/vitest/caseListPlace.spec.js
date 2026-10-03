/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case list as a place a handler works from, rather than one they pass
 * through.
 *
 * Every declaration here fails SILENTLY when it is wrong, which is why it is
 * worth a test at all. `splitView` without a `/<route>/split/:id` route makes
 * a row click log a console warning and do nothing; `splitView: { enabled:
 * false }` renders exactly like a page that never mentioned the key;
 * `manualOrder` on a page that is not an index is refused by the schema but
 * accepted by the eye; and a `permission` dropped from a split record reopens
 * an admin page at a second address that nobody thought to check.
 *
 * @spec openspec/changes/case-page-and-list-as-a-place/specs/case-management/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { routesFromManifest } from '../../src/utils/manifestRoutes.js'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)

const STUB = {}

/** The pages a handler triages from, which are the only ones that split. */
const TRIAGED_FROM = ['Cases', 'Queue']

/**
 * One page of the shipped manifest.
 *
 * @param {string} id The page id.
 * @return {object} The page entry.
 */
function page(id) {
	const found = (manifest.pages || []).find((entry) => entry.id === id)
	expect(found, `the manifest has no page "${id}"`).toBeTruthy()
	return found
}

/**
 * The route records the app registers, keyed by name.
 *
 * @return {object} Named records.
 */
function routesByName() {
	return Object.fromEntries(
		routesFromManifest(manifest, STUB)
			.filter((record) => record.name)
			.map((record) => [record.name, record]),
	)
}

describe('a case opens beside its list', () => {
	it.each(TRIAGED_FROM)('%s declares a working split view', (id) => {
		expect(page(id).splitView).toEqual({
			enabled: true,
			breakpoint: 1024,
			paneWidth: '42%',
		})
	})

	it.each(TRIAGED_FROM)('%s has the split address the pane opens at', (id) => {
		const records = routesByName()
		const split = records[`${id}__split`]
		expect(
			split,
			`${id} declares splitView but registers no split route, so every row click warns and does nothing`,
		).toBeTruthy()
		expect(split.path).toBe(`${page(id).route}/split/:id`)
	})

	it('mounts the same page at both addresses, so the list never unmounts', () => {
		const records = routesByName()
		for (const id of TRIAGED_FROM) {
			expect(records[`${id}__split`].meta.cnPageId).toBe(id)
			expect(records[`${id}__split`].meta.cnSplitOf).toBe(id)
		}
	})

	it('carries the breakpoint onto the record, so a phone gets the full page', () => {
		const records = routesByName()
		expect(records.Cases__split.meta.cnSplitBreakpoint).toBe(1024)
	})

	it('declares it on no page anybody merely browses', () => {
		const declaring = (manifest.pages || [])
			.filter((entry) => entry.splitView)
			.map((entry) => entry.id)
		expect(declaring.sort()).toEqual([...TRIAGED_FROM].sort())
		// The one the spec names by hand: nobody triages a case type.
		expect(page('CaseTypes').splitView).toBeUndefined()
	})

	it('keeps the page permission on the split address too', () => {
		// A split record is the same page at a second URL. `permissionGuard`
		// reads `meta.permission` and nothing else, so a record that lost the
		// field is an open door with no sign on it.
		const records = routesByName()
		for (const id of TRIAGED_FROM) {
			expect(records[`${id}__split`].meta.permission).toBe(
				page(id).permission || '',
			)
		}
	})
})

describe('a handler holds their own order', () => {
	it('is declared on the all-cases list', () => {
		expect(page('Cases').manualOrder).toBe(true)
	})

	it('is declared nowhere else, and never on a shared queue', () => {
		const declaring = (manifest.pages || [])
			.filter((entry) => entry.manualOrder === true)
			.map((entry) => entry.id)
		expect(declaring).toEqual(['Cases'])
	})
})

describe('the case page', () => {
	it('puts the open tab in the address', () => {
		expect(page('CaseDetail').tabInAddress).toBe(true)
	})

	it('offers a reference its summary in place', () => {
		expect(page('CaseDetail').referencePreview).toBe(true)
	})

	it('is still a detail page reached at /cases/:id', () => {
		// The split route is a SIBLING of the list, `/cases/split/:id`, and
		// vue-router ranks its static segment above the `:id` of this one. If
		// that ever stopped being true, a case would open the list instead.
		const records = routesByName()
		expect(records.CaseDetail.path).toBe('/cases/:id')
		expect(records.Cases__split.path).toBe('/cases/split/:id')
	})
})

describe('the personal layer', () => {
	it('is declared once, at the root', () => {
		expect(manifest.personalisation).toBeTruthy()
		expect(manifest.personalisation.enabled).toBe(true)
	})

	it('opens on the page a handler actually starts from', () => {
		const landing = manifest.personalisation.landingPage
		expect(
			(manifest.pages || []).some((entry) => entry.id === landing),
			`personalisation.landingPage names "${landing}", which is not a page`,
		).toBe(true)
	})

	it('pins only menu entries that exist', () => {
		const ids = new Set()
		const walk = (items) => {
			for (const item of items || []) {
				ids.add(item.id)
				walk(item.children)
			}
		}
		walk(manifest.menu)
		for (const pinned of manifest.personalisation.pinnedMenu) {
			expect(ids.has(pinned), `pinnedMenu names "${pinned}"`).toBe(true)
		}
	})

	it('reads dates absolutely by default', () => {
		// A relative date hides the date a statutory term runs to, and this is
		// a product where those terms are the point. A person may still choose
		// relative for themselves; the app default does not choose it for them.
		expect(manifest.personalisation.dateDisplay).toBe('absolute')
	})
})
