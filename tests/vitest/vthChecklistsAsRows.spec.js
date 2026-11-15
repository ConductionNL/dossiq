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
 * @spec openspec/specs/vth-checklists-as-rows/spec.md#requirement-the-vth-checklists-are-library-rows-with-a-status-pill
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const { setResult } = vi.hoisted(() => ({ setResult: vi.fn() }))

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

const stub = (tag) => ({ render() { return h(tag, this.$slots.default ? this.$slots.default() : []) } })

vi.mock('@nextcloud/vue', () => ({ NcButton: stub('button'), NcLoadingIcon: stub('span'), NcEmptyContent: stub('div'), NcIconSvgWrapper: stub('span') }))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnConfirmDialog: stub('div'),
	CnFormDialog: { name: 'CnFormDialog', props: ['schema', 'item', 'initialData', 'dialogTitle'], methods: { setResult }, render: () => h('div', { class: 'form-dialog' }) },
	CnObjectRow: {
		name: 'CnObjectRow',
		props: ['object', 'config'],
		render() {
			return h('div', { class: 'row' }, this.$slots.actions ? this.$slots.actions() : [])
		},
	},
	CnRowActions: { name: 'CnRowActions', props: ['actions', 'row', 'rowLabel'], render: () => h('span') },
}))

const ChecklistTemplatesTab = (await import('../../src/views/settings/tabs/ChecklistTemplatesTab.vue')).default

/**
 * @return {Promise<object>} The tab with two checklists.
 */
async function mountTab() {
	axios.get.mockResolvedValue({ data: [
		{ id: 'a', name: 'Brandveiligheid', active: true, version: 3, items: [{}, {}] },
		{ id: 'b', name: 'Asbest', active: false, version: 1, items: [] },
	] })
	const wrapper = mount(ChecklistTemplatesTab)
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
		expect(rows[1].props('object')).toMatchObject({ state: 'Inactive', tone: 'default' })
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

describe('the checklist template form', () => {
	it('edits a template in the library form dialog, its items as a table of sub-objects', async () => {
		const wrapper = await mountTab()
		wrapper.vm.openEditor(wrapper.vm.checklists[0])
		await flushPromises()

		const dialog = wrapper.findComponent({ name: 'CnFormDialog' })
		expect(dialog.exists()).toBe(true)
		expect(dialog.props('item')).toMatchObject({ id: 'a' })
		const schema = dialog.props('schema')
		expect(Object.keys(schema.properties)).toEqual(['name', 'version', 'active', 'validFrom', 'items'])
		expect(schema.properties.items['x-widget']).toBe('sub-objects')
		expect(Object.keys(schema.properties.items.items.properties)).toEqual(['question', 'type', 'required', 'weight'])
		expect(schema.properties.items.items.properties.type.enum).toEqual(['boolean', 'text', 'enum', 'photo'])
	})

	it('names no procedure in the form', async () => {
		const wrapper = await mountTab()
		wrapper.vm.openEditor(null)
		await flushPromises()
		const schema = JSON.stringify(wrapper.findComponent({ name: 'CnFormDialog' }).props('schema'))
		expect(schema).not.toMatch(/Bouwtoezicht|VTH|inspectie/i)
	})

	it('creates a new template with POST and updates one with PUT on the generic route', async () => {
		const wrapper = await mountTab()
		axios.post = vi.fn().mockResolvedValue({ data: {} })
		axios.put = vi.fn().mockResolvedValue({ data: {} })

		wrapper.vm.openEditor(null)
		await wrapper.vm.saveChecklist({ name: 'Nieuw', items: [{ question: 'Q', type: 'text' }] })
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/dossiq/api/checklists', expect.objectContaining({ name: 'Nieuw', version: 1 }))

		wrapper.vm.openEditor(wrapper.vm.checklists[0])
		await wrapper.vm.saveChecklist({ id: 'a', name: 'Brandveiligheid' })
		expect(axios.put).toHaveBeenCalledWith('/index.php/apps/dossiq/api/checklists/a', expect.objectContaining({ id: 'a', version: 3 }))
	})

	it('keeps the dialog open with the server\'s sentence when the save is refused', async () => {
		const wrapper = await mountTab()
		axios.put = vi.fn().mockRejectedValue({ response: { data: { message: 'Version 3 is already published' } } })

		wrapper.vm.openEditor(wrapper.vm.checklists[0])
		await flushPromises()
		setResult.mockClear()
		await wrapper.vm.saveChecklist({ id: 'a', name: 'Brandveiligheid' })

		expect(wrapper.vm.editing).toBe(true)
		expect(setResult).toHaveBeenCalledWith({ error: 'Version 3 is already published' })
	})
})
