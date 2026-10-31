// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The catalogue's folders are entries under Advanced, and the Store reads
 * Marketplace.
 *
 * Both menus are built with the library's REAL `buildManifest`, and entries
 * are filtered the way CnAppNav filters them: by `permission` against the
 * account's permissions and by `visibleIf` through the library's
 * `passesContextPredicates`, against the runtime main.js provides.
 *
 * @spec openspec/changes/advanced-holds-the-catalogue-folders/specs/settings-navigation/spec.md
 * @spec openspec/changes/advanced-holds-the-catalogue-folders/specs/dossiq-store-surface/spec.md
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import { passesContextPredicates } from '@conduction/nextcloud-vue/src/utils/visibleIfContext.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { RETIRED_ROUTES } from '../../src/utils/manifestRoutes.js'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'

const ROOT = path.resolve(__dirname, '../..')

/**
 * Read a JSON file under the app root.
 *
 * @param {...string} parts Path segments.
 * @return {object} The parsed file.
 */
function readJson(...parts) {
	return JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
}

const fragments = fs
	.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
	.filter((name) => name.endsWith('.json'))
	.sort()
	.map((name) => readJson('src', 'manifest.d', name))

/**
 * The menu one account sees in one structure, children included, and the
 * built pages.
 *
 * @param {string} file The layout file.
 * @param {boolean} isAdmin Whether the account administers the instance.
 * @return {{menu: object[], pages: object[]}} The visible entries and the pages.
 */
function build(file, isAdmin) {
	const built = buildProfiledManifest(
		buildManifest,
		readJson('src', 'manifest.json'),
		fragments,
		readJson('src', file),
	)
	const permissions = isAdmin ? ['user', 'admin', 'privacy', 'sociaal-domein'] : ['user']
	const runtime = { user: { isAdmin } }
	const out = []
	const walk = (items) =>
		(items || []).forEach((item) => {
			if (item.permission && !permissions.includes(item.permission)) {
				return
			}
			if (item.visibleIf && !passesContextPredicates(item.visibleIf, runtime)) {
				return
			}
			out.push(item)
			walk(item.children)
		})
	walk(built.menu)
	return { menu: out, pages: built.pages }
}

/**
 * The Advanced foldout's entries, in the order the navigation draws them.
 *
 * @param {object[]} menu The visible entries.
 * @return {string[]} Their ids.
 */
function advanced(menu) {
	return menu
		.filter((entry) => entry.section === 'settings')
		.sort((a, b) => (a.order ?? Infinity) - (b.order ?? Infinity))
		.map((entry) => entry.id)
}

const manifest = readJson('src', 'manifest.json')
const nl = readJson('l10n', 'nl.json').translations
const en = readJson('l10n', 'en.json').translations

describe.each(['menu-layout.json', 'menu-layout.simple.json'])('%s', (file) => {
	it('🔴 an administrator finds the four folders first under Advanced, and no Catalogue', () => {
		const { menu } = build(file, true)
		expect(advanced(menu).slice(0, 4)).toEqual([
			'CaseTypesMenu',
			'PropertyDefinitionsMenu',
			'WorkflowDefinitionsMenu',
			'ObjectTypesMenu',
		])
		expect(menu.map((entry) => entry.id)).not.toContain('CatalogueMenu')
	})

	it('🔴 a user who is not an administrator keeps Case types, Attributes and Templates', () => {
		const ids = advanced(build(file, false).menu)
		expect(ids.slice(0, 3)).toEqual(['CaseTypesMenu', 'PropertyDefinitionsMenu', 'WorkflowDefinitionsMenu'])
		expect(ids).not.toContain('ObjectTypesMenu')
	})

	it('🔴 has no Catalogue page, and an admin-only Object types page', () => {
		const { pages } = build(file, true)
		expect(pages.find((p) => p.id === 'Catalogue')).toBeUndefined()
		expect(pages.find((p) => p.id === 'ObjectTypes')).toMatchObject({
			route: '/settings/object-types',
			permission: 'admin',
		})
		expect(pages.find((p) => p.id === 'CaseTypes').route).toBe('/settings/case-types')
	})

	it('🔴 calls the store Marketplace in the menu', () => {
		const store = build(file, false).menu.find((entry) => entry.id === 'StoreMenu')
		expect(store).toMatchObject({ label: 'Marketplace', route: 'Store', section: 'footer' })
	})
})

describe('an old catalogue link', () => {
	const route = RETIRED_ROUTES.find((r) => r.path === '/settings/catalogue')

	it('🔴 opens the case types on the folder it named', () => {
		expect(route.redirect({ query: { category: 'VTH' } })).toEqual({
			name: 'CaseTypes',
			query: { category: 'VTH' },
		})
	})

	it('🔴 otherwise opens the object types, the catalogue\'s main card', () => {
		expect(route.redirect({ query: {} })).toEqual({ name: 'ObjectTypes', query: {} })
	})
})

describe('the Store page', () => {
	const page = manifest.pages.find((p) => p.id === 'Store')

	it('🔴 is titled Marketplace and keeps its route', () => {
		expect(page.route).toBe('/store')
		expect(page.title).toBe('Marketplace')
		expect(page.config.title).toBe('Marketplace')
	})

	it('🔴 reads Marktplaats in Dutch', () => {
		expect(en.Marketplace).toBe('Marketplace')
		expect(nl.Marketplace).toBe('Marktplaats')
	})
})

describe('the tour', () => {
	const step = manifest.walkthrough.tours
		.find((tour) => tour.id === 'dossiq:getting-started')
		.steps.find((s) => s.id === 'see-case-types')

	it('opens Advanced, then Case types', () => {
		expect(step.target).toEqual({ kind: 'nav-item', ref: 'CaseTypes' })
		expect(step.task).toBe('Open Advanced, then Case types')
		expect(nl[step.task]).toBe('Open Geavanceerd, dan Zaaktypen')
	})
})
