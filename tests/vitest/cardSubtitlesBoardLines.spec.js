/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The boards draw a muted line under these card titles (DqZaak Flows,
 * DqZaakArchivering, DqZaakBesluiten, DqZaakContact, DqZaakTaken, DqTaak,
 * DqMijnWerk); the card's subtitle carries it, in the user's language.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const nl = read('l10n/nl.json').translations
const simple = read('src/menu-layout.simple.json')
const full = read('src/manifest.json')
const page = (manifest, id) => manifest.pages.find((entry) => entry.id === id)
const caseDetail = page(simple, 'CaseDetail')
const appended = (id) => caseDetail.configAppend.widgets.find((widget) => widget.id === id)
const patched = (id) => caseDetail.configPatch.widgets[id]

/**
 * Find a widget by id anywhere under a page.
 *
 * @param {object} node The page or a part of it.
 * @param {string} id The widget id.
 * @return {object|undefined} The widget.
 */
function findWidget(node, id) {
	if (Array.isArray(node)) {
		for (const item of node) {
			const hit = findWidget(item, id)
			if (hit) {
				return hit
			}
		}
	} else if (node && typeof node === 'object') {
		if (node.id === id && node.type) {
			return node
		}
		for (const value of Object.values(node)) {
			const hit = findWidget(value, id)
			if (hit) {
				return hit
			}
		}
	}
	return undefined
}

describe('the board lines under card titles', () => {
	it.each([
		[() => patched('case-flow-runs'), 'Wat er op deze zaak draaide of nog gepland staat'],
		[() => patched('case-archival-panel'), 'Wat er met deze zaak gebeurt als hij gesloten is'],
		[() => appended('case-decision-letter'), 'Het document dat naar de verzoeker gaat'],
		[() => appended('case-acknowledgement-card'), 'Awb 4:3a: de verzoeker hoort dat het verzoek binnen is'],
		[() => appended('case-conversations-card'), 'Een gesprek in Talk, gestart vanuit deze zaak'],
		[() => appended('case-calendar-card'), 'Uit de agenda, gekoppeld aan deze zaak'],
		[() => appended('case-deck-card'), 'Coördinatie in Deck, gekoppeld aan deze zaak'],
		[() => findWidget(page(full, 'TaskDetail'), 'task-appointments'), 'Uit de agenda, gekoppeld aan deze taak'],
		[() => findWidget(page(simple, 'MyWorkHome') || simple, 'archival-reviews'), 'Archiefbesluiten die op uw handtekening wachten'],
	])('card %# reads the board\'s line', (card, line) => {
		expect(nl[card().subtitle]).toBe(line)
	})
})
