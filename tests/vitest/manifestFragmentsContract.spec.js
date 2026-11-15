/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The manifest fragments under src/manifest.d (ADR-037) merge cleanly onto
 * the base manifest: each adds only pages and menu entries, every page is
 * complete and unique by id and route, and every menu entry opens a page
 * that exists.
 */

import { describe, expect, it } from 'vitest'
import base from '../../src/manifest.json'

const fragments = Object.entries(import.meta.glob('../../src/manifest.d/*.json', { eager: true, import: 'default' }))
	.map(([path, fragment]) => [path.split('/').pop(), fragment])
	.sort(([a], [b]) => a.localeCompare(b))

const pages = [...base.pages, ...fragments.flatMap(([, f]) => f.pages ?? [])]
const menu = [...base.menu, ...fragments.flatMap(([, f]) => f.menu ?? [])]

/**
 * The values that occur more than once.
 *
 * @param {Array<string>} values The values.
 * @return {Array<string>} The duplicates.
 */
function duplicates(values) {
	return values.filter((value, index) => values.indexOf(value) !== index)
}

describe('manifest fragments', () => {
	it('loads every fragment, the placeholder included', () => {
		expect(fragments.length).toBeGreaterThan(1)
		expect(fragments.map(([name]) => name)).toContain('_placeholder.json')
	})

	it('only adds pages and menu entries, never overriding a base key', () => {
		for (const [name, fragment] of fragments) {
			expect(Object.keys(fragment).filter((key) => !['_meta', 'pages', 'menu'].includes(key)), name).toEqual([])
		}
	})

	it('leaves the placeholder empty', () => {
		const placeholder = fragments.find(([name]) => name === '_placeholder.json')[1]
		expect(placeholder).toEqual({ pages: [], menu: [] })
	})

	it('licenses each real fragment', () => {
		for (const [name, fragment] of fragments.filter(([n]) => n !== '_placeholder.json')) {
			expect(fragment._meta?.['spdx-license'], name).toBe('EUPL-1.2')
		}
	})

	it('gives every page an id, an absolute route, a type and a title', () => {
		for (const [name, fragment] of fragments) {
			for (const page of fragment.pages ?? []) {
				expect(typeof page.id, `${name} ${page.id}`).toBe('string')
				expect(page.route, `${name} ${page.id}`).toMatch(/^\//)
				expect(typeof page.type, `${name} ${page.id}`).toBe('string')
				expect(page.title, `${name} ${page.id}`).toBeTruthy()
			}
		}
	})

	it('keeps page ids and routes unique across the base and every fragment', () => {
		expect(duplicates(pages.map((p) => p.id))).toEqual([])
		expect(duplicates(pages.map((p) => p.route))).toEqual([])
	})

	it('points every fragment menu entry at a page, with a unique id', () => {
		const pageIds = new Set(pages.map((p) => p.id))
		for (const [name, fragment] of fragments) {
			for (const entry of fragment.menu ?? []) {
				expect(pageIds.has(entry.route), `${name} ${entry.id} -> ${entry.route}`).toBe(true)
			}
		}
		expect(duplicates(menu.map((m) => m.id))).toEqual([])
	})
})
