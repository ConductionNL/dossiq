/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Why a term's date is not the date it was.
 *
 * A deadline that quietly became another deadline is the thing a handler
 * cannot see and an applicant will argue about. The move itself is lawful: an
 * extension, a pause, or a closure added to the organisation calendar. The
 * absence of a record is what turns it into a dispute.
 *
 * @spec openspec/changes/terms-on-the-engine-calendar/specs/termijnbewaking-schemas/spec.md
 */

import { describe, expect, it } from 'vitest'
import { moveLines, termRows } from '../../src/utils/caseTerms.js'

/** A translate that renders the key with its parameters, so the text is readable. */
function t (key, params = {}) {
  return String(key).replace(/\{(\w+)\}/g, (_, name) => String(params[name] ?? `{${name}}`))
}

describe('the lines under a moved term', () => {
	it('says where the date came from and where it went', () => {
		expect(
			moveLines(
				{
					moves: [
						{
							from: '2026-04-27T23:59:59+02:00',
							to: '2026-04-28T23:59:59+02:00',
							reason: 'Verlenging Awb 4:14',
						},
					],
				},
				t,
			),
		).toEqual(['Moved from 2026-04-27 to 2026-04-28: Verlenging Awb 4:14'])
	})

	it('keeps a move whose reason nobody recorded, and says so', () => {
		// Dropping it would hide the one move somebody will be asked about,
		// and writing a reason of our own would hide it better.
		expect(
			moveLines({ moves: [{ from: '2026-04-27', to: '2026-04-28' }] }, t),
		).toEqual(['Moved from 2026-04-27 to 2026-04-28, with no reason recorded'])
	})

	it('still says a move happened when the dates are missing', () => {
		expect(moveLines({ moves: [{ reason: 'Pauze Awb 4:5' }] }, t)).toEqual([
			'Moved: Pauze Awb 4:5',
		])
		expect(moveLines({ moves: [{}] }, t)).toEqual([
			'Moved, with no reason recorded',
		])
	})

	it('answers nothing for a term that never moved', () => {
		expect(moveLines({}, t)).toEqual([])
		expect(moveLines({ moves: null }, t)).toEqual([])
		expect(moveLines(undefined, t)).toEqual([])
	})
})

describe('the rows the tab renders', () => {
	it('carry the move lines beside the term', () => {
		// The panel reads `row.moves`, so a term whose moves never reached the
		// row renders an empty section and nothing says the history was lost.
		const rows = termRows(
			[
				{
					id: 't1',
					kind: 'statutory',
					endDate: '2026-04-28',
					moves: [{ from: '2026-04-27', to: '2026-04-28', reason: 'Awt' }],
				},
			],
			t,
		)

		expect(rows[0].moves).toEqual([
			'Moved from 2026-04-27 to 2026-04-28: Awt',
		])
	})

	it('give a term that never moved an empty list, not undefined', () => {
		// `v-for` over undefined renders nothing and warns; over an empty list
		// it renders nothing quietly, which is the intended state.
		const rows = termRows([{ id: 't2', kind: 'statutory' }], t)
		expect(rows[0].moves).toEqual([])
	})
})
