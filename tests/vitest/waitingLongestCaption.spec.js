/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqMijnWerk "Wacht op inwoner": under the count the tile says how long the
 * case that waits longest has waited, "langste: 9 dagen", counted from the
 * day each case entered its status.
 *
 * @spec openspec/specs/my-work-waiting-longest/spec.md#requirement-the-waiting-tile-says-how-long-the-longest-wait-is
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const simple = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/menu-layout.simple.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n/nl.json'), 'utf8')).translations

/**
 * Find a widget by id anywhere in a page tree.
 *
 * @param {unknown} node The tree.
 * @param {string} id The widget id.
 * @return {object|undefined} The widget.
 */
function findWidget(node, id) {
	if (Array.isArray(node)) {
		for (const child of node) {
			const found = findWidget(child, id)
			if (found) {
				return found
			}
		}
	} else if (node && typeof node === 'object') {
		if (node.id === id && node.type) {
			return node
		}
		for (const child of Object.values(node)) {
			const found = findWidget(child, id)
			if (found) {
				return found
			}
		}
	}
	return undefined
}

describe('the waiting tile on Mijn werk', () => {
	const tile = findWidget(simple, 'simple-waiting')

	it('captions the longest wait in days since the status was entered', () => {
		expect(tile.content.caption).toBe('longest: {count} days')
		expect(tile.content.captionSource.metric).toBe('daysSinceEarliest')
		expect(tile.content.captionSource.field).toBe('currentStatusEnteredAt')
		expect(tile.content.captionSource).not.toHaveProperty('zeroText')
	})

	it('reads the same cases the tile counts', () => {
		expect(tile.content.captionSource.filter).toEqual(tile.content.source.filter)
	})

	it('reads "langste: {count} dagen" in Dutch', () => {
		expect(nl['longest: {count} days']).toBe('langste: {count} dagen')
	})
})
