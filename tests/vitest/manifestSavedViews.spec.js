/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Saved views on every index page: each index page with a filter sidebar
 * offers the sidebar's Filters tab (save, apply, edit, share, add to the
 * menu, copy a default, delete), and scopes its views to its own source so
 * a view saved on one list does not show up on another.
 *
 * The rule is nextcloud-vue's `pageOffersSavedViews`: `allowSavedViews`
 * decides when set; unset, a page with a sidebar and a register and schema,
 * a named source or a `savedViewsScope` offers them.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(file, 'utf8'))
const files = [
	path.join(ROOT, 'src', 'manifest.json'),
	...fs.readdirSync(path.join(ROOT, 'src', 'manifest.d')).filter((f) => f.endsWith('.json')).map((f) => path.join(ROOT, 'src', 'manifest.d', f)),
]
const indexPages = files.flatMap((file) => {
	const doc = read(file)
	return (Array.isArray(doc.pages) ? doc.pages : []).filter((page) => page.type === 'index')
})

/**
 * Index pages with no filter sidebar: a left-hand list or a plain inbox, so there is nothing to save but a search term.
 */
const NO_FILTER_SIDEBAR = ['Flows', 'EnvironmentOrganisations', 'Departments']
// The mailbox has a filter panel (DqPostvak's Filter button) but no saved views: its board draws no Save view.
const NO_SAVED_VIEWS = [...NO_FILTER_SIDEBAR, 'Postvak']

/**
 * The scope a page's views are kept under, as nextcloud-vue builds it.
 *
 * @param {object} config The page config.
 * @return {string} The scope, or '' when there is nothing to scope a view to.
 */
function scopeOf(config) {
	if (config.savedViewsScope) {
		return config.savedViewsScope
	}
	if (config.register && config.schema) {
		return `${config.register}/${config.schema}`
	}
	return config.entitySource ? `source:${config.entitySource}` : ''
}

/**
 * Whether the page offers saved views in its sidebar.
 *
 * @param {object} config The page config.
 * @return {boolean}
 */
function offersSavedViews(config) {
	if (typeof config.allowSavedViews === 'boolean') {
		return config.allowSavedViews
	}
	return Boolean(config.sidebar && config.sidebar.enabled === true && config.sidebar.show !== false) && scopeOf(config) !== ''
}

describe('saved views on every dossiq index page', () => {
	it('finds the index pages', () => {
		expect(indexPages.length).toBeGreaterThan(20)
		expect(indexPages.map((page) => page.id)).toEqual(expect.arrayContaining(['Cases', 'Tasks', 'Contacts', 'Organisations']))
	})

	it.each(indexPages.filter((page) => !NO_SAVED_VIEWS.includes(page.id)).map((page) => [page.id, page]))('%s offers saved views', (id, page) => {
		const config = page.config || {}
		expect(offersSavedViews(config)).toBe(true)
		expect(scopeOf(config)).not.toBe('')
	})

	it('keeps the task inbox\'s views to the task inbox', () => {
		const tasks = indexPages.find((page) => page.id === 'Tasks').config
		expect(tasks.allowSavedViews).toBe(true)
		expect(tasks.savedViewsScope).toBe('source:tasks')
	})

	it('lists as without a filter sidebar only pages that have none', () => {
		for (const id of NO_FILTER_SIDEBAR) {
			const config = indexPages.find((page) => page.id === id).config || {}
			expect(config.sidebar && config.sidebar.enabled === true).toBeFalsy()
		}
	})
})
