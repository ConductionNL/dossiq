// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Saving and deleting an organisational role from the delegation matrix's
 * role tree: a new role is posted, an existing one patched under its own id,
 * a delete goes to the role's id, and the list is asked to reload after each.
 *
 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-07-admin-ui/tasks.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const mockPost = vi.fn()
const mockPatch = vi.fn()
const mockDelete = vi.fn()

vi.mock('@nextcloud/axios', () => ({
	default: {
		post: (...a) => mockPost(...a),
		patch: (...a) => mockPatch(...a),
		delete: (...a) => mockDelete(...a),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnTreeView: {
		name: 'CnTreeView',
		props: ['nodes', 'expandedIds', 'expandLabel', 'collapseLabel'],
		emits: ['update:expandedIds'],
		render() {
			const draw = (node) => h('li', { 'data-node': node.id }, [
				this.$slots.actions({ node }),
				h('ul', (node.children || []).map(draw)),
			])
			return h('ul', this.nodes.map(draw))
		},
	},
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: { name: 'NcButton', emits: ['click'], render() { return h('button', { onClick: (e) => this.$emit('click', e) }, this.$slots.default()) } },
	NcEmptyContent: { name: 'NcEmptyContent', props: ['name', 'description'], render() { return h('div', { class: 'empty' }, this.name) } },
	NcLoadingIcon: { name: 'NcLoadingIcon', render: () => h('span', { class: 'loading' }) },
}))
vi.mock('../../src/dialogs/RolEditorDialog.vue', () => ({ default: { name: 'RolEditorDialog', props: ['role', 'parentOptions'], emits: ['save', 'close'], render: () => h('div', { class: 'editor' }) } }))
vi.mock('../../src/dialogs/DeleteRolDialog.vue', () => ({ default: { name: 'DeleteRolDialog', props: ['role', 'blockedReason'], emits: ['close', 'confirm'], render: () => h('div', { class: 'delete' }) } }))

const RoleTreeManager = (await import('../../src/views/settings/components/RoleTreeManager.vue')).default

const ROLES = [
	{ id: 'dir', name: 'Directie' },
	{ id: 'vth/1', name: '', parentRole: 'dir' },
]

describe('RoleTreeManager saving and deleting', () => {
	beforeEach(() => {
		mockPost.mockReset().mockResolvedValue({ data: {} })
		mockPatch.mockReset().mockResolvedValue({ data: {} })
		mockDelete.mockReset().mockResolvedValue({ data: {} })
	})

	it('shows the empty state and no tree when there are no roles', () => {
		const wrapper = mount(RoleTreeManager, { props: { roles: [] } })

		expect(wrapper.find('.empty').text()).toBe('No organisational roles')
		expect(wrapper.findComponent({ name: 'CnTreeView' }).exists()).toBe(false)
	})

	it('shows only the loading icon while loading', () => {
		const wrapper = mount(RoleTreeManager, { props: { roles: [], loading: true } })

		expect(wrapper.find('.loading').exists()).toBe(true)
		expect(wrapper.find('.empty').exists()).toBe(false)
	})

	it('offers top level and every role as a parent, naming a nameless role by its id', async () => {
		const wrapper = mount(RoleTreeManager, { props: { roles: ROLES } })
		await wrapper.findAll('button')[0].trigger('click')

		const editor = wrapper.findComponent({ name: 'RolEditorDialog' })
		expect(editor.props('role')).toBeNull()
		expect(editor.props('parentOptions')).toEqual([
			{ id: '', label: '(top level)' },
			{ id: 'dir', label: 'Directie' },
			{ id: 'vth/1', label: 'vth/1' },
		])
	})

	it('posts a new role, closes the editor and asks for a reload', async () => {
		const wrapper = mount(RoleTreeManager, { props: { roles: ROLES } })
		await wrapper.findAll('button')[0].trigger('click')

		wrapper.findComponent({ name: 'RolEditorDialog' }).vm.$emit('save', { name: 'Nieuw' })
		await flushPromises()

		expect(mockPost).toHaveBeenCalledWith('/index.php/apps/dossiq/api/mandate/rollen', { name: 'Nieuw' })
		expect(mockPatch).not.toHaveBeenCalled()
		expect(wrapper.findComponent({ name: 'RolEditorDialog' }).exists()).toBe(false)
		expect(wrapper.emitted('reload')).toHaveLength(1)
	})

	it('patches an edited role under its encoded id', async () => {
		const wrapper = mount(RoleTreeManager, { props: { roles: ROLES } })
		await wrapper.find('[data-node="vth/1"]').findAll('button')[0].trigger('click')

		wrapper.findComponent({ name: 'RolEditorDialog' }).vm.$emit('save', { name: 'VTH' })
		await flushPromises()

		expect(mockPatch).toHaveBeenCalledWith('/index.php/apps/dossiq/api/mandate/rollen/vth%2F1', { name: 'VTH' })
		expect(mockPost).not.toHaveBeenCalled()
		expect(wrapper.emitted('reload')).toHaveLength(1)
	})

	it('keeps the editor open and does not reload when saving fails', async () => {
		mockPost.mockRejectedValue(new Error('500'))
		const wrapper = mount(RoleTreeManager, { props: { roles: ROLES } })
		await wrapper.findAll('button')[0].trigger('click')

		wrapper.findComponent({ name: 'RolEditorDialog' }).vm.$emit('save', { name: 'Nieuw' })
		await flushPromises()

		expect(wrapper.findComponent({ name: 'RolEditorDialog' }).exists()).toBe(true)
		expect(wrapper.emitted('reload')).toBeUndefined()
	})

	it('closes the editor without saving', async () => {
		const wrapper = mount(RoleTreeManager, { props: { roles: ROLES } })
		await wrapper.findAll('button')[0].trigger('click')

		wrapper.findComponent({ name: 'RolEditorDialog' }).vm.$emit('close')
		await flushPromises()

		expect(wrapper.findComponent({ name: 'RolEditorDialog' }).exists()).toBe(false)
		expect(mockPost).not.toHaveBeenCalled()
	})

	it('deletes a leaf role under its encoded id, with no blocked reason, and reloads', async () => {
		const wrapper = mount(RoleTreeManager, { props: { roles: ROLES } })
		await wrapper.find('[data-node="vth/1"]').findAll('button')[1].trigger('click')

		const dialog = wrapper.findComponent({ name: 'DeleteRolDialog' })
		expect(dialog.props('blockedReason')).toBe('')
		dialog.vm.$emit('confirm')
		await flushPromises()

		expect(mockDelete).toHaveBeenCalledWith('/index.php/apps/dossiq/api/mandate/rollen/vth%2F1')
		expect(wrapper.findComponent({ name: 'DeleteRolDialog' }).exists()).toBe(false)
		expect(wrapper.emitted('reload')).toHaveLength(1)
	})

	it('still closes the dialog and reloads when the delete fails', async () => {
		mockDelete.mockRejectedValue(new Error('409'))
		const wrapper = mount(RoleTreeManager, { props: { roles: ROLES } })
		await wrapper.find('[data-node="vth/1"]').findAll('button')[1].trigger('click')

		wrapper.findComponent({ name: 'DeleteRolDialog' }).vm.$emit('confirm')
		await flushPromises()

		expect(wrapper.findComponent({ name: 'DeleteRolDialog' }).exists()).toBe(false)
		expect(wrapper.emitted('reload')).toHaveLength(1)
	})

	it('cancelling the delete dialog deletes nothing', async () => {
		const wrapper = mount(RoleTreeManager, { props: { roles: ROLES } })
		await wrapper.find('[data-node="vth/1"]').findAll('button')[1].trigger('click')

		wrapper.findComponent({ name: 'DeleteRolDialog' }).vm.$emit('close')
		await flushPromises()

		expect(wrapper.findComponent({ name: 'DeleteRolDialog' }).exists()).toBe(false)
		expect(mockDelete).not.toHaveBeenCalled()
	})

	it('follows the tree when it reports other expanded ids', async () => {
		const wrapper = mount(RoleTreeManager, { props: { roles: ROLES } })
		wrapper.findComponent({ name: 'CnTreeView' }).vm.$emit('update:expandedIds', ['dir'])
		await flushPromises()

		expect(wrapper.findComponent({ name: 'CnTreeView' }).props('expandedIds')).toEqual(['dir'])
	})
})
