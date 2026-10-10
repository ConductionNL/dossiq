/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The queue urgency form: defaults, bounds, and that a refused value sends
 * nothing.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

import { describe, expect, it } from 'vitest'
import {
	buildQueueUrgencyPayload,
	initialQueueUrgencyValues,
	QUEUE_URGENCY_FIELDS,
} from '../../src/utils/queueUrgencySettings.js'

describe('initialQueueUrgencyValues', () => {
	it('starts from the defaults when nothing was provided', () => {
		expect(initialQueueUrgencyValues({})).toEqual({
			criticalDays: 3,
			warningDays: 7,
			priorityWeight: 10,
			idleWeight: 0.5,
		})
	})

	it('reads the stored values', () => {
		expect(
			initialQueueUrgencyValues({
				criticalDays: 5,
				warningDays: 10,
				priorityWeight: 20,
				idleWeight: 1,
			}),
		).toEqual({
			criticalDays: 5,
			warningDays: 10,
			priorityWeight: 20,
			idleWeight: 1,
		})
	})
})

describe('buildQueueUrgencyPayload', () => {
	it('writes the four app config keys as strings, accepting a decimal comma', () => {
		const { errors, payload } = buildQueueUrgencyPayload({
			criticalDays: '5',
			warningDays: 10,
			priorityWeight: '12',
			idleWeight: '0,75',
		})
		expect(errors).toEqual({})
		expect(payload).toEqual({
			queue_critical_days: '5',
			queue_warning_days: '10',
			queue_priority_weight: '12',
			queue_idle_weight: '0.75',
		})
	})

	// @spec openspec/specs/admin-settings/spec.md#scenario-an-out-of-bounds-weight-is-refused-in-the-form
	it('refuses a weight out of bounds, names the bounds and sends nothing', () => {
		const { errors, payload } = buildQueueUrgencyPayload({
			criticalDays: 3,
			warningDays: 7,
			priorityWeight: 80,
			idleWeight: 0.5,
		})
		expect(payload).toBeNull()
		expect(errors).toEqual({ priorityWeight: 'Enter a number from 0 to 50.' })
	})

	it('refuses a fraction of a day and an empty field', () => {
		const { errors } = buildQueueUrgencyPayload({
			criticalDays: 2.5,
			warningDays: '',
			priorityWeight: 10,
			idleWeight: 0.5,
		})
		expect(errors.criticalDays).toBe('Enter a whole number from 0 to 60.')
		expect(errors.warningDays).toBe('Enter a whole number from 0 to 120.')
	})

	it('uses the server bounds', () => {
		expect(QUEUE_URGENCY_FIELDS.map((f) => [f.key, f.max])).toEqual([
			['queue_critical_days', 60],
			['queue_warning_days', 120],
			['queue_priority_weight', 50],
			['queue_idle_weight', 1.5],
		])
	})
})
