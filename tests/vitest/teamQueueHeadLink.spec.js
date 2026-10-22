/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqMijnTeam draws "Naar de wachtrij" in the head of "Wachtrij van uw team".
 * It opens the team queue, the saved view the menu's Queue entry opens.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const manifest = read('src/manifest.json')
const nl = read('l10n/nl.json').translations

/**
 * Every object in a JSON tree that matches the predicate.
 *
 * @param {*} node The tree.
 * @param {Function} match The predicate.
 * @return {object[]} The matches.
 */
function findAll(node, match) {
	if (!node || typeof node !== 'object') {
		return []
	}
	const here = !Array.isArray(node) && match(node) ? [node] : []
	return here.concat(Object.values(node).flatMap((child) => findAll(child, match)))
}

describe('the team queue card head', () => {
	it('links to the team queue the menu opens', () => {
		const [card] = findAll(manifest, (n) => n.widgetId === 'your-teams-queue' && n.headerLink)
		const queue = manifest.menu.find((entry) => entry.id === 'Queue')
		expect(nl[card.headerLink.label]).toBe('Naar de wachtrij')
		expect(card.headerLink.route).toBe(queue.route)
		expect(card.headerLink.query).toEqual(queue.query)
	})
})
