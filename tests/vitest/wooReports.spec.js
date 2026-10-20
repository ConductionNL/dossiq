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
// CnCountTablesReport draws the mail parties report; its behaviour (tables,
// totals, empty state, refusal) is specified in nextcloud-vue. Here it is a
// stub that shows the case it was handed, so the page's wiring is asserted.
vi.mock('@conduction/nextcloud-vue', () => ({
	CnCountTablesReport: {
		name: 'CnCountTablesReport',
		props: ['url', 'objectId', 'lists', 'totals', 'emptyField', 'csvParam', 'emptyText', 'title', 'objectLabel', 'note'],
		template: '<input data-testid="case-mail-parties-case" :value="objectId">',
	},
}))

const offered = { throughput: true, parties: true }
vi.mock('@nextcloud/initial-state', () => ({
	loadState: (app, key, fallback) =>
		app === 'dossiq' && key === 'woo_reports' ? { ...offered } : fallback,
}))
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
const { mailPartiesReport } = await import('../../src/views/woo/mailPartiesReport.js')

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
		offered.throughput = true
		offered.parties = true
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

describe('The reports screen draws the halves on offer (REQ-WRR-001)', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		offered.throughput = true
		offered.parties = true
	})

	it('draws both halves when both are offered', () => {
		const wrapper = mount(WooReportsView)

		expect(wrapper.find('[data-testid="woo-throughput"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="case-mail-parties"]').exists()).toBe(true)
	})

	it('leaves the throughput half out for somebody outside the reader group', () => {
		offered.throughput = false
		const wrapper = mount(WooReportsView)

		expect(wrapper.find('[data-testid="woo-throughput"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="woo-throughput-show"]').exists()).toBe(
			false,
		)
		expect(wrapper.find('[data-testid="case-mail-parties"]').exists()).toBe(true)
	})

	it('leaves the parties half out while that report is off', () => {
		offered.parties = false
		const wrapper = mount(WooReportsView)

		expect(wrapper.find('[data-testid="woo-throughput"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="case-mail-parties"]').exists()).toBe(
			false,
		)
	})

	it('starts the parties half on the case the address names', () => {
		const wrapper = mount(WooReportsView, {
			global: { mocks: { $route: { query: { case: 'case-7' } } } },
		})

		expect(
			wrapper.find('[data-testid="case-mail-parties-case"]').element.value,
		).toBe('case-7')
	})
})

describe('Mail parties of a case (REQ-WRR-004)', () => {
	it('asks the case report endpoint, and its CSV form', () => {
		expect(mailPartiesReport.url).toBe('/apps/dossiq/api/cases/{id}/reports/mail-parties')
		expect(mailPartiesReport.csvParam).toBe('format=csv')
	})

	it('counts senders and recipients by address and domains by domain', () => {
		expect(mailPartiesReport.lists).toEqual([
			{ key: 'senders', label: 'Sender', nameField: 'address' },
			{ key: 'recipients', label: 'Recipient', nameField: 'address' },
			{ key: 'domains', label: 'Domain', nameField: 'domain' },
		])
	})

	it('reads the totals sentence and the empty state from the mail count', () => {
		expect(mailPartiesReport.totals).toContain('{mails}')
		expect(mailPartiesReport.totals).toContain('{unreadable}')
		expect(mailPartiesReport.emptyField).toBe('mails')
		expect(mailPartiesReport.emptyText).toBe('No mail collected on this case')
	})

	it('hands the report its config on the reports screen', () => {
		offered.parties = true
		const wrapper = mount(WooReportsView, {
			global: { mocks: { $route: { query: { case: 'case-7' } } } },
		})
		const report = wrapper.findComponent({ name: 'CnCountTablesReport' })

		expect(report.props('url')).toBe(mailPartiesReport.url)
		expect(report.props('lists')).toEqual(mailPartiesReport.lists)
		expect(report.props('objectId')).toBe('case-7')
	})
})
