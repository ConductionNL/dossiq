/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Reading a case type's own queue threshold override.
 *
 * @spec openspec/specs/case-types/spec.md
 */

import { describe, expect, it } from 'vitest'
import { readThresholdOverride } from '../../src/utils/queueUrgencySettings.js'

describe('readThresholdOverride', () => {
	it('reads empty as no override', () => {
		expect(readThresholdOverride('', 60)).toEqual({
			value: undefined,
			error: '',
		})
		expect(readThresholdOverride(undefined, 60)).toEqual({
			value: undefined,
			error: '',
		})
	})

	it('reads a whole number in bounds', () => {
		expect(readThresholdOverride('10', 60)).toEqual({ value: 10, error: '' })
	})

	it('refuses a number out of bounds', () => {
		expect(readThresholdOverride('61', 60).error).toBe(
			'Enter a whole number from 0 to 60.',
		)
	})
})

