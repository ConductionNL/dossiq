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

describe('the bulk bar over the ticked documents (DqDocumentenBeheer)', () => {
	const props = panels.caseWidget('case-files').props

	it('copies the ticked documents to another case, right after merging them into one PDF', () => {
		expect(props.bulkActions[1]).toMatchObject({ id: 'bulk-copy-to-case', target: 'BulkDocumentActionDialog', props: { mode: 'copy-to-case' } })
		expect(props.bulkActions.map((action) => action.id)).toEqual(expect.arrayContaining(['bulk-confidentiality', 'bulk-mark-final']))
	})
})

describe('merging the ticked documents (DqDocumentenBeheer "Samenvoegen tot pdf")', () => {
	it('is the first act in the band, through filinq', () => {
		const props = panels.caseWidget('case-files').props
		expect(props.bulkActions[0]).toMatchObject({ id: 'bulk-merge-pdf', target: 'BulkDocumentActionDialog', props: { mode: 'merge-pdf' } })
	})
})

describe('the checksum card says when the file was last checked (DqDocumentenBeheer "Laatst gecontroleerd")', () => {
	it('shows the last check and its outcome, the outcome in the error tone when the file changed or is gone', () => {
		const props = panels.caseWidget('case-files').props
		const fields = props.sideCards.find((card) => card.id === 'checksum').fields
		expect(fields.find((field) => field.key === 'integrity.checkedAt')).toMatchObject({ label: 'Last checked', formatter: 'relativeDate', formatterOptions: { time: true } })
		expect(fields.find((field) => field.key === 'integrity.status')).toMatchObject({ formatter: 'integrityStatus', colorMap: { changed: 'error', missing: 'error' } })
	})
})
