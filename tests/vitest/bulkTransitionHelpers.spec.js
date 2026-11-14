/**
 * SPDX-FileCopyrightText: 2026 Conduction / Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the bulk status-transition helper utilities
 * (case-bulk-status-transition): the payload builders.
 */

import { describe, expect, it } from 'vitest'
import {
	isLifecycleGesture,
	lifecycleParameters,
	transitionParameters,
} from '../../src/utils/bulkTransitionHelpers.js'

describe('isLifecycleGesture', () => {
	it('names the three gestures that move the clock, not the status', () => {
		expect(isLifecycleGesture('suspend')).toBe(true)
		expect(isLifecycleGesture('resume')).toBe(true)
		expect(isLifecycleGesture('extend')).toBe(true)
	})

	it('does not claim a transition', () => {
		// A transition moves `case.status`, whose only write path is the
		// status engine. Reading it as a lifecycle gesture here would send it
		// down the wrong half of the endpoint.
		expect(isLifecycleGesture('transition')).toBe(false)
		expect(isLifecycleGesture('')).toBe(false)
		expect(isLifecycleGesture(undefined)).toBe(false)
	})
})

describe('transitionParameters', () => {
	it('carries the transition and the comment the case timeline gets', () => {
		expect(transitionParameters('to-decided', 'Handled in bulk')).toEqual({
			transitionId: 'to-decided',
			comment: 'Handled in bulk',
		})
	})

	it('answers empty strings rather than undefined keys', () => {
		// A key whose value is `undefined` disappears in JSON, so the server
		// would read a missing parameter rather than an empty one, and the
		// refusal would name the wrong thing.
		expect(transitionParameters()).toEqual({ transitionId: '', comment: '' })
	})
})

describe('lifecycleParameters', () => {
	it('sends days with a suspend and nothing else', () => {
		expect(
			lifecycleParameters('suspend', {
				reason: 'Awaiting documents',
				days: '14',
			}),
		).toEqual({
			gesture: 'suspend',
			reason: 'Awaiting documents',
			days: 14,
		})
	})

	it('sends the new deadline with an extend and no days', () => {
		expect(
			lifecycleParameters('extend', {
				reason: 'Complex case',
				newEndDate: '2026-12-01',
			}),
		).toEqual({
			gesture: 'extend',
			reason: 'Complex case',
			newEndDate: '2026-12-01',
		})
	})

	it('sends neither with a resume', () => {
		expect(
			lifecycleParameters('resume', { reason: 'Documents arrived' }),
		).toEqual({
			gesture: 'resume',
			reason: 'Documents arrived',
		})
	})

	it('trims the reason, so whitespace cannot pass for a justification', () => {
		expect(lifecycleParameters('resume', { reason: '   ' }).reason).toBe('')
	})
})
