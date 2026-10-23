/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqTaak and DqZaakTaken: "Afspraak maken" is an outlined button with a
 * calendar icon in the Afspraken card, not the filled primary. DqTaak draws
 * only that button and keeps "Bestaande koppelen" in the card's Actions
 * menu; DqZaakTaken also shows "Bestaande koppelen" in the title row.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const layout = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'menu-layout.simple.json'), 'utf8'))

/**
 * Find a widget definition by id.
 *
 * @param {*} node The subtree.
 * @param {string} id The widget id.
 * @return {object|null} The widget.
 */
function findWidget(node, id) {
	if (Array.isArray(node)) {
		for (const item of node) {
			const hit = findWidget(item, id)
			if (hit) return hit
		}
		return null
	}
	if (node && typeof node === 'object') {
		if (node.id === id && node.type) return node
		for (const value of Object.values(node)) {
			const hit = findWidget(value, id)
			if (hit) return hit
		}
	}
	return null
}

describe('Afspraken: the create button is the quiet one', () => {
	it('DqTaak: "Afspraak maken" outlined with a calendar icon, "Bestaande koppelen" kept in the Actions menu', () => {
		const card = findWidget(manifest, 'task-appointments')
		expect(card.content).toMatchObject({ headerActions: ['create'], menuActions: ['link'], createVariant: 'secondary', createIcon: 'CalendarPlusOutline' })
	})

	it('DqZaakTaken: link and create, the create one outlined with a calendar icon', () => {
		const card = findWidget(layout, 'case-calendar-card')
		expect(card.props).toMatchObject({ headerActions: ['link', 'create'], createVariant: 'secondary', createIcon: 'CalendarPlusOutline' })
	})
})
