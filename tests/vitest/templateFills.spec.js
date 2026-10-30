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

	it('leaves out a fill whose field the case does not have', () => {
		expect(templateFillRows(FILLS, { legalDecisionDate: '2026-11-01' }, 'nl-NL')).toEqual([{ label: 'Decision deadline', value: '1 november 2026' }])
		expect(templateFillRows(undefined, {}, 'nl-NL')).toEqual([])
	})
})
