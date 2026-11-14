/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaakContact draws a muted line under "Ontvangstbevestiging" and "Live
 * gesprek"; the card's subtitle carries it.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const simple = read('src/menu-layout.simple.json').pages.find((page) => page.id === 'CaseDetail')
const nl = read('l10n/nl.json').translations
const card = (id) => simple.configAppend.widgets.find((widget) => widget.id === id)

describe('the Contact tab cards\' subtitles', () => {
	it.each([
		['case-acknowledgement-card', 'Awb 4:3a: de verzoeker hoort dat het verzoek binnen is'],
		['case-conversations-card', 'Een gesprek in Talk, gestart vanuit deze zaak'],
	])('%s reads the board\'s line', (id, line) => {
		expect(nl[card(id).subtitle]).toBe(line)
	})
})
