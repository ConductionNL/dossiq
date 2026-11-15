/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaakBetrokkenen "Zetels" as the board draws it (design-system #356): a
 * subtitle, the pairs in as many 200px columns as the card holds (three on
 * the case page), "Wacht op oppakken: Nee" and "Doorgegeven aan: Niemand"
 * when nothing is pending.
 *
 * @spec openspec/specs/case-page/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const simple = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/menu-layout.simple.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../l10n/nl.json'), 'utf8')).translations
const caseDetail = simple.pages.find((entry) => entry.id === 'CaseDetail')
const seats = caseDetail.configAppend.widgets.find((widget) => widget.id === 'case-seats-card')

describe('the seats card', () => {
	it('has the board subtitle', () => {
		expect(nl[seats.subtitle]).toBe('Wie het werk doet en wie ervoor staat')
	})

	it('lets the board look fit its pairs in 200px columns, not one column', () => {
		expect(seats.content.columns).toBe(3)
		expect(seats.content.fixedColumns).not.toBe(true)
	})

	it('names what is not pending in the board words', () => {
		const { handoverPending, handoverTo } = seats.content.overrides
		expect(nl[handoverPending.label]).toBe('Wacht op oppakken')
		expect(nl[handoverPending.emptyValue]).toBe('Nee')
		expect(nl[handoverTo.label]).toBe('Doorgegeven aan')
		expect(nl[handoverTo.emptyValue]).toBe('Niemand')
	})

	it('ends with the board footnote', () => {
		expect(nl[seats.content.footnote]).toBe('De coördinator staat bij Partijen, met zijn rol. Niet hier, zodat er één plek is om te kijken.')
	})
})
