// @vitest-environment jsdom
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * A workflow board column draws its first few cards and the rest behind
 * "Show N more" (DqWerkbord). The hidden cards stay in the column's list, so
 * nothing a drop, a selection or a keyboard move needs is taken away.
 *
 * @spec openspec/specs/dashboard/spec.md#requirement-req-dash-v1-006-workflow-board-view-v1
 */
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('@nextcloud/vue', () => ({
	NcAvatar: { name: 'NcAvatar', render: () => h('span') },
	NcLoadingIcon: { name: 'NcLoadingIcon', render: () => h('span') },
	NcCheckboxRadioSwitch: {
		name: 'NcCheckboxRadioSwitch',
		render: () => h('input'),
	},
}))

vi.mock('vue-draggable-plus', () => ({
	VueDraggable: {
		name: 'VueDraggable',
		props: ['modelValue'],
		render() {
			return h(
				'div',
				{ 'data-testid': 'draggable' },
				this.$slots.default ? this.$slots.default() : [],
			)
		},
	},
}))

const BoardColumn = (await import('../../src/views/workflow-board/BoardColumn.vue'))
	.default

/**
 * Mount a column over `count` cases.
 *
 * @param {number} count How many cases the column holds.
 * @param {object} [props] Extra props.
 * @return {object} The wrapper.
 */
function column(count, props = {}) {
	const cases = Array.from({ length: count }, (_, i) => ({
		id: `case-${i}`,
		identifier: `2026-00${10 + i}`,
		title: `Case ${i}`,
	}))
	return mount(BoardColumn, {
		props: {
			statusType: { id: 'st-1', name: 'Ontvangen' },
			cases,
			...props,
		},
	})
}

/**
 * The cards a reader sees.
 *
 * @param {object} wrapper The column.
 * @return {number} The visible cards.
 */
function visibleCards(wrapper) {
	// v-show's own switch. jsdom's isVisible() keeps a card hidden after v-show
	// clears its display, so the check reads the display the directive wrote.
	return wrapper
		.findAll('.case-card')
		.filter((card) => card.element.style.display !== 'none').length
}

describe('BoardColumn card limit', () => {
	it('draws three cards and offers the rest behind "Show N more"', async () => {
		const wrapper = column(8)
		expect(wrapper.findAll('.case-card')).toHaveLength(8)
		expect(visibleCards(wrapper)).toBe(3)
		const more = wrapper.find('[data-testid="board-column-more"]')
		expect(more.text()).toBe('Show 5 more')
		expect(more.attributes('aria-expanded')).toBe('false')
		// The count is the column's total, not the cards drawn.
		expect(wrapper.find('[data-testid="board-column-count"]').text()).toContain(
			'8',
		)

		await more.trigger('click')
		// The cards sit in the draggable list's slot, which re-renders a tick later.
		await flushPromises()
		expect(visibleCards(wrapper)).toBe(8)
		expect(more.text()).toBe('Show less')
		expect(more.attributes('aria-expanded')).toBe('true')

		await more.trigger('click')
		await flushPromises()
		expect(visibleCards(wrapper)).toBe(3)
	})

	it('offers no button when every card fits', () => {
		const wrapper = column(3)
		expect(visibleCards(wrapper)).toBe(3)
		expect(wrapper.find('[data-testid="board-column-more"]').exists()).toBe(
			false,
		)
	})

	it('draws every card with a limit of 0', () => {
		const wrapper = column(6, { cardLimit: 0 })
		expect(visibleCards(wrapper)).toBe(6)
		expect(wrapper.find('[data-testid="board-column-more"]').exists()).toBe(
			false,
		)
	})

	it('keeps every case in the draggable list, hidden or not', () => {
		const wrapper = column(7)
		const list = wrapper.findComponent({ name: 'VueDraggable' })
		expect(list.props('modelValue')).toHaveLength(7)
	})
})
