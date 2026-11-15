// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The StUF audit log as mounted: it reads the messages with the filters it
 * shows, draws a dash for a missing HTTP status or duration, opens a row in
 * the envelope dialog, says so when the read fails, and exports the rows it
 * holds as CSV.
 *
 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-outbound-audit-log
 */

import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('@nextcloud/dialogs', () => ({
	showError: vi.fn(),
}))

/**
 * A stub that renders its default slot inside a tag.
 *
 * @param {string} name The component name.
 * @param {string} tag  The element.
 * @return {object} The stub.
 */
function stub(name, tag = 'div') {
	return {
		name,
		props: ['name', 'disabled'],
		emits: ['click'],
		render() {
			return h(tag, { class: name, onClick: () => this.$emit('click') }, [this.name, this.$slots.default ? this.$slots.default() : null])
		},
	}
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton', 'button'),
	NcEmptyContent: stub('NcEmptyContent'),
	NcLoadingIcon: stub('NcLoadingIcon', 'span'),
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'options', 'inputLabel'],
		emits: ['update:modelValue'],
		render: () => h('select'),
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		render: () => h('input'),
	},
}))

vi.mock('vue-material-design-icons/EmailSearchOutline.vue', () => ({ default: { render: () => h('span') } }))

vi.mock('../../src/dialogs/StufEnvelopeDialog.vue', () => ({
	default: {
		name: 'StufEnvelopeDialog',
		props: ['row'],
		emits: ['close'],
		render() {
			return h('div', { class: 'StufEnvelopeDialog' }, this.row.referenceNumber)
		},
	},
}))

const StufAuditLog = (await import('../../src/views/settings/StufAuditLog.vue')).default

const ROWS = [
	{ sentOn: '2026-11-01T10:00:00Z', direction: 'out', messageKind: 'Lk01', role: 'client', status: 'bevestigd', httpStatus: 200, durationMs: 120, referenceNumber: 'ref-1', caseIdentification: 'Z-1' },
	{ sentOn: '2026-11-01T11:00:00Z', direction: 'out', messageKind: 'Lk02', role: 'client', status: null, httpStatus: null, durationMs: 0, referenceNumber: 'ref "2"', caseIdentification: null },
]

/**
 * Mount the log against a messages response and let it settle.
 *
 * @param {object} data The body the GET answers.
 * @return {Promise<object>} The wrapper.
 */
async function open(data = { items: ROWS }) {
	axios.get.mockResolvedValue({ data })
	const wrapper = mount(StufAuditLog)
	await flushPromises()
	return wrapper
}

describe('StufAuditLog mounted', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('reads the messages with the current filters and a limit', async () => {
		await open()

		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/dossiq/api/stuf/messages', {
			params: { endpointId: '', messageKind: '', status: '', limit: 100 },
		})
	})

	it('draws one row per message, with a dash for a missing HTTP status or duration', async () => {
		const wrapper = await open()

		const rows = wrapper.findAll('[data-testid="stuf-audit-log-table"] tbody tr')
		expect(rows).toHaveLength(2)
		const second = rows[1].findAll('td').map((td) => td.text())
		expect(second.slice(-3, -1)).toEqual(['—', '—'])
		expect(rows[0].find('.stuf-audit-log__status').classes()).toContain('stuf-audit-log__status--bevestigd')
		expect(rows[1].find('.stuf-audit-log__status').classes()).toContain('stuf-audit-log__status--unknown')
	})

	it('shows the empty state for a body without items', async () => {
		const wrapper = await open({ items: 'none' })

		expect(wrapper.find('[data-testid="stuf-audit-log-table"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="stuf-audit-log-empty"]').text()).toBe('No StUF messages match the filters.')
	})

	it('says so when the messages cannot be read', async () => {
		axios.get.mockRejectedValue(new Error('down'))
		const wrapper = mount(StufAuditLog)
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('Failed to load StUF audit log')
		expect(wrapper.find('.stuf-audit-log__error').text()).toBe('Failed to load StUF audit log')
		expect(wrapper.find('.NcButton').text()).toBe('Reload')
	})

	it('clears the error once a reload succeeds', async () => {
		axios.get.mockRejectedValueOnce(new Error('down'))
		const wrapper = mount(StufAuditLog)
		await flushPromises()
		axios.get.mockResolvedValue({ data: { items: ROWS } })

		await wrapper.find('.NcButton').trigger('click')
		await flushPromises()

		expect(wrapper.find('.stuf-audit-log__error').exists()).toBe(false)
		expect(wrapper.findAll('tbody tr')).toHaveLength(2)
	})

	it('opens a row in the envelope dialog and closes it again', async () => {
		const wrapper = await open()

		await wrapper.findAll('tbody tr')[0].find('.NcButton').trigger('click')
		const dialog = wrapper.findComponent({ name: 'StufEnvelopeDialog' })
		expect(dialog.props('row')).toEqual(ROWS[0])

		dialog.vm.$emit('close')
		await wrapper.vm.$nextTick()
		expect(wrapper.findComponent({ name: 'StufEnvelopeDialog' }).exists()).toBe(false)
	})

	it('reloads at once on a status filter and after a pause on the endpoint id', async () => {
		const wrapper = await open()
		vi.useFakeTimers()

		wrapper.findAllComponents({ name: 'NcSelect' })[1].vm.$emit('update:modelValue', 'fout')
		await flushPromises()
		expect(axios.get).toHaveBeenCalledTimes(2)
		expect(axios.get.mock.calls[1][1].params.status).toBe('fout')

		wrapper.findComponent({ name: 'NcTextField' }).vm.$emit('update:modelValue', 'ep-1')
		await flushPromises()
		vi.advanceTimersByTime(399)
		expect(axios.get).toHaveBeenCalledTimes(2)
		vi.advanceTimersByTime(1)
		await flushPromises()
		expect(axios.get).toHaveBeenCalledTimes(3)
		expect(axios.get.mock.calls[2][1].params).toEqual({ endpointId: 'ep-1', messageKind: '', status: 'fout', limit: 100 })
	})

	it('exports the rows it holds as CSV, blanks for missing values', async () => {
		const wrapper = await open()
		let blob = null
		URL.createObjectURL = vi.fn((b) => {
			blob = b
			return 'blob:stuf'
		})
		URL.revokeObjectURL = vi.fn()
		const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})

		await wrapper.findAll('.stuf-audit-log__buttons .NcButton')[1].trigger('click')

		expect(click).toHaveBeenCalledTimes(1)
		expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:stuf')
		expect(blob.type).toBe('text/csv')
		expect((await blob.text()).split('\n')).toEqual([
			'sentOn,direction,messageKind,role,status,httpStatus,durationMs,referenceNumber,caseIdentification',
			'"2026-11-01T10:00:00Z","out","Lk01","client","bevestigd",200,120,"ref-1","Z-1"',
			'"2026-11-01T11:00:00Z","out","Lk02","client","","",0,"ref \\"2\\"",""',
		])
		click.mockRestore()
	})
})
