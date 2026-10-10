// @vitest-environment jsdom
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

describe('a case opens as its own page', () => {
	// Round 6 (9 Oct): no board draws a split pane, and the canon is that a row
	// opens the detail page. A row click on Cases or Queue went to
	// /cases/split/<id> because both pages declared splitView.
	it.each(TRIAGED_FROM)('%s declares no split view', (id) => {
		expect(page(id).splitView).toBeUndefined()
	})

	it.each(TRIAGED_FROM)('%s registers no split address', (id) => {
		expect(routesByName()[`${id}__split`]).toBeUndefined()
	})

	it('declares a split view on no page', () => {
		const declaring = (manifest.pages || []).filter((entry) => entry.splitView)
		expect(declaring).toEqual([])
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
