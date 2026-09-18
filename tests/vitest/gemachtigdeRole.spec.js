// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Every case type offers Gemachtigde, and a gemachtigde row says whom they act for.
 *
 * Three things here cannot be seen from a screenshot, and each one has a test.
 *
 * THE ORDER IS THE REQUIREMENT. "The case type's own rows, then the generic
 * ones" is an ordering, and an ordering that stops working looks exactly like
 * an instance whose role types happen to sort that way. So the assertions name
 * the rows in order rather than counting them.
 *
 * A GENERIC ROW LISTED TWICE is the failure a handler actually meets: two
 * entries called Gemachtigde with nothing on screen to tell them apart. The
 * deduplication is keyed on `genericRole`, so the test gives the case type's own
 * row that key and a different name.
 *
 * AN UNKNOWN CASE TYPE MUST NOT EMPTY THE PICKER. Returning the generic rows
 * alone for a case whose type could not be read would say this instance
 * declares no roles, on exactly the case where somebody is trying to add one.
 *
 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md
 */
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, text) => text,
}))

const { offeredRoleTypes } = await import(
	'../../src/services/roleTypeOptions.js'
)
const { representedByMap } = await import('../../src/services/caseParties.js')

const rows = [
	{ id: 'rt-handler', name: 'Behandelaar', caseType: 'ct-vergunning' },
	{ id: 'rt-other', name: 'Bezwaarbehandelaar', caseType: 'ct-bezwaar' },
	{
		id: 'rt-gem',
		name: 'Gemachtigde',
		genericRole: 'gemachtigde',
	},
]

describe('the roles a case type offers', () => {
	it('offers Gemachtigde on a type that declares none, after its own roles', () => {
		const offered = offeredRoleTypes(rows, 'ct-vergunning')

		expect(offered.map((option) => option.id)).toEqual([
			'rt-handler',
			'rt-gem',
		])
		expect(offered[1].scope).toBe('generic')
	})

	it('leaves another case type\'s roles off the list', () => {
		const offered = offeredRoleTypes(rows, 'ct-vergunning')

		expect(offered.map((option) => option.id)).not.toContain('rt-other')
	})

	it('lists Gemachtigde once when the type declares its own', () => {
		const withOwn = [
			...rows,
			{
				id: 'rt-bezwaar-gem',
				name: 'Gemachtigde bezwaar',
				genericRole: 'gemachtigde',
				caseType: 'ct-bezwaar',
			},
		]

		const offered = offeredRoleTypes(withOwn, 'ct-bezwaar')

		expect(offered.map((option) => option.id)).toEqual([
			'rt-other',
			'rt-bezwaar-gem',
		])
		expect(
			offered.filter((option) => option.genericRole === 'gemachtigde'),
		).toHaveLength(1)
	})

	it('offers every role type when the case type is unknown', () => {
		const offered = offeredRoleTypes(rows, '')

		expect(offered.map((option) => option.id)).toEqual([
			'rt-handler',
			'rt-other',
			'rt-gem',
		])
	})

	it('reads a case type handed over as a reference object', () => {
		const nested = [{ id: 'rt-x', name: 'X', caseType: { id: 'ct-a' } }]

		expect(offeredRoleTypes(nested, 'ct-a').map((o) => o.id)).toEqual(['rt-x'])
	})
})

describe('who a party is representing', () => {
	it('names the represented party for the participant acting for them', () => {
		const map = representedByMap([
			{
				participant: 'party-gem',
				roleType: 'rt-gem',
				representedParty: 'party-applicant',
			},
			{ participant: 'party-applicant', roleType: 'rt-handler' },
		])

		expect(map).toEqual({ 'party-gem': 'party-applicant' })
	})

	it('ignores delegateFrom, which is a date and not a party', () => {
		const map = representedByMap([
			{
				participant: 'party-gem',
				delegateFrom: '2026-09-01T00:00:00+00:00',
			},
		])

		expect(map).toEqual({})
	})

	it('reads a represented party handed over as a reference object', () => {
		const map = representedByMap([
			{ participant: 'p', representedParty: { id: 'q' } },
		])

		expect(map).toEqual({ p: 'q' })
	})

	it('answers an empty map for a case whose roles could not be read', () => {
		expect(representedByMap(null)).toEqual({})
	})
})
