// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Woo review reports: the admin switches show the server's refusal, and
 * the throughput screen shows the server's rows, or its refusal and no rows.
 *
 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

/**
 * A switch stub rendering a real checkbox with its modelValue.
 *
 * @return {object} The stub.
 */
function switchStub() {
	return {
		name: 'NcCheckboxRadioSwitch',
		props: { modelValue: { type: Boolean, default: false } },
		emits: ['update:modelValue'],
		render() {
			return h('input', {
				type: 'checkbox',
				checked: this.modelValue === true,
				'data-testid': this.$attrs['data-testid'],
				onChange: (event) =>
					this.$emit('update:modelValue', event.target.checked),
			})
		},
	}
}

/**
 * A text field stub that keeps its value.
 *
 * @return {object} The stub.
 */
function fieldStub() {
	return {
		name: 'NcTextField',
		props: { modelValue: { type: String, default: '' } },
		emits: ['update:modelValue'],
		render() {
			return h('input', {
				value: this.modelValue,
				'data-testid': this.$attrs['data-testid'],
				onInput: (event) =>
					this.$emit('update:modelValue', event.target.value),
			})
		},
	}
}

/**
 * A stub rendering its slot in an element.
 *
 * @param {string} name The component name.
 * @param {string} tag The element.
 * @return {object} The stub.
 */
function boxStub(name, tag = 'div') {
	return {
		name,
		inheritAttrs: true,
		render() {
			return h(
				tag,
				{ class: name },
				this.$slots.default ? this.$slots.default() : [],
			)
		},
	}
}

vi.mock('@nextcloud/vue', () => ({
	NcAppContent: boxStub('NcAppContent'),
	NcButton: boxStub('NcButton', 'button'),
	NcCheckboxRadioSwitch: switchStub(),
	NcEmptyContent: boxStub('NcEmptyContent'),
	NcLoadingIcon: boxStub('NcLoadingIcon', 'span'),
	NcNoteCard: boxStub('NcNoteCard'),
	NcTextField: fieldStub(),
}))

const stored = { config: {} }
vi.mock('../../src/store/modules/settings.js', () => ({
	useSettingsStore: () => ({
		isInitialized: true,
		get getConfig() {
			return stored.config
		},
	}),
}))

/**
 * Flip the throughput switch on the way the real switch does: by emitting its model update.
 *
 * @param {object} wrapper The mounted tab.
 * @return {Promise<void>}
 */
async function flipThroughputOn(wrapper) {
	const switches = wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
	await switches[0].vm.$emit('update:modelValue', true)
}

const tab = await import('../../src/views/settings/tabs/WooReportsSettingsTab.vue')
const WooReportsSettingsTab = tab.default
const { isSwitchedOn } = tab
const view = await import('../../src/views/woo/WooReportsView.vue')
const WooReportsView = view.default
const { throughputUrl } = view

describe('Woo report switches (REQ-WRR-001)', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		stored.config = {}
	})

	it('reads only an explicit yes as on', () => {
		expect(['', 'false', '0', undefined, null].map(isSwitchedOn)).toEqual([
			false,
			false,
			false,
			false,
			false,
		])
		expect(['true', '1', 'on', true].map(isSwitchedOn)).toEqual([
			true,
			true,
			true,
			true,
		])
	})

	it('draws both switches off on a fresh install', async () => {
		const wrapper = mount(WooReportsSettingsTab)
		await flushPromises()
		expect(
			wrapper.find('[data-testid="woo-reports-throughput"]').element.checked,
		).toBe(false)
		expect(
			wrapper.find('[data-testid="woo-reports-parties"]').element.checked,
		).toBe(false)
	})

	it('shows the refusal and leaves the switch off when no reader group is named', async () => {
		axios.post.mockRejectedValue({
			response: {
				status: 422,
				data: {
					message:
						'Name an existing reader group before switching the throughput report on.',
				},
			},
		})
		const wrapper = mount(WooReportsSettingsTab)
		await flushPromises()

		await flipThroughputOn(wrapper)
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(
			expect.stringContaining('/apps/dossiq/api/settings'),
			{
				wooReviewerThroughputReport: 'true',
				wooReviewerThroughputReaders: '',
			},
		)
		expect(wrapper.text()).toContain('Name an existing reader group')
		expect(
			wrapper.find('[data-testid="woo-reports-throughput"]').element.checked,
		).toBe(false)
	})

	it('sends the reader group with the switch and draws what the server stored', async () => {
		axios.post.mockResolvedValue({
			data: {
				config: {
					wooReviewerThroughputReport: 'true',
					wooReviewerThroughputReaders: 'woo-leiding',
				},
			},
		})
		const wrapper = mount(WooReportsSettingsTab)
		await flushPromises()
		await wrapper
			.find('[data-testid="woo-reports-readers"]')
			.setValue('woo-leiding')

		await flipThroughputOn(wrapper)
		await flushPromises()

		expect(axios.post.mock.calls[0][1]).toEqual({
			wooReviewerThroughputReport: 'true',
			wooReviewerThroughputReaders: 'woo-leiding',
		})
		expect(
			wrapper.find('[data-testid="woo-reports-throughput"]').element.checked,
		).toBe(true)
	})
})

describe('Woo throughput screen (REQ-WRR-002)', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('asks for the period, and for the CSV of the same period', () => {
		expect(throughputUrl('2026-11-01', '2026-11-30')).toContain(
			'/apps/dossiq/api/woo/reports/throughput?from=2026-11-01&to=2026-11-30',
		)
		expect(throughputUrl('2026-11-01', '2026-11-30', true)).toContain(
			'&format=csv',
		)
	})

	it('shows one table row per reviewer per day', async () => {
		axios.get.mockResolvedValue({
			data: {
				rows: [
					{
						reviewer: 'a',
						displayName: 'Anna de Wit',
						day: '2026-11-10',
						openbaar: 3,
						deels_openbaar: 0,
						niet_openbaar: 1,
						total: 4,
					},
					{
						reviewer: 'b',
						displayName: 'Bram',
						day: '2026-11-10',
						openbaar: 0,
						deels_openbaar: 2,
						niet_openbaar: 0,
						total: 2,
					},
				],
				truncated: false,
			},
		})
		const wrapper = mount(WooReportsView)
		await wrapper.find('[data-testid="woo-throughput-show"]').trigger('click')
		await flushPromises()

		const rows = wrapper.findAll('tbody tr')
		expect(rows).toHaveLength(2)
		expect(rows[0].text()).toContain('Anna de Wit')
		expect(rows[0].findAll('td').map((cell) => cell.text())).toEqual([
			'Anna de Wit',
			'2026-11-10',
			'3',
			'0',
			'1',
			'4',
		])
	})

	it('shows the refusal and no rows when the server refuses', async () => {
		axios.get.mockRejectedValue({
			response: {
				status: 403,
				data: {
					message: 'Your organisation has not switched this report on.',
				},
			},
		})
		const wrapper = mount(WooReportsView)
		await wrapper.find('[data-testid="woo-throughput-show"]').trigger('click')
		await flushPromises()

		expect(wrapper.text()).toContain(
			'Your organisation has not switched this report on.',
		)
		expect(wrapper.findAll('tbody tr')).toHaveLength(0)
	})
})
