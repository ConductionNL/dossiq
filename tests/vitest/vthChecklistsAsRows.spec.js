// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The VTH checklists are library list rows with a status pill and a row menu
 * (board DqVthInstellingen). The old badge drew the main ink on the solid
 * success colour, 2.6:1 under the Zuiddrecht theme; the library's success pill
 * is the light tint with the deep ink, 5.8:1.
 *
 * @spec openspec/changes/vth-checklists-as-rows/specs/vth-checklists-as-rows/spec.md#requirement-the-vth-checklists-are-library-rows-with-a-status-pill
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

const stub = (tag) => ({ render() { return h(tag, this.$slots.default ? this.$slots.default() : []) } })

vi.mock('@nextcloud/vue', () => ({ NcButton: stub('button'), NcLoadingIcon: stub('span'), NcEmptyContent: stub('div'), NcIconSvgWrapper: stub('span') }))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnConfirmDialog: stub('div'),
	CnObjectRow: {
		name: 'CnObjectRow',
		props: ['object', 'config'],
		render() {
			return h('div', { class: 'row' }, this.$slots.actions ? this.$slots.actions() : [])
		},
	},
	CnRowActions: { name: 'CnRowActions', props: ['actions', 'row', 'rowLabel'], render: () => h('span') },
}))
vi.mock('../../src/components/InspectionChecklistEditor.vue', () => ({ default: stub('div') }))

const ChecklistsTab = (await import('../../src/views/settings/tabs/ChecklistsTab.vue')).default

/**
 * @return {Promise<object>} The tab with two checklists.
 */
async function mountTab() {
	axios.get.mockResolvedValue({ data: [
		{ id: 'a', name: 'Brandveiligheid', active: true, version: 3, items: [{}, {}] },
		{ id: 'b', name: 'Asbest', active: false, version: 1, items: [] },
	] })
	const wrapper = mount(ChecklistsTab)
	await flushPromises()
	return wrapper
}

describe('VTH checklists as library rows', () => {
	it('draws one row per checklist with its name, version and size, and its state as a pill', async () => {
		const rows = (await mountTab()).findAllComponents({ name: 'CnObjectRow' })
		expect(rows).toHaveLength(2)
		expect(rows[0].props('config')).toEqual({ titleField: 'name', subtitleField: 'subtitle', badgeField: 'state', badgeVariantField: 'tone' })
		expect(rows[0].props('object')).toMatchObject({ name: 'Brandveiligheid', state: 'Active', tone: 'success' })
		expect(rows[0].props('object').subtitle).toContain('3')
		expect(rows[1].props('object')).toMatchObject({ state: 'Inactive', tone: 'warning' })
	})

	it('keeps Edit and Delete in the row menu, bound to the checklist', async () => {
		const wrapper = await mountTab()
		const menu = wrapper.findAllComponents({ name: 'CnRowActions' })[0]
		expect(menu.props('actions').map((action) => action.id)).toEqual(['edit', 'delete'])
		expect(menu.props('row')).toMatchObject({ id: 'a' })
		menu.props('actions')[0].handler(menu.props('row'))
		expect(wrapper.vm.editing).toBe(true)
	})

	it('draws no buttons of its own in a row', async () => {
		const wrapper = await mountTab()
		expect(wrapper.findAll('.row button')).toHaveLength(0)
	})
})
