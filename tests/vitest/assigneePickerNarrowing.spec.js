// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The assignee picker offers only what the case type allows.
 *
 * The requirement is that the picker and the write read the SAME declaration,
 * so the one thing worth asserting on this side is that the narrowing here
 * matches `AssigneeNarrowing` on the server, case for case:
 *
 *  - an empty allowed list keeps every option, because a case type that
 *    narrowed nothing has said nothing about who may take the case, and every
 *    case type on an existing instance is that one;
 *  - teams and people are separate axes, so narrowing the teams must not
 *    quietly narrow the people;
 *  - the order the picker was going to show is kept, because a narrowing is a
 *    filter and not a re-sort.
 *
 * A picker that offered a third team the API refuses is the defect this whole
 * requirement exists to prevent, so the third team is driven explicitly rather
 * than left to a length assertion.
 *
 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
 */

import { describe, expect, it } from 'vitest'
import {
	narrowGroups,
	narrowOptions,
	narrowUsers,
	normaliseDeclaration,
} from '../../src/utils/intakeRequirements.js'

/** A case type allowing exactly two teams. */
const TWO_TEAMS = {
	caseType: 'ct-handhavingsverzoek',
	assigneeNarrowing: {
		allowedGroups: ['handhaving', 'juridische-zaken'],
		allowedUsers: [],
	},
	narrowsNothing: false,
}

/** Every team the picker knows about. */
const EVERY_TEAM = ['handhaving', 'juridische-zaken', 'burgerzaken']

describe('the picker offers only the declared teams', () => {
	it('drops the team the write would refuse', () => {
		const offered = narrowGroups(EVERY_TEAM, TWO_TEAMS)

		expect(offered).toEqual(['handhaving', 'juridische-zaken'])
		expect(offered).not.toContain('burgerzaken')
	})

	it('keeps the order the picker was going to show', () => {
		const offered = narrowGroups(
			['juridische-zaken', 'burgerzaken', 'handhaving'],
			TWO_TEAMS,
		)

		expect(offered).toEqual(['juridische-zaken', 'handhaving'])
	})

	it('narrows options that are objects, through the reference reader', () => {
		const offered = narrowGroups(
			EVERY_TEAM.map((id) => ({ id, label: id.toUpperCase() })),
			TWO_TEAMS,
			(option) => option.id,
		)

		expect(offered.map((option) => option.id)).toEqual([
			'handhaving',
			'juridische-zaken',
		])
	})
})

describe('a case type with no narrowing keeps every choice', () => {
	it('offers every team it was given', () => {
		const undeclared = { caseType: 'ct-melding' }

		expect(narrowGroups(EVERY_TEAM, undeclared)).toEqual(EVERY_TEAM)
		expect(normaliseDeclaration(undeclared).narrowsNothing).toBe(true)
	})

	it('offers every person it was given', () => {
		expect(
			narrowUsers(['jdevries', 'mbakker'], { caseType: 'ct-melding' }),
		).toEqual(['jdevries', 'mbakker'])
	})
})

describe('teams and people are separate axes', () => {
	it('does not narrow the people when only the teams are declared', () => {
		expect(narrowUsers(['jdevries', 'mbakker'], TWO_TEAMS)).toEqual([
			'jdevries',
			'mbakker',
		])
	})

	it('does not narrow the teams when only the people are declared', () => {
		const peopleOnly = {
			assigneeNarrowing: { allowedGroups: [], allowedUsers: ['jdevries'] },
			narrowsNothing: false,
		}

		expect(narrowGroups(EVERY_TEAM, peopleOnly)).toEqual(EVERY_TEAM)
		expect(narrowUsers(['jdevries', 'mbakker'], peopleOnly)).toEqual([
			'jdevries',
		])
	})
})

describe('narrowOptions on its own', () => {
	it('keeps everything against an empty allowed list', () => {
		expect(narrowOptions(['a', 'b'], [])).toEqual(['a', 'b'])
	})

	it('answers an empty list when nothing offered is allowed', () => {
		expect(narrowOptions(['a', 'b'], ['c'])).toEqual([])
	})

	it('survives a missing offered list rather than throwing in a picker', () => {
		expect(narrowOptions(undefined, ['a'])).toEqual([])
		expect(narrowOptions(['a'], undefined)).toEqual(['a'])
	})
})

describe('what the picker reads off the declaration', () => {
	it('carries the two allowed teams through unchanged', () => {
		const read = normaliseDeclaration(TWO_TEAMS)

		expect(read.allowedGroups).toEqual(['handhaving', 'juridische-zaken'])
		expect(read.allowedUsers).toEqual([])
		expect(read.narrowsNothing).toBe(false)
	})

	it('copies the lists rather than handing out the server answer', () => {
		// A picker that pushed onto the array it was given would widen the
		// narrowing for every other component reading the same declaration.
		const read = normaliseDeclaration(TWO_TEAMS)
		read.allowedGroups.push('burgerzaken')

		expect(TWO_TEAMS.assigneeNarrowing.allowedGroups).toEqual([
			'handhaving',
			'juridische-zaken',
		])
	})
})
