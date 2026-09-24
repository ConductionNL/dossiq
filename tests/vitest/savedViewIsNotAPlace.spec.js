/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A saved view is a lens applied onto the page's own address, not a place.
 *
 * WHAT THIS REPLACES, because the reversal is the point. `savedViewPlaces`
 * gave every view of the Cases and Queue pages a route of its own,
 * `/cases/views/9`. It worked. What it wrote into that address was a sort
 * spelled `_sortKey`/`_sortOrder`, which nothing in the stack reads: not
 * `parseSortKeysFromQuery`, which seeds the list's sort on load, and not
 * OpenRegister, which accepts `_order` and nothing else. So the sort survived
 * only in memory, and a reload of a link somebody sent lost it.
 *
 * THE DECLARATION IS GONE FROM THE VOCABULARY, NOT JUST FROM THIS MANIFEST.
 * `@conduction/nextcloud-vue` schema 2.40.0 removed the key, so a page that
 * declares it now fails `check:manifest` rather than validating and doing
 * nothing. The schema assertion below is what proves the vendored copy was
 * re-vendored: `tests/validate-manifest.js` forgives an unknown key as a
 * library lag whenever the VENDORED schema declares it, so a stale vendored
 * copy would let the old declaration pass as "pending a release".
 *
 * NOTHING HANGS IN THE NAVIGATION. Pinning went with the route: a pin is an
 * entry pointing at a view's address, and there is no address to point at.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const schema = JSON.parse(
	fs.readFileSync(
		path.join(ROOT, 'tests', 'schemas', 'app-manifest-v2.schema.json'),
		'utf8',
	),
)

const pageById = (id) => manifest.pages.find((page) => page.id === id)

describe('no page declares that its saved views are places', () => {
	it.each(['Cases', 'Queue'])(
		'%s keeps its saved views and drops the route',
		(id) => {
			const page = pageById(id)
			expect(page.type).toBe('index')
			expect(page.savedViewPlaces).toBeUndefined()
			// The dropdown stays. Only the address layer went.
			expect(page.config.allowSavedViews).toBe(true)
		},
	)

	it('declares the key on no page at all', () => {
		expect(
			manifest.pages.filter((page) => page.savedViewPlaces !== undefined),
		).toEqual([])
	})

	it('leaves Tasks out of saved views entirely, because its list is the engine inbox', () => {
		expect(pageById('Tasks').config.allowSavedViews).toBe(false)
	})
})

describe('a fresh install has the navigation it had before views were places', () => {
	it('has no menu entry pointing at a view route', () => {
		const entries = []
		const walk = (items) => {
			for (const item of items || []) {
				entries.push(item)
				walk(item.children)
			}
		}
		walk(manifest.menu)
		// `__view` was the suffix buildManifestRoutes gave a view route. No
		// such route is registered any more, so an entry naming one would
		// render and go nowhere.
		expect(
			entries.filter(
				(item) =>
					typeof item.route === 'string' && item.route.endsWith('__view'),
			),
		).toEqual([])
	})

	it('keeps both page entries top level, with no children added', () => {
		for (const id of ['Cases', 'Queue']) {
			const entry = manifest.menu.find((item) => item.route === id)
			expect(entry, `${id} has no menu entry`).toBeTruthy()
			expect(entry.children ?? []).toEqual([])
		}
	})
})

describe('the schema the manifest gate reads has dropped the key', () => {
	it('declares savedViewPlaces nowhere, so a stale declaration is refused rather than forgiven', () => {
		expect(schema.$defs.page.properties.savedViewPlaces).toBeUndefined()
		expect(schema.$defs.savedViewPlaces).toBeUndefined()
	})
})
