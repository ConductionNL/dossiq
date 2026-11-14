// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DossierExportPanel: the read-only Awb-ordered export plan of one case. A
 * refused read (403), a failed read and a case with no documents are three
 * different answers and must not render alike.
 *
 * @spec openspec/specs/bezwaar-beroep-workflow/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const mockGet = vi.fn()

vi.mock('@nextcloud/axios', () => ({
	default: { get: (...a) => mockGet(...a) },
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url) => String(url),
}))

function control(name) {
	return defineComponent({
		name,
		props: ['name', 'size'],
		emits: ['close'],
		render() {
			return h('div', { class: name, 'data-name': this.name ?? '' }, [
				this.$slots.default?.() ?? [],
				this.$slots.icon?.() ?? [],
			])
		},
	})
}

vi.mock('@nextcloud/vue', () => ({
	NcEmptyContent: control('NcEmptyContent'),
	NcLoadingIcon: control('NcLoadingIcon'),
	NcModal: control('NcModal'),
}))

const { default: DossierExportPanel } =
	await import('../../src/modals/DossierExportPanel.vue')

function mountPanel(props, route = { params: {} }) {
	return mount(DossierExportPanel, {
		props,
		global: { mocks: { $route: route } },
	})
}

beforeEach(() => {
	mockGet.mockReset()
})

describe('DossierExportPanel', () => {
	it('reads the plan when it opens and lists the entries in order', async () => {
		mockGet.mockResolvedValue({
			data: {
				entries: [
					{ sequence: 1, filename: '01-besluit.pdf', title: 'Besluit' },
					{
						sequence: 2,
						filename: '02-bezwaar.pdf',
						title: 'Bezwaarschrift',
					},
				],
			},
		})

		const wrapper = mountPanel({ open: true, caseId: 'case 1' })
		await flushPromises()

		expect(mockGet).toHaveBeenCalledWith(
			'/apps/dossiq/api/dossier/case%201/export',
		)
		const items = wrapper.findAll('.dossier-export-panel__item')
		expect(
			items.map((i) => i.find('.dossier-export-panel__filename').text()),
		).toEqual(['01-besluit.pdf', '02-bezwaar.pdf'])
		expect(items[1].find('.dossier-export-panel__meta').text()).toBe(
			'Bezwaarschrift',
		)
		expect(wrapper.find('.dossier-export-panel__count').exists()).toBe(true)
		expect(wrapper.vm.loading).toBe(false)
	})

	it('does not read while closed, and reads once it opens', async () => {
		mockGet.mockResolvedValue({ data: { entries: [] } })

		const wrapper = mountPanel({ open: false, caseId: 'case-1' })
		await flushPromises()
		expect(mockGet).not.toHaveBeenCalled()
		expect(wrapper.find('.NcModal').exists()).toBe(false)

		await wrapper.setProps({ open: true })
		await flushPromises()
		expect(mockGet).toHaveBeenCalledTimes(1)
	})

	it('tells an empty case apart from a refused or failed read', async () => {
		mockGet.mockResolvedValueOnce({ data: { entries: [] } })
		const empty = mountPanel({ open: true, caseId: 'case-1' })
		await flushPromises()
		expect(empty.vm.error).toBe('')
		expect(empty.find('.NcEmptyContent').attributes('data-name')).toBe(
			'This case has no documents to submit',
		)

		mockGet.mockRejectedValueOnce({ response: { status: 403 } })
		const refused = mountPanel({ open: true, caseId: 'case-1' })
		await flushPromises()
		expect(refused.vm.error).toBe('You may not read every case in this chain')
		expect(refused.vm.entries).toEqual([])

		mockGet.mockRejectedValueOnce(new Error('network'))
		const failed = mountPanel({ open: true, caseId: 'case-1' })
		await flushPromises()
		expect(failed.vm.error).toBe('Could not build the dossier export')
		expect(failed.vm.loading).toBe(false)
	})

	it('treats an answer without an entries list as empty', async () => {
		mockGet.mockResolvedValue({ data: { entries: 'nope' } })

		const wrapper = mountPanel({ open: true, caseId: 'case-1' })
		await flushPromises()

		expect(wrapper.vm.entries).toEqual([])
	})

	it('falls back to the route id for an unresolved token, and refuses without a case', async () => {
		mockGet.mockResolvedValue({ data: { entries: [] } })

		mountPanel(
			{ open: true, caseId: '@objectId' },
			{ params: { id: 'route-case' } },
		)
		await flushPromises()
		expect(mockGet).toHaveBeenCalledWith(
			'/apps/dossiq/api/dossier/route-case/export',
		)

		mockGet.mockReset()
		const none = mountPanel({ open: true, caseId: '' })
		await flushPromises()
		expect(mockGet).not.toHaveBeenCalled()
		expect(none.vm.error).toBe('No case to export')
	})

	it('passes the modal close on', async () => {
		mockGet.mockResolvedValue({ data: { entries: [] } })
		const wrapper = mountPanel({ open: true, caseId: 'case-1' })
		await flushPromises()

		wrapper.findComponent({ name: 'NcModal' }).vm.$emit('close')

		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})
