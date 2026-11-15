// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The checklist admin list's row menu and its failure paths: Edit opens the
 * form on a copy of the row, Delete asks first and deletes through dossiq's
 * own checklist route, and a failed read or delete says so.
 *
 * @spec openspec/specs/inspection-checklists/spec.md#inspection-checklist-admin-ui
 */

import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('@nextcloud/dialogs', () => ({
	showError: vi.fn(),
	showSuccess: vi.fn(),
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
		render() {
			return h(tag, { class: name }, this.$slots.default ? this.$slots.default() : [])
		},
	}
}

const confirmSetResult = vi.fn()

vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		emits: ['click'],
		render() {
			return h('button', { class: 'NcButton', onClick: () => this.$emit('click') }, this.$slots.default ? this.$slots.default() : [])
		},
	},
	NcLoadingIcon: stub('NcLoadingIcon', 'span'),
	NcEmptyContent: {
		name: 'NcEmptyContent',
		props: ['name', 'description'],
		render() {
			return h('div', { class: 'NcEmptyContent' }, this.name)
		},
	},
	NcIconSvgWrapper: stub('NcIconSvgWrapper', 'span'),
}))

vi.mock('@conduction/nextcloud-vue', () => ({
	CnConfirmDialog: {
		name: 'CnConfirmDialog',
		props: ['dialogTitle', 'message', 'variant', 'confirmLabel'],
		emits: ['confirm', 'close'],
		methods: { setResult: (...args) => confirmSetResult(...args) },
		render() {
			return h('div', { class: 'CnConfirmDialog' }, this.message)
		},
	},
	CnFormDialog: {
		name: 'CnFormDialog',
		props: ['schema', 'item', 'initialData', 'dialogTitle'],
		emits: ['confirm', 'close'],
		methods: { setResult: vi.fn() },
		render: () => h('div', { class: 'CnFormDialog' }),
	},
	CnObjectRow: {
		name: 'CnObjectRow',
		props: ['object', 'config'],
		render() {
			return h('div', { class: 'row' }, [this.object.name, this.$slots.actions ? this.$slots.actions() : null])
		},
	},
	// The row menu: one button per action, calling its handler with the row.
	CnRowActions: {
		name: 'CnRowActions',
		props: ['actions', 'row', 'rowLabel'],
		render() {
			return h('span', {}, this.actions.map((action) => h('button', {
				class: 'row-action row-action--' + action.id,
				onClick: () => action.handler(this.row),
			}, action.label)))
		},
	},
}))

const ChecklistTemplatesTab = (
	await import('../../src/views/settings/tabs/ChecklistTemplatesTab.vue')
).default

const CHECKLIST = { id: 'chk/1', name: 'Bouw', active: true, version: 2, items: [{ question: 'Fundering?' }] }

/**
 * Mount the tab against a list response and let it settle.
 *
 * @param {Array<object>} list The checklists the GET answers.
 * @return {Promise<object>} The wrapper.
 */
async function open(list = [CHECKLIST]) {
	axios.get.mockResolvedValue({ data: list })
	const wrapper = mount(ChecklistTemplatesTab)
	await flushPromises()
	return wrapper
}

describe('ChecklistTemplatesTab row actions', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('says so when the checklists cannot be read, and shows the empty state', async () => {
		axios.get.mockRejectedValue(new Error('offline'))
		const wrapper = mount(ChecklistTemplatesTab)
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('Failed to load checklists')
		expect(wrapper.find('.NcLoadingIcon').exists()).toBe(false)
		expect(wrapper.find('.NcEmptyContent').text()).toBe('No checklists')
	})

	it('offers Edit, then a destructive Delete, on each row', async () => {
		const wrapper = await open()

		const menu = wrapper.findComponent({ name: 'CnRowActions' })
		expect(menu.props('actions').map((a) => [a.id, a.label, a.destructive === true])).toEqual([
			['edit', 'Edit', false],
			['delete', 'Delete', true],
		])
		expect(menu.props('row')).toEqual(CHECKLIST)
		expect(menu.props('rowLabel')).toBe('Bouw')
	})

	it('opens the editor on a copy of the row from Edit', async () => {
		const wrapper = await open()

		await wrapper.find('.row-action--edit').trigger('click')

		const form = wrapper.findComponent({ name: 'CnFormDialog' })
		expect(form.exists()).toBe(true)
		expect(form.props('dialogTitle')).toBe('Edit checklist')
		expect(form.props('item')).toEqual(CHECKLIST)
		expect(form.props('item')).not.toBe(CHECKLIST)
		expect(form.props('initialData')).toEqual({})
	})

	it('opens a new checklist from the header button', async () => {
		const wrapper = await open([])

		await wrapper.find('.checklists-tab__header .NcButton').trigger('click')

		const form = wrapper.findComponent({ name: 'CnFormDialog' })
		expect(form.props('dialogTitle')).toBe('New checklist')
		expect(form.props('item')).toBeNull()
		expect(form.props('initialData')).toMatchObject({ name: '', version: 1, items: [], active: false })
	})

	it('asks before deleting, then deletes through the checklist route and reloads', async () => {
		const wrapper = await open()
		axios.delete.mockResolvedValue({})

		await wrapper.find('.row-action--delete').trigger('click')
		const dialog = wrapper.findComponent({ name: 'CnConfirmDialog' })
		expect(dialog.props('message')).toBe('Delete checklist “Bouw”?')
		expect(axios.delete).not.toHaveBeenCalled()

		dialog.vm.$emit('confirm')
		await flushPromises()

		expect(axios.delete).toHaveBeenCalledWith('/index.php/apps/dossiq/api/checklists/chk%2F1')
		expect(showSuccess).toHaveBeenCalledWith('Checklist deleted')
		expect(wrapper.findComponent({ name: 'CnConfirmDialog' }).exists()).toBe(false)
		expect(axios.get).toHaveBeenCalledTimes(2)
	})

	it('keeps the dialog open with the error when the delete fails', async () => {
		const wrapper = await open()
		axios.delete.mockRejectedValue(new Error('refused'))

		await wrapper.find('.row-action--delete').trigger('click')
		wrapper.findComponent({ name: 'CnConfirmDialog' }).vm.$emit('confirm')
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('Failed to delete checklist')
		expect(confirmSetResult).toHaveBeenCalledWith({ error: 'Failed to delete checklist' })
		expect(wrapper.findComponent({ name: 'CnConfirmDialog' }).exists()).toBe(true)
		expect(axios.get).toHaveBeenCalledTimes(1)
	})

	it('closes the confirmation without deleting', async () => {
		const wrapper = await open()

		await wrapper.find('.row-action--delete').trigger('click')
		wrapper.findComponent({ name: 'CnConfirmDialog' }).vm.$emit('close')
		await flushPromises()

		expect(wrapper.findComponent({ name: 'CnConfirmDialog' }).exists()).toBe(false)
		expect(axios.delete).not.toHaveBeenCalled()
	})
})
