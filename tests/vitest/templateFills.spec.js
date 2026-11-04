/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The generate dialog lists what the chosen template fills in from the case
 * (DqDocumentGenereren, Verdagingsbrief: "Beslistermijn 1 november 2026",
 * "Verdaging 2 weken, tot 15 november 2026").
 *
 * @spec openspec/specs/generate-fills-per-template/spec.md
 */

import { describe, expect, it } from 'vitest'
import { templateFillRows } from '../../src/utils/templateFills.js'

const FILLS = [
	{ label: 'Decision deadline', field: 'legalDecisionDate', as: 'date' },
	{ label: 'Adjournment', field: 'statutoryExtension', as: 'extension', from: 'legalDecisionDate' },
]

describe('templateFillRows', () => {
	it('reads each fill off the case: a date in full, an extension in weeks with the day it ends', () => {
		const rows = templateFillRows(FILLS, { legalDecisionDate: '2026-11-01', statutoryExtension: 'P14D' }, 'nl-NL')
		expect(rows).toEqual([
			{ label: 'Decision deadline', value: '1 november 2026' },
			{ label: 'Adjournment', value: '2 weeks, until 15 november 2026' },
		])
	})

	it('keeps a required fill the case does not have, marked as still to fill in (DqDocumentGenereren "Motivering")', () => {
		const rows = templateFillRows([{ label: 'Reasoning', field: 'extensionReason', required: true }], {}, 'nl-NL')
		expect(rows).toEqual([{ label: 'Reasoning', value: 'Still to fill in, not in the case', required: true, missing: true }])
	})

	it('marks a required fill the case has as required, not missing', () => {
		const rows = templateFillRows([{ label: 'Reasoning', field: 'extensionReason', required: true }], { extensionReason: 'More time for the review.' }, 'nl-NL')
		expect(rows).toEqual([{ label: 'Reasoning', value: 'More time for the review.', required: true, missing: false }])
	})

	it('shows a fixed value the template states itself, as "Status after generating"', () => {
		const rows = templateFillRows([{ label: 'Status after generating', value: 'Draft' }], {}, 'nl-NL')
		expect(rows).toEqual([{ label: 'Status after generating', value: 'Draft', fixed: true }])
	})

	it('leaves out a fill whose field the case does not have', () => {
		expect(templateFillRows(FILLS, { legalDecisionDate: '2026-11-01' }, 'nl-NL')).toEqual([{ label: 'Decision deadline', value: '1 november 2026' }])
		expect(templateFillRows(undefined, {}, 'nl-NL')).toEqual([])
	})
})
