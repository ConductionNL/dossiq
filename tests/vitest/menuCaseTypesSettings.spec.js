// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Case types in my menu, the section of Nextcloud's personal settings that
 * decides what stands under My case types in the sidebar. Adding, removing and
 * moving each save at once, the keyboard can move a row, and every move is
 * said out loud to a screen reader.
 *
 * @spec openspec/changes/case-types-in-my-menu/specs/case-type-navigation/spec.md#REQ-CTN-005
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		emits: ['click'],
		render() {
			return h(
				'button',
				{ onClick: () => this.$emit('click') },
				this.$slots.default ? this.$slots.default() : [],
			)
		},
	},
	NcSelect: {
		name: 'NcSelect',
		props: {
			options: { type: Array, default: () => [] },
			inputLabel: { type: String, default: '' },
		},
		emits: ['update:modelValue'],
		render() {
			return h('select', { class: 'NcSelect', 'aria-label': this.inputLabel })
		},
	},
}))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) =>
		text.replace(/{(\w+)}/g, (_, key) => String(vars[key] ?? `{${key}}`)),
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), put: vi.fn() } }))

const { default: MenuCaseTypesSettings } =
	await import('../../src/views/settings/MenuCaseTypesSettings.vue')

const WOO = { id: 'w', title: 'Woo-verzoek' }
const BEZWAAR = { id: 'b', title: 'Bezwaar' }
const KLACHT = { id: 'k', title: 'Klacht' }

/**
 * Mount the section over a server that holds `chosen` and offers `available`.
 *
 * @param {Array} chosen The stored list.
 * @param {Array} available The visible case types.
 * @return {Promise<object>} The wrapper, after the first read.
 */
async function mountWith(chosen, available) {
	axios.get.mockResolvedValue({ data: { chosen, available } })
	axios.put.mockImplementation(async (url, { ids }) => ({
		data: {
			chosen: ids.map((id) =>
				available.find((caseType) => caseType.id === id),
			),
		},
	}))
	const wrapper = mount(MenuCaseTypesSettings, { attachTo: document.body })
	await flushPromises()
	return wrapper
}

/**
 * The visible row texts, in order.
 *
 * @param {object} wrapper The mounted section.
 * @return {Array<string>} The row texts.
 */
function rows(wrapper) {
	return wrapper
		.findAll('[data-testid^="menu-case-types-row-"]')
		.map((row) => row.text())
}

describe('Case types in my menu', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.put.mockReset()
	})

	it('lists the chosen case types in order and offers only the others', async () => {
		const wrapper = await mountWith([BEZWAAR, WOO], [BEZWAAR, KLACHT, WOO])

		expect(rows(wrapper)).toEqual(['Bezwaar', 'Woo-verzoek'])
		expect(wrapper.findComponent({ name: 'NcSelect' }).props('options')).toEqual(
			[KLACHT],
		)
		expect(wrapper.find('.NcSelect').attributes('aria-label')).toBe(
			'Add case type',
		)
	})

	it('adds a case type at the bottom and saves at once', async () => {
		const wrapper = await mountWith([WOO], [BEZWAAR, WOO])

		wrapper
			.findComponent({ name: 'NcSelect' })
			.vm.$emit('update:modelValue', BEZWAAR)
		await flushPromises()

		expect(axios.put).toHaveBeenCalledWith('/apps/dossiq/api/menu-case-types', {
			ids: ['w', 'b'],
		})
		expect(rows(wrapper)).toEqual(['Woo-verzoek', 'Bezwaar'])
		expect(wrapper.find('[data-testid="menu-case-types-status"]').text()).toBe(
			'Bezwaar added to your menu',
		)
	})

	it('moves a row with the arrow keys, names its place and announces the move', async () => {
		const wrapper = await mountWith(
			[WOO, BEZWAAR, KLACHT],
			[BEZWAAR, KLACHT, WOO],
		)

		const handles = wrapper.findAll('[data-testid="menu-case-types-move"]')
		expect(handles[1].attributes('aria-label')).toBe('Bezwaar, move, now 2 of 3')

		await handles[1].trigger('keydown', { key: 'ArrowUp' })
		await flushPromises()

		expect(axios.put).toHaveBeenCalledWith('/apps/dossiq/api/menu-case-types', {
			ids: ['b', 'w', 'k'],
		})
		expect(rows(wrapper)).toEqual(['Bezwaar', 'Woo-verzoek', 'Klacht'])
		const status = wrapper.find('[data-testid="menu-case-types-status"]')
		expect(status.attributes('aria-live')).toBe('polite')
		expect(status.text()).toBe('Bezwaar is now 1 of 3')
		expect(document.activeElement).toBe(
			wrapper.findAll('[data-testid="menu-case-types-move"]')[0].element,
		)
		wrapper.unmount()
	})

	it('does not move the first row up', async () => {
		const wrapper = await mountWith([WOO, BEZWAAR], [BEZWAAR, WOO])

		await wrapper
			.findAll('[data-testid="menu-case-types-move"]')[0]
			.trigger('keydown', { key: 'ArrowUp' })
		await flushPromises()

		expect(axios.put).not.toHaveBeenCalled()
	})

	it('removes a row and saves', async () => {
		const wrapper = await mountWith([WOO, BEZWAAR], [BEZWAAR, WOO])

		await wrapper
			.findAll('[data-testid="menu-case-types-remove"]')[0]
			.trigger('click')
		await flushPromises()

		expect(axios.put).toHaveBeenCalledWith('/apps/dossiq/api/menu-case-types', {
			ids: ['b'],
		})
		expect(rows(wrapper)).toEqual(['Bezwaar'])
	})

	it('puts the list back when a save fails, and says so', async () => {
		const wrapper = await mountWith([WOO], [BEZWAAR, WOO])
		axios.put.mockRejectedValue(new Error('500'))

		await wrapper
			.findAll('[data-testid="menu-case-types-remove"]')[0]
			.trigger('click')
		await flushPromises()

		expect(rows(wrapper)).toEqual(['Woo-verzoek'])
		expect(wrapper.find('[data-testid="menu-case-types-status"]').text()).toBe(
			'Your change was not saved. Try again.',
		)
	})
})
