// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Deadline cell renders the countdown AND the overdue state.
 *
 * The pure function is covered by `deadlineCountdown.spec.js`; what this
 * adds is the half a formatter could not do. The whole reason the column
 * declares a cell WIDGET rather than a `formatter` is the `is-overdue`
 * class — a formatter returns a string, and a string cannot say that a case
 * is past due. If the class stops being emitted the text still reads
 * correctly and the list quietly loses its only visual alarm, so the class
 * is asserted here rather than left to the e2e alone.
 *
 * The component imports nothing from `@nextcloud/vue`, so the real SFC
 * mounts — no stubs.
 *
 * @spec openspec/changes/one-case-list/specs/signalering-widgets/spec.md
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import DeadlineCountdownCell from '../../src/components/cells/DeadlineCountdownCell.vue'

/**
 * A date-only string N days from today.
 *
 * @param {number} offset Days ahead (negative for the past).
 * @return {string} `YYYY-MM-DD`.
 */
function day(offset) {
	const now = new Date()
	const d = new Date(now.getFullYear(), now.getMonth(), now.getDate() + offset)
	const month = String(d.getMonth() + 1).padStart(2, '0')
	const date = String(d.getDate()).padStart(2, '0')
	return `${d.getFullYear()}-${month}-${date}`
}

/**
 * Mount the cell for one deadline value.
 *
 * @param {string|null} value The row's deadline.
 * @return {object} The wrapper.
 */
const cell = (value) => mount(DeadlineCountdownCell, { props: { value } })

describe('DeadlineCountdownCell', () => {
	it('shows the days left and carries no overdue class', () => {
		const wrapper = cell(day(3))

		expect(wrapper.text()).toBe('3 days left')
		expect(wrapper.classes()).not.toContain('is-overdue')
	})

	it('shows the days overdue and carries the overdue class', () => {
		const wrapper = cell(day(-2))

		expect(wrapper.text()).toBe('2 days overdue')
		expect(wrapper.classes()).toContain('is-overdue')
	})

	it('renders an empty cell for a case with no deadline', () => {
		const wrapper = cell(null)

		expect(wrapper.text()).toBe('')
		expect(wrapper.classes()).toContain('deadline-countdown--empty')
		expect(wrapper.classes()).not.toContain('is-overdue')
	})

	it('keeps the exact date reachable as the cell title', () => {
		expect(cell('2026-12-01').attributes('title')).toBe('2026-12-01')
	})

	it('is addressable from an e2e by its test id', () => {
		expect(cell(day(3)).attributes('data-testid')).toBe('deadline-countdown')
	})
})
