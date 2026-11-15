/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaakGerelateerd: the planned follow-ups stand under "Gepland vervolg",
 * and "Vervolg plannen" is a tonal pill at the end of that heading, not a
 * button under the card. The record list draws it from footerAction.group
 * and footerAction.variant.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const layout = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'menu-layout.simple.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8')).translations

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

describe('DqZaakGerelateerd planned follow-up action', () => {
	const card = findWidget(layout, 'case-related-cases-card')

	it('ends the planned group heading as a tonal pill', () => {
		expect(card.content.groupBy).toBe('group')
		expect(card.content.footerAction).toMatchObject({
			type: 'open-modal',
			target: 'CasePlanFollowUpDialog',
			group: 'planned',
			variant: 'tonal',
		})
	})

	it('reads as the board words it', () => {
		const { label } = card.content.footerAction
		expect(nl[label]).toBe('Vervolg plannen')
		expect(nl[card.content.valueLabels.group.planned]).toBe('Gepland vervolg')
	})
})
