// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The organisational roles of the delegation matrix are the library's tree:
 * each role under its parent, every level open, with its kind, mandate level
 * and unit beside its name and Edit and Delete on its row.
 *
 * @spec openspec/changes/ui-facing-names-are-generic/specs/ui-facing-names/spec.md
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('@conduction/nextcloud-vue', () => ({
	CnTreeView: {
		name: 'CnTreeView',
		props: ['nodes', 'expandedIds', 'expandLabel', 'collapseLabel'],
		render() {
			const draw = (node) => h('li', { 'data-node': node.id }, [
				h('span', { class: 'label' }, node.label),
				this.$slots.actions({ node }),
				h('ul', (node.children || []).map(draw)),
			])
			return h('ul', this.nodes.map(draw))
		},
	},
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: { name: 'NcButton', emits: ['click'], render() { return h('button', { onClick: (e) => this.$emit('click', e) }, this.$slots.default()) } },
	NcEmptyContent: { render: () => h('div') },
	NcLoadingIcon: { render: () => h('span') },
}))
vi.mock('../../src/dialogs/RolEditorDialog.vue', () => ({ default: { name: 'RolEditorDialog', props: ['role', 'parentOptions'], render: () => h('div', { class: 'editor' }) } }))
vi.mock('../../src/dialogs/DeleteRolDialog.vue', () => ({ default: { name: 'DeleteRolDialog', props: ['role', 'blockedReason'], render: () => h('div', { class: 'delete' }) } }))

const RoleTreeManager = (await import('../../src/views/settings/components/RoleTreeManager.vue')).default

const ROLES = [
	{ id: 'dir', name: 'Directie', type: 'afdeling', mandateLevel: 3 },
	{ id: 'vth', name: 'VTH', parentRole: 'dir', department: 'Omgeving' },
	{ id: 'team', name: 'Team toezicht', parentRole: 'vth', team: 'Toezicht' },
]

describe('RoleTreeManager', () => {
	it('nests each role under its parent in the library tree, every level open', () => {
		const wrapper = mount(RoleTreeManager, { props: { roles: ROLES } })
		const tree = wrapper.findComponent({ name: 'CnTreeView' })

		expect(tree.props('nodes')).toHaveLength(1)
		expect(tree.props('nodes')[0].children[0].children[0]).toMatchObject({ id: 'team', label: 'Team toezicht' })
		expect(tree.props('expandedIds')).toEqual(['dir', 'vth', 'team'])
		expect(tree.props('expandLabel')).toBe('Expand')
	})

	it('shows the kind, mandate level and unit beside the name', () => {
		const text = mount(RoleTreeManager, { props: { roles: ROLES } }).text()

		expect(text).toContain('afdeling')
		expect(text).toContain('level 3')
		expect(text).toContain('Omgeving')
		expect(text).toContain('Toezicht')
	})

	it('opens the editor and the delete dialog from a row, refusing to delete a parent', async () => {
		const wrapper = mount(RoleTreeManager, { props: { roles: ROLES } })
		const vthRow = wrapper.find('[data-node="vth"]')
		const buttons = vthRow.findAll('button')

		await buttons[0].trigger('click')
		expect(wrapper.findComponent({ name: 'RolEditorDialog' }).props('role')).toMatchObject({ id: 'vth' })

		await buttons[1].trigger('click')
		expect(wrapper.findComponent({ name: 'DeleteRolDialog' }).props('blockedReason')).toContain('parent of other roles')
	})

	it('survives a role that names itself as its own ancestor', () => {
		const loop = [{ id: 'a', name: 'A' }, { id: 'b', name: 'B', parentRole: 'c' }, { id: 'c', name: 'C', parentRole: 'b' }]
		const tree = mount(RoleTreeManager, { props: { roles: loop } }).findComponent({ name: 'CnTreeView' })

		expect(tree.props('nodes').map((node) => node.id)).toEqual(['a'])
	})
})
