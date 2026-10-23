/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The catalogue holds the case types, and the Store reads Marketplace.
 *
 * Both menus are built with the library's REAL `buildManifest` and the
 * entries filtered with its REAL `passesContextPredicates`, the function
 * CnAppNav runs on `visibleIf`, against the runtime main.js provides.
 *
 * @spec openspec/changes/catalogue-holds-case-types-and-marketplace/specs/catalogue/spec.md
 * @spec openspec/changes/catalogue-holds-case-types-and-marketplace/specs/getting-started-tour/spec.md
 * @spec openspec/changes/catalogue-holds-case-types-and-marketplace/specs/dossiq-store-surface/spec.md
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import { passesContextPredicates } from '@conduction/nextcloud-vue/src/utils/visibleIfContext.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
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
 * The entries one account sees in one structure, children included.
 *
 * @param {string} file The layout file.
 * @param {boolean} isAdmin Whether the account administers the instance.
 * @return {object[]} The visible entries.
 */
function visibleMenu(file, isAdmin) {
	const built = buildProfiledManifest(
		buildManifest,
		readJson('src', 'manifest.json'),
		fragments,
		readJson('src', file),
	)
	const permissions = isAdmin ? ['user', 'admin'] : ['user']
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

const manifest = readJson('src', 'manifest.json')
const nl = readJson('l10n', 'nl.json').translations
const en = readJson('l10n', 'en.json').translations

describe.each(['menu-layout.json', 'menu-layout.simple.json'])('%s', (file) => {
	it('🔴 an administrator finds the case types in the catalogue, not as their own entry', () => {
		const { menu, pages } = visibleMenu(file, true)
		const ids = menu.map((entry) => entry.id)
		expect(ids).toContain('CatalogueMenu')
		expect(ids).not.toContain('CaseTypesMenu')

		const catalogue = pages.find((page) => page.id === 'Catalogue')
		const folders = catalogue.config.widgets.find((w) => w.id === 'catalogue-folders')
		expect(folders.content.items[0].url).toBe('/index.php/apps/dossiq/settings/case-types')
	})

	it('a user without the catalogue keeps the Case types entry', () => {
		const ids = visibleMenu(file, false).menu.map((entry) => entry.id)
		expect(ids).not.toContain('CatalogueMenu')
		expect(ids).toContain('CaseTypesMenu')
	})

	it('keeps the Case types page and its route', () => {
		const page = visibleMenu(file, true).pages.find((p) => p.id === 'CaseTypes')
		expect(page.route).toBe('/settings/case-types')
	})

	it('🔴 calls the store Marketplace in the menu', () => {
		const store = visibleMenu(file, false).menu.find((entry) => entry.id === 'StoreMenu')
		expect(store.label).toBe('Marketplace')
		expect(store.route).toBe('Store')
		expect(store.section).toBe('footer')
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

	it('🔴 opens the catalogue for the case types', () => {
		expect(step.target).toEqual({ kind: 'nav-item', ref: 'Catalogue' })
		expect(step.task).toBe('Open Advanced, then Catalogue')
		expect(step.body).toMatch(/first folder of the catalogue/)
		expect(step.optional).toBe(true)
		expect(nl[step.task]).toBe('Open Geavanceerd, dan Catalogus')
	})
})
