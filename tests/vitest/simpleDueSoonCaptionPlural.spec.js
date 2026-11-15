/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The simple profile's "Due this week" tile names what ends today in a
 * singular and a plural form, so the Dutch caption reads "1 loopt vandaag af"
 * and "2 lopen vandaag af" (nc-vue CnStatWidget `caption: { singular, plural }`,
 * schema 2.173.0: a count of one takes the singular, any other count the plural).
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))

/**
 * Find the widget with the given id anywhere in a layout tree.
 *
 * @param {*} node A layout node.
 * @param {string} id The widget id.
 * @return {object|null} The widget, or null.
 */
function findWidget(node, id) {
	if (Array.isArray(node)) {
		for (const child of node) {
			const hit = findWidget(child, id)
			if (hit) {
				return hit
			}
		}
		return null
	}
	if (node && typeof node === 'object') {
		if (node.id === id && node.type === 'stat') {
			return node
		}
		return findWidget(Object.values(node), id)
	}
	return null
}

const tile = findWidget(read('src', 'menu-layout.simple.json'), 'simple-due-soon')
const nl = read('l10n', 'nl.json').translations
const en = read('l10n', 'en.json').translations

/**
 * Render the tile caption the way CnStatWidget picks and fills its form.
 *
 * @param {object} catalogue The translations.
 * @param {number} count The number of cases that end today.
 * @return {string} The caption.
 */
function caption(catalogue, count) {
	const form = count === 1 ? tile.content.caption.singular : tile.content.caption.plural
	return catalogue[form].replace('{count}', String(count))
}

describe('simple profile "Due this week" caption', () => {
	it('carries a singular and a plural form', () => {
		expect(tile.content.caption).toEqual({ singular: '{count} ends today', plural: '{count} end today' })
	})

	it('reads in the singular for one case', () => {
		expect(caption(nl, 1)).toBe('1 loopt vandaag af')
		expect(caption(en, 1)).toBe('1 ends today')
	})

	it('reads in the plural for two cases', () => {
		expect(caption(nl, 2)).toBe('2 lopen vandaag af')
		expect(caption(en, 2)).toBe('2 end today')
	})

	it('keeps its zero text', () => {
		expect(nl[tile.content.captionSource.zeroText]).toBe('niets loopt vandaag af')
	})
})
