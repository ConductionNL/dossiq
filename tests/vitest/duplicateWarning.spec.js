/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the duplicate panel offers, over a stubbed platform answer.
 *
 * 🔑 THE CASE THAT DECIDES WHETHER ANY OF THIS IS HONEST IS `checked: false`.
 * A failed check and a clean check both answer an empty match list, and a panel
 * that treated the first as an all clear would tell a handler "nothing looks
 * like this case" on the strength of a call that never completed. Every other
 * assertion here is about which buttons appear; this one is about not lying.
 */

import { describe, expect, it } from 'vitest'
import {
	affordancesFor,
	candidateFrom,
	matchedFields,
	matchPercentage,
	matchRow,
	mayFile,
	normalisePolicy,
} from '../../src/utils/duplicateWarning.js'

const oneMatch = [
	{
		uuid: 'existing-case-uuid',
		score: 0.97,
		matchedOn: ['requester', 'title'],
	},
]

describe('normalisePolicy', () => {
	it('reads block as block', () => {
		expect(normalisePolicy('block')).toBe('block')
	})

	it('reads anything else as warn, so a typo never stops an intake desk', () => {
		expect(normalisePolicy('blocking')).toBe('warn')
		expect(normalisePolicy(undefined)).toBe('warn')
		expect(normalisePolicy('')).toBe('warn')
	})
})

describe('affordancesFor', () => {
	it('shows the panel when the platform matched something', () => {
		const affordances = affordancesFor({
			matches: oneMatch,
			checked: true,
			policy: 'warn',
		})

		expect(affordances.show).toBe(true)
		expect(affordances.blocked).toBe(false)
		expect(affordances.mayContinue).toBe(true)
		expect(affordances.needsReason).toBe(false)
	})

	it('shows nothing when the platform matched nothing', () => {
		expect(affordancesFor({ matches: [], checked: true }).show).toBe(false)
	})

	it('shows nothing when the check never ran, and does not call it an all clear', () => {
		const affordances = affordancesFor({
			matches: oneMatch,
			checked: false,
			policy: 'block',
		})

		expect(affordances.show).toBe(false)
		expect(affordances.blocked).toBe(false)
		expect(affordances.mayContinue).toBe(false)
	})

	it('offers a handler under block no way through', () => {
		const affordances = affordancesFor({
			matches: oneMatch,
			checked: true,
			policy: 'block',
			mayOverride: false,
		})

		expect(affordances.blocked).toBe(true)
		expect(affordances.mayContinue).toBe(false)
		expect(affordances.needsReason).toBe(false)
	})

	it('offers a coordinator under block a way through, and asks why', () => {
		const affordances = affordancesFor({
			matches: oneMatch,
			checked: true,
			policy: 'block',
			mayOverride: true,
		})

		expect(affordances.blocked).toBe(true)
		expect(affordances.mayContinue).toBe(true)
		expect(affordances.needsReason).toBe(true)
	})
})

describe('mayFile', () => {
	it('lets a warn case through with no reason typed', () => {
		expect(mayFile({ mayContinue: true, needsReason: false })).toBe(true)
	})

	it('holds a blocked case until a coordinator says why', () => {
		expect(mayFile({ mayContinue: true, needsReason: true, reason: '' })).toBe(
			false,
		)
		expect(
			mayFile({ mayContinue: true, needsReason: true, reason: '   ' }),
		).toBe(false)
		expect(
			mayFile({ mayContinue: true, needsReason: true, reason: 'Second tree' }),
		).toBe(true)
	})

	it('never files when continuing was not offered', () => {
		expect(
			mayFile({ mayContinue: false, needsReason: false, reason: 'anything' }),
		).toBe(false)
	})
})

describe('matchedFields', () => {
	it('names the fields the scorer used, and nothing else', () => {
		expect(matchedFields(oneMatch[0])).toEqual(['requester', 'title'])
	})

	it('answers an empty list rather than inventing a reason', () => {
		expect(matchedFields({ matchedOn: [] })).toEqual([])
		expect(matchedFields(null)).toEqual([])
	})
})

describe('candidateFrom', () => {
	it('drops the blanks, because two empty strings score as a perfect match', () => {
		expect(
			candidateFrom({
				title: 'Kap eik',
				requester: '',
				permitApplicationRef: null,
				relatedCases: [],
				caseType: 'kapvergunning',
			}),
		).toEqual({ title: 'Kap eik', caseType: 'kapvergunning' })
	})

	it('keeps a falsy value that is a real answer', () => {
		expect(candidateFrom({ extensionCount: 0, isDraft: false })).toEqual({
			extensionCount: 0,
			isDraft: false,
		})
	})
})

describe('matchPercentage', () => {
	it('reads the score as a percentage', () => {
		expect(matchPercentage({ score: 0.97 })).toBe(97)
	})

	it('answers zero for a match carrying no score', () => {
		expect(matchPercentage({})).toBe(0)
		expect(matchPercentage(null)).toBe(0)
	})
})

describe('matchRow', () => {
	it('names a case the handler may read', () => {
		const row = matchRow(oneMatch[0], [
			{
				id: 'existing-case-uuid',
				title: 'Kapvergunning Eikenlaan',
				identifier: 'ZAAK-1',
			},
		])

		expect(row.readable).toBe(true)
		expect(row.title).toBe('Kapvergunning Eikenlaan')
		expect(row.identifier).toBe('ZAAK-1')
	})

	it('reports a match it may not name without naming it', () => {
		const row = matchRow(oneMatch[0], [])

		expect(row.readable).toBe(false)
		expect(row.title).toBe('')
		expect(row.uuid).toBe('existing-case-uuid')
	})
})
