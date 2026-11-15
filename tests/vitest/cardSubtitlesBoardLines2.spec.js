/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * More muted lines the boards draw under card titles: the case plan
 * (DqZaak and the case tabs), notes (DqZaakEmail, DqTaak), decisions
 * (DqZaakBesluiten), custody (DqZaakArchivering), the LHS matrix
 * (DqHandhaving) and the catalogue's object types (DqCatalogus).
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

describe('more board lines under card titles', () => {
	it.each([
		['src/manifest.json', 'cmmn-case-plan', 'Mijlpalen en onderdelen van deze zaak'],
		['src/manifest.json', 'case-notes-panel', 'Interne notities, ook zichtbaar in de historie'],
		['src/manifest.json', 'case-decisions-panel', 'Uit de besluitvorming van decidiq, op de zaak getoond'],
		['src/manifest.json', 'case-custody-panel', 'Wie deze zaak in handen heeft gehad'],
		['src/manifest.json', 'task-notes', 'Alleen voor wie aan de taak werkt'],
		['src/manifest.json', 'lhs-matrix', 'Het gekozen vak is omlijnd'],
		['src/manifest.d/64-catalogue.json', 'catalogue-object-types', 'Objecten die u aan zaken koppelt; eigenschappen beheert u hier'],
	])('%s %s reads the board\'s line', (file, id, line) => {
		expect(nl[widget(read(file), id).subtitle]).toBe(line)
	})
})
