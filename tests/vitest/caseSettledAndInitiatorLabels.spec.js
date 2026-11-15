/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Two raw values on the case page in Zuiddrecht live round 3 (14 Oct):
 * a closed case read "24 dagen te laat", and the initiator read
 * "Soort initiator: company". The deadline tile is the library countdown,
 * which stops counting once `settledField` is truthy; the initiator type is
 * an enum the data widget labels through `x-enum-labels` and the l10n files.
 *
 * @spec openspec/specs/case-management/spec.md#requirement-the-case-page-shows-words-not-raw-values
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '..', '..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))

/**
 * Find the first value under `node` that `match` accepts, depth first.
 *
 * @param {*} node Any JSON value.
 * @param {(key: string, value: *) => boolean} match The test, given each key and value.
 * @return {*} The value, or undefined.
 */
function find(node, match) {
	if (!node || typeof node !== 'object') {
		return undefined
	}
	for (const [key, value] of Object.entries(node)) {
		if (match(key, value)) {
			return value
		}
		const hit = find(value, match)
		if (hit !== undefined) {
			return hit
		}
	}
	return undefined
}

describe('the case page shows words, not raw values', () => {
	it('stops the deadline tile counting overdue on a case in its final status', () => {
		const tile = find(
			read('src/manifest.json'),
			(key, value) => value?.id === 'case-tile-deadline',
		)
		expect(tile.content.settledField).toBe('isFinalStatus')
		expect(tile.content.settledLabel).toBe('Handled')

		// The simple structure patches the tile and replaces `content` whole.
		const patch = find(
			read('src/menu-layout.simple.json'),
			(key) => key === 'case-tile-deadline',
		)
		expect(patch.content.settledField).toBe('isFinalStatus')
		expect(patch.content.settledLabel).toBe('Handled')
	})

	it('labels every initiator type and has Dutch for each label', () => {
		const property = find(
			read('lib/Settings/dossiq_register.json'),
			(key, value) => key === 'initiatorType' && Array.isArray(value?.enum),
		)
		const labels = property['x-enum-labels']
		expect(Object.keys(labels).sort()).toEqual([...property.enum].sort())

		const nl = read('l10n/nl.json').translations
		for (const label of [...Object.values(labels), 'Handled']) {
			expect(nl[label], label).toBeTruthy()
		}
		expect(nl.Company).toBe('Bedrijf')
	})
})
