/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case map says how many places it shows. DqZaak and DqZaakMeer draw
 * "1 locatie" at the right end of the "Locaties" title row (the simple
 * profile's card: `headerNote`); the full profile's map keeps it under its
 * title (`headerSubtitle`).
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const nl = read('l10n/nl.json').translations

/**
 * The widget with this id under a manifest.
 *
 * @param {object} node The manifest or a part of it.
 * @param {string} id The widget id.
 * @return {object|undefined} The widget.
 */
function widget(node, id) {
	if (Array.isArray(node)) {
		for (const item of node) {
			const hit = widget(item, id)
			if (hit) {
				return hit
			}
		}
	} else if (node && typeof node === 'object') {
		if (node.id === id && node.type) {
			return node
		}
		for (const value of Object.values(node)) {
			const hit = widget(value, id)
			if (hit) {
				return hit
			}
		}
	}
	return undefined
}

describe('the case map says how many places it shows', () => {
	it.each([
		['src/menu-layout.simple.json', 'case-locations-card', 'headerNote'],
		['src/manifest.json', 'case-location-map', 'headerSubtitle'],
	])('%s %s', (file, id, key) => {
		const { one, other } = widget(read(file), id).content[key]
		expect(nl[one]).toBe('{total} locatie')
		expect(nl[other]).toBe('{total} locaties')
	})
})
