// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * What a board card may be moved to.
 *
 * THIS REPLACES `columnsExcludingCurrent`, and the replacement is the fix. That
 * helper answered "every board column except this card's own", which is what
 * the card's move menu listed. A board column exists per status NAME across
 * every case type on the instance, so the menu offered a building permit the
 * statuses of unrelated workflows — two hundred entries on a real register,
 * nearly none of them reachable. The engine already answers the question per
 * case and per role; `moveTargetsFromTransitions` maps that answer.
 *
 * @spec openspec/specs/status-transition-engine/spec.md#requirement-transition-execution
 */

import { describe, expect, it } from 'vitest'
import { moveTargetsFromTransitions } from '../../src/utils/workflowBoardHelpers.js'

const statusById = {
	's-1': { name: 'Ontvangen' },
	's-2': { name: 'In behandeling' },
	's-3': { name: 'Afgehandeld' },
}

/**
 * One entry of the engine's offer.
 *
 * @param {string} toStatus The target statusType id.
 * @param {object} [extra] Guard fields, or an id of its own.
 * @return {object} The transition.
 */
function offer(toStatus, extra = {}) {
	return {
		id: `t-${toStatus}`,
		label: `to ${toStatus}`,
		toStatus,
		...extra,
	}
}

describe('moveTargetsFromTransitions', () => {
	it('names each target by its status, which is the column the move addresses', () => {
		const targets = moveTargetsFromTransitions(
			[offer('s-2'), offer('s-3')],
			statusById,
		)

		expect(targets).toEqual([
			{
				id: 'In behandeling',
				label: 'In behandeling',
				statusId: 's-2',
				disabled: false,
				reason: '',
			},
			{
				id: 'Afgehandeld',
				label: 'Afgehandeld',
				statusId: 's-3',
				disabled: false,
				reason: '',
			},
		])
	})

	it('offers a final status too, which the column-derived menu never could', () => {
		// The menu listed non-final columns only, so a case could not be closed
		// from the board at all. The engine offers the move; the board takes it.
		const targets = moveTargetsFromTransitions([offer('s-3')], statusById)
		expect(targets.map((target) => target.id)).toEqual(['Afgehandeld'])
	})

	it('lists a guard-blocked transition, disabled, carrying its reason', () => {
		const targets = moveTargetsFromTransitions(
			[
				offer('s-2', {
					guardsPassed: false,
					failedGuards: [{ failureMessage: 'No decision recorded yet' }],
				}),
			],
			statusById,
		)

		expect(targets[0].disabled).toBe(true)
		expect(targets[0].reason).toBe('No decision recorded yet')
	})

	it('keeps a blocked transition selectable-looking only when a guard passed', () => {
		const targets = moveTargetsFromTransitions(
			[offer('s-2', { guardsPassed: true })],
			statusById,
		)
		expect(targets[0].disabled).toBe(false)
	})

	it('keeps the FIRST of two transitions ending on the same status', () => {
		// `findTransitionToStatus` posts the first match, so the entry shown has
		// to be that one — otherwise the dialog describes one transition and
		// the board runs another.
		const targets = moveTargetsFromTransitions(
			[
				offer('s-2', { id: 'approve' }),
				offer('s-2', { id: 'approve-with-conditions', guardsPassed: false }),
			],
			statusById,
		)

		expect(targets).toHaveLength(1)
		expect(targets[0].disabled).toBe(false)
	})

	it('disables a target whose status the board cannot name', () => {
		// Without a name there is no column to address, so the move handler
		// would have nothing to resolve. Offered but unpickable beats a silent
		// drop: the handler can see the engine offered something.
		const targets = moveTargetsFromTransitions([offer('s-unknown')], statusById)

		expect(targets[0].disabled).toBe(true)
		expect(targets[0].label).toBe('to s-unknown')
	})

	it('skips an entry that names no target status', () => {
		expect(
			moveTargetsFromTransitions(
				[{ id: 't-1', label: 'nowhere' }, offer('s-2')],
				statusById,
			).map((target) => target.statusId),
		).toEqual(['s-2'])
	})

	it('survives a missing status map', () => {
		const targets = moveTargetsFromTransitions([offer('s-2')], undefined)
		expect(targets[0].disabled).toBe(true)
	})

	it.each([null, undefined, 'nope', 42])(
		'returns an empty list for %s',
		(input) => {
			expect(moveTargetsFromTransitions(input, statusById)).toEqual([])
		},
	)

	it('ignores non-object entries', () => {
		expect(
			moveTargetsFromTransitions([null, 'x', offer('s-2')], statusById),
		).toHaveLength(1)
	})
})
