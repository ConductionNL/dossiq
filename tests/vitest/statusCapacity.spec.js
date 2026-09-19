// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The board column shows the count against the limit, and says no first.
 *
 * THE ASSERTION THAT CARRIES THE REQUIREMENT is "a merged column shows no
 * limit". It is not a nicety: a board column merges every non-final status
 * type sharing a NAME, across every case type, and a capacity is authored on
 * ONE status type. A header reading "9 of 12" beside an engine that refuses at
 * four is worse than no number, because the number is exactly what a handler
 * would plan against. A test that only checked the single-status column would
 * pass against a component that summed two limits, or took the first.
 *
 * The other half is that the refusal is a REFUSAL. Gap register row Q3.22 was
 * measured: Kanboard colours a full column's header and lets the card through.
 * A test on the colour alone would pass against exactly that.
 *
 * @spec openspec/changes/status-capacity-limit/specs/status-transition-engine/spec.md
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { h } from 'vue'
import { capacityRefusal, countInStatus } from '../../src/utils/statusCapacity.js'

const BoardColumn = (await import('../../src/views/workflow-board/BoardColumn.vue'))
	.default

/** Stubs for the library and child components the column mounts. */
const stubs = {
	NcLoadingIcon: { name: 'NcLoadingIcon', render: () => h('span') },
	CaseCard: { name: 'CaseCard', render: () => h('div', { class: 'case-card' }) },
}

/**
 * Mount a column.
 *
 * @param {object} statusType The column's status type.
 * @param {number} howMany    How many cases sit in it.
 * @return {object} The mounted wrapper.
 */
function open(statusType, howMany) {
	const cases = []
	for (let i = 0; i < howMany; i++) {
		cases.push({ id: `case-${i}`, status: statusType.id })
	}

	return mount(BoardColumn, {
		props: { statusType, cases },
		global: { stubs },
	})
}

/**
 * The header's count element.
 *
 * @param {object} wrapper The mounted wrapper.
 * @return {object} The locator.
 */
function count(wrapper) {
	return wrapper.find('[data-testid="board-column-count"]')
}

describe('The board column header', () => {
	it('shows the count against the limit when the status carries one', () => {
		const wrapper = open(
			{ id: 'in-behandeling', name: 'In behandeling', capacity: 12 },
			9,
		)

		expect(count(wrapper).text()).toBe('9 / 12')
	})

	it('shows the bare count when the status carries no limit', () => {
		const wrapper = open({ id: 'ontvangen', name: 'Ontvangen' }, 40)

		// Every status shipped today is this one, and it must read exactly as
		// it did before.
		expect(count(wrapper).text()).toBe('40')
	})

	it('shows no limit on a column that merged more than one status', () => {
		// 🔴 THE ASSERTION THIS FILE EXISTS FOR. The board sets `capacity` to
		// null when a second status type merges into the column, because one
		// number over two different limits is wrong in both directions.
		const wrapper = open(
			{
				id: 'In behandeling',
				name: 'In behandeling',
				capacity: null,
				merged: true,
			},
			9,
		)

		expect(count(wrapper).text()).toBe('9')
	})

	it('says full in the number, not only in a colour', () => {
		// A colour alone says nothing to a reader who cannot see it
		// (WCAG 2.2 SC 1.4.1). "12 / 12" reads as full in any palette.
		const wrapper = open({ id: 's', name: 'In behandeling', capacity: 12 }, 12)

		expect(count(wrapper).text()).toBe('12 / 12')
		expect(count(wrapper).classes()).toContain('board-column__count--full')
	})

	it('marks a column full at the limit and not before', () => {
		expect(
			count(open({ id: 's', name: 'S', capacity: 3 }, 2)).classes(),
		).not.toContain('board-column__count--full')
		expect(
			count(open({ id: 's', name: 'S', capacity: 3 }, 3)).classes(),
		).toContain('board-column__count--full')
	})

	it('treats a zero capacity as no limit', () => {
		const wrapper = open({ id: 's', name: 'S', capacity: 0 }, 5)

		expect(count(wrapper).text()).toBe('5')
		expect(count(wrapper).classes()).not.toContain('board-column__count--full')
	})
})

describe('The drop refusal', () => {
	/**
	 * A translator that substitutes the named placeholders, so the sentence is
	 * assertable rather than the key.
	 *
	 * @param {string} text The source string.
	 * @param {object} params The placeholder values.
	 * @return {string} The substituted sentence.
	 */
	const translate = (text, params = {}) =>
		text.replace(/\{(\w+)\}/g, (_, key) => String(params[key] ?? ''))

	/**
	 * Cases in one status.
	 *
	 * @param {string} statusId The status id.
	 * @param {number} howMany How many.
	 * @return {Array} The rows.
	 */
	const sitting = (statusId, howMany) =>
		new Array(howMany)
			.fill(0)
			.map((_, i) => ({ id: `${statusId}-${i}`, status: statusId }))

	it('refuses a card onto a status that is at its limit', () => {
		const status = { id: 'in-behandeling', name: 'In behandeling', capacity: 3 }
		const refusal = capacityRefusal(
			status,
			sitting('in-behandeling', 3),
			translate,
		)

		expect(refusal).toContain('In behandeling')
		expect(refusal).toContain('3')
	})

	it('lets the card through while there is room', () => {
		const status = { id: 'in-behandeling', name: 'In behandeling', capacity: 3 }

		expect(
			capacityRefusal(status, sitting('in-behandeling', 2), translate),
		).toBe('')
	})

	it('counts only the cases in the concrete target status', () => {
		// 🔴 THE MERGED COLUMN, FROM THE OTHER SIDE. The column holds six cases
		// and four of them belong to another case type's status of the same
		// name. Counting the column's length would refuse a move into a status
		// that has room.
		const column = [
			...sitting('behandeling-a', 2),
			...sitting('behandeling-b', 4),
		]

		expect(
			capacityRefusal(
				{ id: 'behandeling-a', name: 'In behandeling', capacity: 4 },
				column,
				translate,
			),
		).toBe('')
		expect(
			capacityRefusal(
				{ id: 'behandeling-b', name: 'In behandeling', capacity: 4 },
				column,
				translate,
			),
		).not.toBe('')
	})

	it('never refuses a move into a status with no limit', () => {
		expect(
			capacityRefusal(
				{ id: 'ontvangen', name: 'Ontvangen' },
				sitting('ontvangen', 50),
				translate,
			),
		).toBe('')
	})

	it('never refuses a move into a final status', () => {
		// A case type that could not close its thirteenth case would be worse
		// off than one with no limit at all.
		expect(
			capacityRefusal(
				{
					id: 'afgehandeld',
					name: 'Afgehandeld',
					capacity: 1,
					isFinal: true,
				},
				sitting('afgehandeld', 9),
				translate,
			),
		).toBe('')
	})

	it('counts nothing towards a status the board loaded no cases for', () => {
		// The control for every "" above: an empty answer must be reachable
		// for a reason other than the helper never counting anything.
		expect(countInStatus(sitting('elsewhere', 5), 'in-behandeling')).toBe(0)
		expect(countInStatus(sitting('in-behandeling', 5), 'in-behandeling')).toBe(5)
	})
})
