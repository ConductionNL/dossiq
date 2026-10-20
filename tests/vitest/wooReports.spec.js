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
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
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
const { mailPartiesReport } = await import('../../src/views/reports/mailPartiesReport.js')
const { default: MailPartiesReportWidget } = await import('../../src/views/reports/MailPartiesReportWidget.vue')
const reportsPage = JSON.parse(
	readFileSync(resolve(dirname(fileURLToPath(import.meta.url)), '../../src/manifest.d/86-woo-reports.json'), 'utf8'),
).pages[0]
const pageWidget = (id) => reportsPage.config.widgets.find((w) => w.id === id)

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

describe('Woo throughput on the reports page (REQ-WRR-002)', () => {
	const table = pageWidget('throughput')
	const source = table.content.endpointSource

	it('reads the period the page shows, from the throughput endpoint', () => {
		expect(table.type).toBe('object-table')
		expect(source).toMatchObject({
			url: '/apps/dossiq/api/woo/reports/throughput',
			params: { from: '@workspace.dateFrom', to: '@workspace.dateTo' },
			responsePath: 'rows',
		})
		expect(reportsPage.type).toBe('dashboard')
		expect(reportsPage.config.dateRange.enabled).toBe(true)
	})

	it('reads only when asked, so opening the page records nothing on the audit trail', () => {
		expect(source.loadOn).toBe('request')
		expect(source.requestLabel).toBe('Show')
		expect(source.requestHint).toContain('it is recorded')
	})

	it('shows one row per reviewer per day with the three verdicts and the total', () => {
		expect(table.content.columns.map((c) => c.key)).toEqual(['displayName', 'day', 'openbaar', 'deels_openbaar', 'niet_openbaar', 'total'])
		expect(table.content.emptyText).toBe('No assessments in this period')
		expect(source.truncatedText).toBe('This period holds more assessments than one report reads. Choose a shorter period.')
	})

	it('downloads the CSV of the same period from the header', () => {
		const csv = reportsPage.config.headerActions.find((a) => a.id === 'throughput-csv')
		expect(csv.href).toBe('/apps/dossiq/api/woo/reports/throughput?format=csv&from=@workspace.dateFrom&to=@workspace.dateTo')
	})
})

describe('The reports page draws the halves on offer (REQ-WRR-001)', () => {
	it('shows the throughput table and its CSV only while the throughput report is offered', () => {
		const gate = { endpoint: '/apps/dossiq/api/reports/offered', field: 'throughput', op: 'eq', value: true }
		expect(pageWidget('throughput').visibleWhen).toEqual(gate)
		expect(reportsPage.config.headerActions[0].visibleWhen).toEqual(gate)
	})

	it('shows the mail parties report only while it is offered, through its registered widget', () => {
		expect(pageWidget('mail-parties').visibleWhen).toMatchObject({ field: 'parties', value: true })
		expect(reportsPage.slots['widget-mail-parties']).toBe('MailPartiesReportWidget')
		expect(reportsPage.config.layout.map((l) => l.widgetId)).toEqual(['throughput', 'mail-parties'])
	})

	it('starts the parties report on the case the address names', () => {
		const wrapper = mount(MailPartiesReportWidget, {
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

	it('hands the report its config', () => {
		const wrapper = mount(MailPartiesReportWidget, {
			global: { mocks: { $route: { query: { case: 'case-7' } } } },
		})
		const report = wrapper.findComponent({ name: 'CnCountTablesReport' })

		expect(report.props('url')).toBe(mailPartiesReport.url)
		expect(report.props('lists')).toEqual(mailPartiesReport.lists)
		expect(report.props('objectId')).toBe('case-7')
	})
})
