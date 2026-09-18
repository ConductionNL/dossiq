/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The pages whose saved views are places, as the manifest declares them.
 *
 * Three of these assertions exist because the failure they catch is silent.
 *
 * A DECLARATION NOTHING READS IS DARK. `savedViewPlaces` is rendered by
 * nextcloud-vue, so this manifest can carry the key for months while every
 * saved view stays behind the dropdown and nothing anywhere says so. The
 * schema assertion is the one mechanical signal we have: the vendored schema
 * the manifest gate reads must know the key, and the version it must know it
 * from is pinned here, so a dependency bump that has not happened yet cannot
 * be mistaken for one that has.
 *
 * NOTHING ARRIVES IN THE NAVIGATION UNINVITED. Pinning is the user's act, so
 * a fresh install must carry no view entry at all. A seeded pin would spend
 * the app's ADR-097 navigation budget on behalf of somebody who never asked,
 * and it would look exactly like a user's own pin in the rendered navigation.
 *
 * A PLACE ONLY MEANS SOMETHING WHERE THERE ARE VIEWS. The key is refused by
 * the schema off an index page, but nothing refuses it on an index page whose
 * saved views are turned OFF: there the route would exist and never have a
 * view to open. Tasks is exactly that page, so its exclusion is asserted
 * rather than remembered.
 *
 * @spec openspec/changes/cases-views-are-places/specs/case-management/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const schema = JSON.parse(fs.readFileSync(path.join(ROOT, 'tests', 'schemas', 'app-manifest-v2.schema.json'), 'utf8'))

const pageById = (id) => manifest.pages.find((page) => page.id === id)
const declaringPages = manifest.pages.filter((page) => page.savedViewPlaces !== undefined)

describe('the working lists declare that their saved views are places', () => {
	it.each(['Cases', 'Queue'])('%s declares places, under its own route', (id) => {
		const page = pageById(id)
		expect(page.type).toBe('index')
		expect(page.savedViewPlaces).toEqual({ enabled: true, routeBase: 'views' })
		// A place with no views to be a place OF is a route that opens an
		// empty state for ever.
		expect(page.config.allowSavedViews).toBe(true)
	})

	it('declares places on those two pages and nowhere else', () => {
		expect(declaringPages.map((page) => page.id).sort()).toEqual(['Cases', 'Queue'])
	})

	it('leaves Tasks out, because its list is the engine inbox and has no saved views', () => {
		const tasks = pageById('Tasks')
		expect(tasks.config.allowSavedViews).toBe(false)
		expect(tasks.savedViewPlaces).toBeUndefined()
	})

	it('leaves the configuration lists alone, where a view is a filter and not somewhere to work', () => {
		for (const id of ['CaseTypes', 'Contacts', 'Organisations', 'CaseObjects']) {
			const page = pageById(id)
			if (page) {
				expect(page.savedViewPlaces, `${id} declares places`).toBeUndefined()
			}
		}
	})
})

describe('a fresh install has the navigation it had yesterday', () => {
	it('seeds no pinned view anywhere in the menu', () => {
		const entries = []
		const walk = (items) => {
			for (const item of items || []) {
				entries.push(item)
				walk(item.children)
			}
		}
		walk(manifest.menu)
		// A pinned view is an entry pointing at a view route. The app seeds
		// none: pinning is the user's act, and a seeded pin spends the
		// ADR-097 budget on behalf of somebody who never asked for it.
		const viewEntries = entries.filter((item) => typeof item.route === 'string' && item.route.endsWith('__view'))
		expect(viewEntries).toEqual([])
	})

	it('keeps both page entries top level, with no children added', () => {
		for (const id of ['Cases', 'Queue']) {
			const entry = manifest.menu.find((item) => item.route === id)
			expect(entry, `${id} has no menu entry to hang pinned views under`).toBeTruthy()
			expect(entry.children ?? []).toEqual([])
		}
	})
})

describe('the schema the manifest gate reads knows the key', () => {
	it('carries savedViewPlaces on a page, from schema 2.34.0', () => {
		// The gate prefers the INSTALLED @conduction/nextcloud-vue schema over
		// this vendored copy, deliberately, so a manifest satisfies the rules
		// its own dependency ships. Until a release carrying 2.34.0 is
		// published and this app bumps to it, `npm run check:manifest` reads
		// 2.33.0 and rejects these two pages. That is the one known red on
		// this change and it clears with the bump, not with a manifest edit.
		expect(schema.version).toBe('2.34.0')
		expect(schema.$defs.page.properties.savedViewPlaces).toBeTruthy()
		expect(schema.$defs.savedViewPlaces.properties.routeBase.pattern).toBe('^[a-z0-9][a-z0-9-]*$')
	})
})
