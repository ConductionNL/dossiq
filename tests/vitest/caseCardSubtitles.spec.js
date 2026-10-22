/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaakGerelateerd and DqZaakTaken draw a muted line under the titles of
 * Deelzaken, Objecten and Incidenten; the card's subtitle carries it.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const simple = read('src/menu-layout.simple.json').pages.find((page) => page.id === 'CaseDetail')
const nl = read('l10n/nl.json').translations
const card = (id) => simple.configAppend.widgets.find((widget) => widget.id === id)

describe('the case cards\' subtitles', () => {
	it.each([
		['case-sub-cases-card', 'Zaken die onder deze zaak hangen'],
		['case-objects-card', 'Waar deze zaak over gaat, met wat het object nu over zichzelf zegt'],
		['case-incidents-card', 'Meldingen binnen deze zaak, zoals een klacht over de behandeling'],
	])('%s reads the board\'s line', (id, line) => {
		expect(nl[card(id).subtitle]).toBe(line)
	})
})
