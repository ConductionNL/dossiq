/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaakContact and DqZaakGerelateerd draw the contact moments, sub-cases and
 * objects tables edge to edge in their cards; DqZaakAssistent keeps the
 * assistant's table inside the card's padding.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const simple = read('src/menu-layout.simple.json').pages.find((page) => page.id === 'CaseDetail')
const card = (id) => simple.configAppend.widgets.find((widget) => widget.id === id)

describe('the case tab tables', () => {
	it.each(['case-contact-moments-card', 'case-sub-cases-card', 'case-objects-card'])('%s runs edge to edge', (id) => {
		expect(card(id).content.tableBleed).toBe(true)
	})

	it.each(['case-sub-cases-card', 'case-objects-card'])('%s sorts and filters from its column heads, as DqZaakGerelateerd draws them', (id) => {
		expect(card(id).content.sortable).toBe(true)
		expect(card(id).content.filterable).toBe(true)
	})

	it('keeps the assistant\'s table inside the card', () => {
		expect(card('case-ai-activity').content.tableBleed).toBeUndefined()
	})
})
