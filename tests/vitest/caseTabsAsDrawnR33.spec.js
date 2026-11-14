/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case cards as the boards draw them (r33 zero audit): the Locaties map
 * names its places under the map ("Fietspad Lindelaan, tussen nummer 2 en
 * 40", DqZaak), the Incidenten and Wat hierna komt rows set their titles
 * in bold (DqZaakTaken), and Behandeling names the team ("Woo en
 * informatie") from the case's group, as Zaakgegevens does.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const simple = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/menu-layout.simple.json'), 'utf8'))

/**
 * The widget with this id anywhere in the layout.
 *
 * @param {string} id The widget id.
 * @return {object|undefined} The widget.
 */
function widget(id) {
	let found
	const walk = (node) => {
		if (found || !node || typeof node !== 'object') {
			return
		}
		if (!Array.isArray(node) && node.id === id && node.content) {
			found = node
			return
		}
		Object.values(node).forEach(walk)
	}
	walk(simple)
	return found
}

describe('case cards as drawn (r33)', () => {
	it('names the places under the Locaties map', () => {
		const map = widget('case-locations-card')
		expect(map.content.labelsBelow).toBe(true)
		expect(map.content.markers.popupField).toBe('label')
	})

	it('names the Behandeling team by the case group\'s display name', () => {
		const team = widget('side-handling').content.fields.find((field) => field.label === 'Team')
		expect(team).toMatchObject({ path: 'assignedGroup', format: 'group' })
	})

	it('sets the Incidenten and Wat hierna komt row titles in bold', () => {
		expect(widget('case-incidents-card').content.strongTitle).toBe(true)
		expect(widget('case-planned-actions-card').content.strongTitle).toBe(true)
	})
})
