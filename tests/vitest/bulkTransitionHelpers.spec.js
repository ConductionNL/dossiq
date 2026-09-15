/**
 * SPDX-FileCopyrightText: 2026 Conduction / Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the bulk status-transition helper utilities
 * (case-bulk-status-transition): column-scoped selection toggling
 * (incl. cross-column reset), payload builders, and result summarisation.
 */

import { describe, expect, it } from 'vitest'
import {
	clearSelection,
	emptySelection,
	isLifecycleGesture,
	isSelected,
	lifecycleParameters,
	toggleSelection,
	transitionParameters,
} from '../../src/utils/bulkTransitionHelpers.js'

describe('emptySelection', () => {
	it('returns a selection with no column and no cases', () => {
		expect(emptySelection()).toEqual({ columnId: null, caseIds: [] })
	})
})

describe('toggleSelection', () => {
	it('selects a case into an empty selection', () => {
		const result = toggleSelection(emptySelection(), 'case-1', 'Received')
		expect(result).toEqual({ columnId: 'Received', caseIds: ['case-1'] })
	})

	it('adds a second case within the same column', () => {
		const first = toggleSelection(emptySelection(), 'case-1', 'Received')
		const second = toggleSelection(first, 'case-2', 'Received')
		expect(second).toEqual({
			columnId: 'Received',
			caseIds: ['case-1', 'case-2'],
		})
	})

	it('removes a case already selected within the same column', () => {
		const selection = { columnId: 'Received', caseIds: ['case-1', 'case-2'] }
		const result = toggleSelection(selection, 'case-1', 'Received')
		expect(result).toEqual({ columnId: 'Received', caseIds: ['case-2'] })
	})

	it('clears the column scope when the last case is deselected', () => {
		const selection = { columnId: 'Received', caseIds: ['case-1'] }
		const result = toggleSelection(selection, 'case-1', 'Received')
		expect(result).toEqual({ columnId: null, caseIds: [] })
	})

	it('resets the selection when a case is selected in a different column (cross-column reset)', () => {
		const selection = { columnId: 'Received', caseIds: ['case-1', 'case-2'] }
		const result = toggleSelection(selection, 'case-9', 'In handling')
		expect(result).toEqual({ columnId: 'In handling', caseIds: ['case-9'] })
	})

	it('compares case ids as strings so numeric/string mismatches still toggle correctly', () => {
		const selection = { columnId: 'Received', caseIds: [1, 2] }
		const result = toggleSelection(selection, '1', 'Received')
		expect(result).toEqual({ columnId: 'Received', caseIds: [2] })
	})

	it('tolerates a null/undefined selection as the starting state', () => {
		expect(toggleSelection(null, 'case-1', 'Received')).toEqual({
			columnId: 'Received',
			caseIds: ['case-1'],
		})
		expect(toggleSelection(undefined, 'case-1', 'Received')).toEqual({
			columnId: 'Received',
			caseIds: ['case-1'],
		})
	})
})

describe('isSelected', () => {
	it('returns true when the case id is in the selection', () => {
		const selection = { columnId: 'Received', caseIds: ['case-1', 'case-2'] }
		expect(isSelected(selection, 'case-1')).toBe(true)
	})

	it('returns false when the case id is not in the selection', () => {
		const selection = { columnId: 'Received', caseIds: ['case-1'] }
		expect(isSelected(selection, 'case-9')).toBe(false)
	})

	it('returns false for an empty or malformed selection', () => {
		expect(isSelected(emptySelection(), 'case-1')).toBe(false)
		expect(isSelected(null, 'case-1')).toBe(false)
		expect(isSelected({}, 'case-1')).toBe(false)
	})
})

describe('clearSelection', () => {
	it('returns an empty selection', () => {
		expect(clearSelection()).toEqual({ columnId: null, caseIds: [] })
	})
})

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
		expect(lifecycleParameters('suspend', { reason: 'Awaiting documents', days: '14' })).toEqual({
			gesture: 'suspend',
			reason: 'Awaiting documents',
			days: 14,
		})
	})

	it('sends the new deadline with an extend and no days', () => {
		expect(lifecycleParameters('extend', { reason: 'Complex case', newEndDate: '2026-12-01' })).toEqual({
			gesture: 'extend',
			reason: 'Complex case',
			newEndDate: '2026-12-01',
		})
	})

	it('sends neither with a resume', () => {
		expect(lifecycleParameters('resume', { reason: 'Documents arrived' })).toEqual({
			gesture: 'resume',
			reason: 'Documents arrived',
		})
	})

	it('trims the reason, so whitespace cannot pass for a justification', () => {
		expect(lifecycleParameters('resume', { reason: '   ' }).reason).toBe('')
	})
})
