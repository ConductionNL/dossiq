/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for `translateLensLabels`
 * (src/utils/translateLensLabels.js): the page notes and the quick filter
 * labels it translates, and what it leaves alone.
 */

import { describe, expect, it } from 'vitest'
import { translateLensLabels } from '../../src/utils/translateLensLabels.js'

/**
 * A translate function that marks what it translated.
 *
 * @param {string} text The key.
 * @return {string} The marked key.
 */
function marked(text) {
	return '[' + text + ']'
}

describe('translateLensLabels golden', () => {
	it('translates the notes and the string lens labels of every page', () => {
		const lens = { key: 'mine', label: 'Mine' }
		const odd = { key: 'odd', label: 4 }
		const manifest = {
			pages: [
				{ config: { footerNote: 'Note', bulkHint: 'Hint', quickFilters: [lens, odd, null] } },
				{ config: { footerNote: 7, quickFilters: 'none' } },
				{ id: 'no-config' },
				null,
			],
		}
		const out = translateLensLabels(manifest, marked)
		expect(out).toBe(manifest)
		expect(manifest.pages[0].config).toEqual({
			footerNote: '[Note]',
			bulkHint: '[Hint]',
			quickFilters: [{ key: 'mine', label: '[Mine]' }, odd, null],
		})
		expect(manifest.pages[0].config.quickFilters[1]).toBe(odd)
		expect(lens.label).toBe('Mine')
		expect(manifest.pages[1].config).toEqual({ footerNote: 7, quickFilters: 'none' })
	})

	it.each([null, undefined, {}, { pages: [] }])('hands %j back', (manifest) => {
		expect(translateLensLabels(manifest, marked)).toBe(manifest)
	})
})
