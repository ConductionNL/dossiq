/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * One menu holds every lifecycle act, and a refused act is in it (REQ-LIFE-10).
 *
 * The assertions that matter here are the ones about ABSENCE, because the
 * defect this change fixes is a handler who cannot tell "I may not do this"
 * from "this does not exist". So the menu is asserted to contain the acts it
 * refuses, carrying the reason, rather than merely to contain the acts it
 * allows.
 *
 * The second theme is that the menu derives nothing. Every `disabled` and
 * every `reason` traces to a server answer, so the tests feed answers and read
 * the menu rather than constructing a case and reasoning about it.
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */

import { describe, expect, it } from 'vitest'
import {
	buildActsMenu,
	endpointFor,
	inputsFor,
} from '../../src/utils/caseActsMenu.js'

/** A `/lifecycle` answer for an open case of a permissive case type. */
const OPEN = {
	suspended: false,
	canSuspend: true,
	canResume: false,
	canExtend: true,
	canReopen: false,
}

/** An `/acts` answer where the handler may finish and abort but not archive. */
const ACTS = {
	held: false,
	draft: false,
	acts: [
		{ act: 'finish', allowed: true, role: '', reason: '' },
		{ act: 'abort', allowed: true, role: '', reason: '' },
		{
			act: 'archive',
			allowed: false,
			role: 'archivaris',
			reason: 'This act needs the archivaris group.',
		},
	],
}

/**
 * One entry of a built menu.
 *
 * @param {Array<object>} menu The built menu.
 * @param {string} id The entry id.
 * @return {object|undefined} The entry.
 */
const entry = (menu, id) => menu.find((row) => row.id === id)

describe('the one lifecycle menu', () => {
	it('lists every act the provider publishes for the case', () => {
		const menu = buildActsMenu({
			transitions: [
				{ id: 'lc-approve', label: 'Approve', toStatus: 'st-4' },
				{ id: 'lc-close', label: 'Close', toStatus: 'st-5' },
			],
			state: OPEN,
			acts: ACTS,
		})

		expect(menu.filter((row) => row.kind === 'transition').map((row) => row.id)).toEqual([
			'lc-approve',
			'lc-close',
		])
	})

	it('shows a refused transition disabled, carrying the guard sentence', () => {
		const menu = buildActsMenu({
			transitions: [
				{
					id: 'lc-close',
					label: 'Close',
					guardsPassed: false,
					failedGuards: [{ failureMessage: 'The besluitnota is missing.' }],
				},
			],
			state: OPEN,
			acts: ACTS,
		})

		expect(entry(menu, 'lc-close').disabled).toBe(true)
		expect(entry(menu, 'lc-close').reason).toBe('The besluitnota is missing.')
	})

	it('disables a guard that refused with no sentence of its own', () => {
		// A button that stayed enabled because nobody wrote a message would
		// send the handler into a refusal instead of telling them beforehand.
		const menu = buildActsMenu({
			transitions: [{ id: 'lc-close', label: 'Close', guardsPassed: false }],
			state: OPEN,
			acts: ACTS,
		})

		expect(entry(menu, 'lc-close').disabled).toBe(true)
		expect(entry(menu, 'lc-close').reason).toBe('')
	})

	it('offers the four term gestures, each refused with its own sentence', () => {
		const menu = buildActsMenu({ transitions: [], state: OPEN, acts: ACTS })

		expect(entry(menu, 'suspend').disabled).toBe(false)
		expect(entry(menu, 'extend').disabled).toBe(false)

		expect(entry(menu, 'resume').disabled).toBe(true)
		expect(entry(menu, 'resume').reason).toBe('This case is not suspended.')
		expect(entry(menu, 'reopen').disabled).toBe(true)
		expect(entry(menu, 'reopen').reason).toBe('Only a closed case can be reopened.')
	})

	it('shows an act the role forbids, disabled, naming the group', () => {
		// The whole point of the change. Hiding Archive would leave the handler
		// with nothing to act on; naming the group tells them who to ask.
		const menu = buildActsMenu({ transitions: [], state: OPEN, acts: ACTS })

		expect(entry(menu, 'archive')).toBeTruthy()
		expect(entry(menu, 'archive').disabled).toBe(true)
		expect(entry(menu, 'archive').reason).toContain('archivaris')
		expect(entry(menu, 'archive').role).toBe('archivaris')
	})

	it('offers finish and abort when the handler may perform them', () => {
		const menu = buildActsMenu({ transitions: [], state: OPEN, acts: ACTS })

		expect(entry(menu, 'finish').disabled).toBe(false)
		expect(entry(menu, 'abort').disabled).toBe(false)
	})

	it('puts the irreversible acts after the ordinary ones', () => {
		// Transitions, then the term gestures, then the three ways to end it.
		// A hurried handler should not meet Archive at the top of a list.
		const menu = buildActsMenu({
			transitions: [{ id: 'lc-approve', label: 'Approve' }],
			state: OPEN,
			acts: ACTS,
		})
		const at = (id) => menu.findIndex((row) => row.id === id)

		expect(at('lc-approve')).toBeLessThan(at('suspend'))
		expect(at('suspend')).toBeLessThan(at('finish'))
		expect(at('finish')).toBeLessThan(at('archive'))
	})
})

describe('the menu when a read failed', () => {
	it('still offers the term gestures when the lifecycle read failed', () => {
		// The endpoint is the authority either way, and a menu that greyed
		// everything out because its own read failed would look exactly like a
		// case nobody is allowed to touch.
		const menu = buildActsMenu({ transitions: [], state: null, acts: ACTS })

		expect(entry(menu, 'suspend').disabled).toBe(false)
		expect(entry(menu, 'reopen').disabled).toBe(false)
	})

	it('disables the ending acts when the acts read failed, and says so', () => {
		// The opposite reading, deliberately. These three write archival
		// consequences, and the answer that is safe for a term gesture is not
		// safe here.
		const menu = buildActsMenu({ transitions: [], state: OPEN, acts: null })

		for (const act of ['finish', 'abort', 'archive']) {
			expect(entry(menu, act).disabled, `${act} must not be offered unread`).toBe(true)
			expect(entry(menu, act).reason).toContain('could not be read')
		}
	})

	it('builds an empty-ish menu from no answers at all without throwing', () => {
		const menu = buildActsMenu()

		expect(Array.isArray(menu)).toBe(true)
		expect(menu.filter((row) => row.kind === 'transition')).toHaveLength(0)
	})
})

describe('hold, release and promote follow the case state', () => {
	it('offers Hold on a case that is not held', () => {
		const menu = buildActsMenu({ transitions: [], state: OPEN, acts: ACTS })

		expect(entry(menu, 'hold')).toBeTruthy()
		expect(entry(menu, 'release-hold')).toBeUndefined()
	})

	it('offers Take off hold on a held case, and not Hold', () => {
		const menu = buildActsMenu({
			transitions: [],
			state: OPEN,
			acts: { ...ACTS, held: true },
		})

		expect(entry(menu, 'release-hold')).toBeTruthy()
		expect(entry(menu, 'hold')).toBeUndefined()
	})

	it('offers Promote only on a draft', () => {
		expect(entry(buildActsMenu({ state: OPEN, acts: ACTS }), 'promote')).toBeUndefined()
		expect(
			entry(buildActsMenu({ state: OPEN, acts: { ...ACTS, draft: true } }), 'promote'),
		).toBeTruthy()
	})

	it('reads the state flags the way every JSON boolean in this app is read', () => {
		const menu = buildActsMenu({ state: OPEN, acts: { ...ACTS, held: 'true' } })

		expect(entry(menu, 'release-hold')).toBeTruthy()
	})
})

describe('what each act asks for before it posts', () => {
	it('asks for a result on finish and abort, and on nothing else', () => {
		expect(inputsFor({ id: 'finish' }).result).toBe(true)
		expect(inputsFor({ id: 'abort' }).result).toBe(true)
		expect(inputsFor({ id: 'archive' }).result).toBe(false)
		expect(inputsFor({ id: 'hold' }).result).toBe(false)
	})

	it('asks for a wake date on hold, which the server refuses without', () => {
		expect(inputsFor({ id: 'hold' }).until).toBe(true)
		expect(inputsFor({ id: 'release-hold' }).until).toBe(false)
	})

	it('asks for a reason on everything but promote', () => {
		// Promote starts a term rather than changing one, so a reason field
		// would be one nobody can fill in honestly.
		expect(inputsFor({ id: 'promote' }).reason).toBe(false)
		for (const act of ['finish', 'abort', 'archive', 'hold', 'release-hold', 'suspend']) {
			expect(inputsFor({ id: act }).reason, `${act} records why`).toBe(true)
		}
	})

	it('asks for the days on suspend only', () => {
		expect(inputsFor({ id: 'suspend' }).days).toBe(true)
		expect(inputsFor({ id: 'hold' }).days).toBe(false)
	})

	it('posts a transition through the transition endpoint, not an act one', () => {
		expect(endpointFor({ kind: 'transition', id: 'lc-close' })).toBe('')
		expect(endpointFor({ kind: 'ending', id: 'archive' })).toBe('archive')
		expect(endpointFor({ kind: 'state', id: 'release-hold' })).toBe('release-hold')
	})
})
