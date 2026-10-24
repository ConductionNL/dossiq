/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqDocumentenBeheer draws the cards about the ticked file ("Wie mag dit
 * zien: <file>", "Controlegetal") in the case's side column, in place of the
 * generic side cards, not in a column inside the files card.
 */

import { describe, expect, it } from 'vitest'
const panels = require('./helpers/casePanels.js')

describe('the cards about the selected document', () => {
	const props = panels.caseWidget('case-files').props

	it('stand in the page side column while a file is ticked', () => {
		expect(props.sideCardsPlacement).toBe('page-side')
	})

	it('are still the visibility and checksum cards, first', () => {
		expect(props.sideCards.map((card) => card.id).slice(0, 2)).toEqual(['visibility', 'checksum'])
	})

	it('write their dates through formatters the library has, not as raw ISO text', () => {
		const builtIn = ['date', 'datetime', 'relativeDate', 'relative-time']
		const dated = props.sideCards.flatMap((card) => card.fields).filter((field) => /date|time/i.test(field.formatter || ''))
		expect(dated.length).toBeGreaterThan(0)
		for (const field of dated) {
			expect(builtIn, field.key).toContain(field.formatter)
		}
		const checked = props.sideCards.find((card) => card.id === 'checksum').fields.find((field) => field.key === 'integrity.date')
		expect(checked.formatter).toBe('relativeDate')
		expect(checked.formatterOptions).toEqual({ time: true })
	})
})
