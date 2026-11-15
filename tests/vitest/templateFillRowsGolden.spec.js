/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for `templateFillRows` (src/utils/templateFills.js):
 * every kind of fill, with an explicit locale so no date depends on the
 * machine. Snapshots taken on the code before it was split.
 */

import { describe, expect, it } from 'vitest'
import { templateFillRows } from '../../src/utils/templateFills.js'

const KASE = {
	start: '2026-10-05',
	ends: '2026-11-01T10:00:00Z',
	term: 'P28D',
	monthTerm: 'P1M',
	ext: 'P14D',
	extOdd: 'P10D',
	badExt: 'soon',
	title: 'Dakkapel',
	zero: 0,
	empty: '',
	nothing: null,
}

describe('template fill rows golden', () => {
	it.each([[null, KASE], [undefined, KASE], ['x', KASE], [[], null]])('reads no rows for fills %j', (fills, kase) => {
		expect(templateFillRows(fills, kase, 'en-GB')).toEqual([])
	})

	it('reads every kind of fill', () => {
		expect(templateFillRows([
			null,
			{ label: 'Fixed', value: 'Sent' },
			{ label: 'Empty fixed', value: '', field: 'title' },
			{ label: 'Text', field: 'title' },
			{ label: 'Zero', field: 'zero' },
			{ label: 'Blank', field: 'empty' },
			{ label: 'Null', field: 'nothing' },
			{ label: 'Absent', field: 'missing' },
			{ label: 'No field' },
			{ label: 'Date', field: 'start', as: 'date' },
			{ label: 'Bad date', field: 'title', as: 'date' },
			{ label: 'Deadline', field: 'ends', as: 'deadline', term: 'term' },
			{ label: 'Month deadline', field: 'ends', as: 'deadline', term: 'monthTerm' },
			{ label: 'No term', field: 'ends', as: 'deadline' },
			{ label: 'Extension', field: 'ext', as: 'extension', from: 'start' },
			{ label: 'Odd extension', field: 'extOdd', as: 'extension', from: 'start' },
			{ label: 'Extension without start', field: 'ext', as: 'extension', from: 'missing' },
			{ label: 'Bad extension', field: 'badExt', as: 'extension', from: 'start' },
			{ label: 'After', field: 'ext', as: 'after-extension', from: 'start' },
			{ label: 'After without start', field: 'ext', as: 'after-extension', from: 'missing' },
			{ label: 'Inherited name', field: 'title', as: 'toString' },
			{ label: 'Required missing', field: 'missing', required: true },
			{ label: 'Required present', field: 'title', required: true },
			{ label: 'Required deadline', field: 'ends', as: 'deadline', term: 'term', required: true },
		], KASE, 'en-GB')).toMatchInlineSnapshot(`
			[
			  {
			    "fixed": true,
			    "label": "Fixed",
			    "value": "Sent",
			  },
			  {
			    "label": "Empty fixed",
			    "value": "Dakkapel",
			  },
			  {
			    "label": "Text",
			    "value": "Dakkapel",
			  },
			  {
			    "label": "Zero",
			    "value": "0",
			  },
			  {
			    "label": "Date",
			    "value": "5 October 2026",
			  },
			  {
			    "label": "Deadline",
			    "note": "28 calendar days, late from 2 November",
			    "value": "1 November 2026",
			  },
			  {
			    "label": "Month deadline",
			    "value": "1 November 2026",
			  },
			  {
			    "label": "No term",
			    "value": "1 November 2026",
			  },
			  {
			    "label": "Extension",
			    "value": "2 weeks, until 19 October 2026",
			  },
			  {
			    "label": "Odd extension",
			    "value": "10 days, until 15 October 2026",
			  },
			  {
			    "label": "Extension without start",
			    "value": "2 weeks",
			  },
			  {
			    "label": "Bad extension",
			    "value": "soon",
			  },
			  {
			    "label": "After",
			    "value": "20 October 2026",
			  },
			  {
			    "label": "Inherited name",
			    "value": "Dakkapel",
			  },
			  {
			    "label": "Required missing",
			    "missing": true,
			    "required": true,
			    "value": "Still to fill in, not in the case",
			  },
			  {
			    "label": "Required present",
			    "missing": false,
			    "required": true,
			    "value": "Dakkapel",
			  },
			  {
			    "label": "Required deadline",
			    "missing": false,
			    "required": true,
			    "value": "1 November 2026",
			  },
			]
		`)
	})
})
