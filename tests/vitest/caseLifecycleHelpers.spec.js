/**
 * SPDX-FileCopyrightText: 2026 Conduction / Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case page's lifecycle reasoning: stepper ordering, whether a transition
 * closes the case, whether it may be confirmed, and what a refusal means.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 * @spec openspec/specs/case-dashboard-view/spec.md
 */

import { describe, expect, it } from 'vitest'
import {
	buildTransitionPayload,
	canConfirmTransition,
	isClosingTransition,
	lifecycleRefusalCode,
	offeredLifecycleActions,
	refusalMessage,
	rowId,
	toStages,
} from '../../src/utils/caseLifecycleHelpers.js'

describe('rowId', () => {
	it('reads the id whichever shape OpenRegister used', () => {
		expect(rowId({ id: 'a' })).toBe('a')
		expect(rowId({ '@self': { id: 'b' } })).toBe('b')
		expect(rowId({ uuid: 'c' })).toBe('c')
		expect(rowId(null)).toBe('')
	})
})

describe('toStages', () => {
	const rows = [
		{ id: 'done', name: 'Afgehandeld', order: 3 },
		{ id: 'received', name: 'Ontvangen', order: 1 },
		{ id: 'progress', name: 'In behandeling', order: '2' },
	]

	it('orders the stages by order, reading numeric strings as numbers', () => {
		expect(toStages(rows).map((s) => s.id)).toEqual([
			'received',
			'progress',
			'done',
		])
	})

	it('labels each stage with the status name', () => {
		expect(toStages(rows)[0].label).toBe('Ontvangen')
	})

	it('sorts a status with no order last, not first', () => {
		const stages = toStages([...rows, { id: 'unplaced', name: 'Onbekend' }])
		expect(stages[stages.length - 1].id).toBe('unplaced')
	})

	it('keeps two statuses sharing an order in a stable order', () => {
		const tied = [
			{ id: 'b', name: 'B', order: 1 },
			{ id: 'a', name: 'A', order: 1 },
		]
		expect(toStages(tied).map((s) => s.id)).toEqual(['a', 'b'])
		expect(toStages([...tied].reverse()).map((s) => s.id)).toEqual(['a', 'b'])
	})

	it('survives a missing or malformed collection', () => {
		expect(toStages(undefined)).toEqual([])
		expect(toStages(['nonsense', null])).toEqual([])
	})
})

describe('isClosingTransition', () => {
	const statuses = [
		{ id: 'progress', isFinal: false },
		{ id: 'done', isFinal: true },
		{ id: 'archived', isFinal: 'true' },
	]

	it('is true for the status that closes the case', () => {
		expect(isClosingTransition(statuses, 'done')).toBe(true)
	})

	it('reads a JSON-shaped boolean', () => {
		expect(isClosingTransition(statuses, 'archived')).toBe(true)
	})

	it('is false for an ordinary status, an unknown one and none at all', () => {
		expect(isClosingTransition(statuses, 'progress')).toBe(false)
		expect(isClosingTransition(statuses, 'nope')).toBe(false)
		expect(isClosingTransition(statuses, '')).toBe(false)
	})
})

describe('canConfirmTransition', () => {
	it('allows an ordinary transition', () => {
		expect(
			canConfirmTransition({
				closing: false,
				resultTypes: [],
				resultTypeId: '',
				busy: false,
			}),
		).toBe(true)
	})

	it('blocks a closing transition until a result is picked', () => {
		const resultTypes = [{ id: 'granted' }, { id: 'refused' }]
		expect(
			canConfirmTransition({
				closing: true,
				resultTypes,
				resultTypeId: '',
				busy: false,
			}),
		).toBe(false)
		expect(
			canConfirmTransition({
				closing: true,
				resultTypes,
				resultTypeId: 'granted',
				busy: false,
			}),
		).toBe(true)
	})

	it('allows a closing transition on a case type that offers no results', () => {
		expect(
			canConfirmTransition({
				closing: true,
				resultTypes: [],
				resultTypeId: '',
				busy: false,
			}),
		).toBe(true)
	})

	it('blocks while a request is in flight', () => {
		expect(
			canConfirmTransition({
				closing: false,
				resultTypes: [],
				resultTypeId: '',
				busy: true,
			}),
		).toBe(false)
	})
})

describe('buildTransitionPayload', () => {
	it('carries only what was given', () => {
		expect(buildTransitionPayload({ transitionId: 't1' })).toEqual({
			transitionId: 't1',
		})
	})

	it('carries the comment and the result type when they are given', () => {
		expect(
			buildTransitionPayload({
				transitionId: 't2',
				comment: 'Klaar',
				resultTypeId: 'granted',
			}),
		).toEqual({ transitionId: 't2', comment: 'Klaar', resultTypeId: 'granted' })
	})

	it('omits an empty result type rather than sending it as an empty answer', () => {
		expect(
			buildTransitionPayload({ transitionId: 't2', resultTypeId: '' }),
		).toEqual({
			transitionId: 't2',
		})
	})
})

describe('offeredLifecycleActions', () => {
	it('offers what the case state allows, in menu order', () => {
		expect(
			offeredLifecycleActions({
				canSuspend: true,
				canExtend: true,
				canReopen: false,
			}),
		).toEqual(['suspend', 'extend'])
	})

	it('offers Resume instead of Suspend on a suspended case', () => {
		expect(
			offeredLifecycleActions({ canResume: true, canExtend: true }),
		).toEqual(['resume', 'extend'])
	})

	it('offers nothing when the state could not be read', () => {
		expect(offeredLifecycleActions(null)).toEqual([])
	})
})

describe('lifecycleRefusalCode', () => {
	it('refuses nothing when the case offers the gesture', () => {
		expect(lifecycleRefusalCode('suspend', { canSuspend: true })).toBe('')
		expect(lifecycleRefusalCode('reopen', { canReopen: true })).toBe('')
	})

	it('names why the case type forbids the gesture', () => {
		expect(
			lifecycleRefusalCode('suspend', { canSuspend: false, suspended: false }),
		).toBe('suspension_not_allowed')
		expect(lifecycleRefusalCode('extend', { canExtend: false })).toBe(
			'extension_not_allowed',
		)
		expect(lifecycleRefusalCode('resume', { canResume: false })).toBe(
			'not_suspended',
		)
		expect(lifecycleRefusalCode('reopen', { canReopen: false })).toBe(
			'case_not_closed',
		)
	})

	it('tells an already-suspended case apart from one that may not be suspended', () => {
		expect(
			lifecycleRefusalCode('suspend', { canSuspend: false, suspended: true }),
		).toBe('already_suspended')
	})

	it('refuses nothing when the state could not be read', () => {
		expect(lifecycleRefusalCode('suspend', null)).toBe('')
		expect(lifecycleRefusalCode('suspend', undefined)).toBe('')
	})
})

describe('refusalMessage', () => {
	const t = (s) => s

	it('shows the guard message the engine reported', () => {
		// `failureMessage` is the key GuardRegistry::evaluateAll writes. Feeding
		// this test a `message` key instead would have passed while the shipped
		// dialog showed the bare guard TYPE on every refusal.
		const body = {
			error: 'Transition is not available',
			failedGuards: [
				{
					type: 'requiredDocument',
					passed: false,
					failureMessage: 'A decision document is required.',
					details: {},
				},
			],
		}
		expect(refusalMessage(body, t)).toBe('A decision document is required.')
	})

	it('names a guard by type when it carries no message', () => {
		expect(
			refusalMessage({ failedGuards: [{ type: 'requiredDocument' }] }, t),
		).toBe('requiredDocument')
	})

	it('joins several failed guards into one sentence', () => {
		expect(
			refusalMessage(
				{
					failedGuards: [
						{
							type: 'requiredField',
							failureMessage: 'Vul de omschrijving in.',
						},
						{ type: 'mandaat', failureMessage: 'Mandaat ontbreekt.' },
					],
				},
				t,
			),
		).toBe('Vul de omschrijving in. Mandaat ontbreekt.')
	})

	it('turns a refusal code into a sentence', () => {
		expect(refusalMessage({ code: 'result_type_required' }, t)).toBe(
			'Pick a result before closing this case.',
		)
		expect(refusalMessage({ code: 'suspension_not_allowed' }, t)).toBe(
			'This case type does not allow suspension.',
		)
		expect(refusalMessage({ code: 'case_not_closed' }, t)).toBe(
			'Only a closed case can be reopened.',
		)
	})

	it('has a sentence for every code the two controllers can answer with', () => {
		// The lifecycle controller hands the code straight through, so a code
		// with no case here reaches the handler as "The case does not allow
		// this" — which names no cause and suggests no next step.
		const codes = [
			'result_type_required',
			'suspension_not_allowed',
			'extension_not_allowed',
			'extension_period_not_configured',
			'extension_period_unreadable',
			'already_suspended',
			'not_suspended',
			'case_not_closed',
			'initial_status_not_configured',
			'initial_status_not_of_case_type',
			'case_not_found',
			'reason_required',
		]
		for (const code of codes) {
			const sentence = refusalMessage(
				{ code, error: 'The case does not allow this' },
				t,
			)
			expect(sentence, code).not.toBe('The case does not allow this')
			expect(sentence.endsWith('.'), code).toBe(true)
		}
	})

	it('falls back to the server error, then to a sentence of its own', () => {
		expect(refusalMessage({ error: 'Could not execute transition' }, t)).toBe(
			'Could not execute transition',
		)
		expect(refusalMessage({}, t)).toBe('The case could not be changed.')
	})

	it('survives a translate function that is not one', () => {
		expect(refusalMessage({ code: 'reason_required' }, null)).toBe(
			'Give a reason first.',
		)
	})
})
