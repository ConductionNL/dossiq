/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The queue urgency bounds: the server's, shared by the admin section
 * (adminFields.js) and the case type override, and a value outside them is
 * refused with the bounds named.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

import { describe, expect, it } from 'vitest'
import {
	checkQueueValue,
	QUEUE_URGENCY_FIELDS,
} from '../../src/utils/queueUrgencySettings.js'

const spec = (field) => QUEUE_URGENCY_FIELDS.find((entry) => entry.field === field)

describe('checkQueueValue', () => {
	it('reads a number, accepting a decimal comma', () => {
		expect(checkQueueValue('5', spec('criticalDays'))).toEqual({ value: 5, error: '' })
		expect(checkQueueValue('0,75', spec('idleWeight'))).toEqual({ value: 0.75, error: '' })
	})

	// @spec openspec/specs/admin-settings/spec.md#scenario-an-out-of-bounds-weight-is-refused-in-the-form
	it('refuses a weight out of bounds and names the bounds', () => {
		expect(checkQueueValue(80, spec('priorityWeight')).error).toBe('Enter a number from 0 to 50.')
	})

	it('refuses a fraction of a day and an empty field', () => {
		expect(checkQueueValue(2.5, spec('criticalDays')).error).toBe('Enter a whole number from 0 to 60.')
		expect(checkQueueValue('', spec('warningDays')).error).toBe('Enter a whole number from 0 to 120.')
	})
})

describe('the bounds', () => {
	it('are the server\'s', () => {
		expect(QUEUE_URGENCY_FIELDS.map((f) => [f.key, f.max])).toEqual([
			['queue_critical_days', 60],
			['queue_warning_days', 120],
			['queue_priority_weight', 50],
			['queue_idle_weight', 1.5],
		])
	})
})
