// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The labels beside a seeded object and on a connection's note card, for the
 * inputs the screen-level specs leave out.
 *
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */
import { describe, expect, it } from 'vitest'
import { connectionLabel, shippedLabel } from '../../src/utils/starterStates.js'

describe('shippedLabel', () => {
	it.each([
		[null, 'Yours'],
		[{}, 'Yours'],
		[{ state: '' }, 'Yours'],
		[{ state: 'something else' }, 'Yours'],
		[{ state: 'toString' }, 'Yours'],
		[{ state: 'shipped' }, 'Shipped, unchanged'],
		[{ state: 'changed' }, 'Shipped, changed here'],
		[{ state: 'removed' }, 'Shipped, removed here'],
	])('labels %j as "%s"', (row, label) => {
		expect(shippedLabel(row)).toBe(label)
	})
})

describe('connectionLabel', () => {
	const notTested = { state: 'not_tested', label: 'Not tested', type: 'warning', measuredAt: '' }

	it.each([[undefined], [{}], [{ state: '' }], [{ state: 'not_tested', measuredAt: 'x' }]])(
		'reads %j as not tested',
		(result) => {
			expect(connectionLabel(result)).toEqual(notTested)
		},
	)

	it('reads a reachable endpoint without a moment', () => {
		expect(connectionLabel({ state: 'reachable', status: 204 })).toEqual({
			state: 'reachable',
			label: 'Answered 204',
			type: 'success',
			measuredAt: '',
		})
	})

	it('reads any other state as failed, with its reason and moment', () => {
		expect(connectionLabel({ state: 'timeout', reason: 'Too slow', measuredAt: '2026-09-15' })).toEqual({
			state: 'failed',
			label: 'Too slow',
			type: 'error',
			measuredAt: '2026-09-15',
		})
		expect(connectionLabel({ state: 'failed', reason: '' })).toEqual({
			state: 'failed',
			label: 'The endpoint did not answer',
			type: 'error',
			measuredAt: '',
		})
	})
})
