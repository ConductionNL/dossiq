// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The sidebar Notes tab renders the resolved notes leaf, not its tag name.
 *
 * The defect this pins down: `CaseNotesTab` held the leaf component object
 * in data and wrote `<CnNotesTabComponent>` in its template. Vue resolves a
 * tag against REGISTERED components only, so the case sidebar's Notes tab
 * rendered a literal `<cnnotestabcomponent>` element with nothing inside.
 * `<component :is>` takes the object itself; this mounts the tab with a
 * stub leaf and checks that the stub, not an unknown element, is what
 * comes out, and that a mention the leaf reports is forwarded to dossiq's
 * own notification endpoint.
 *
 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const NotesLeafStub = defineComponent({
	name: 'NotesLeafStub',
	props: ['objectId', 'register', 'schema', 'apiBase'],
	emits: ['mention'],
	render() {
		return h(
			'div',
			{
				class: 'notes-leaf-stub',
				'data-object-id': this.objectId,
				'data-register': this.register,
				'data-schema': this.schema,
			},
			'notes leaf',
		)
	},
})

vi.mock('../../src/integrations/leafTabs.js', () => ({
	leafTab: (id) => (id === 'notes' ? NotesLeafStub : undefined),
}))

// Imported AFTER the mock so the component sees the stubbed resolver.
const { default: CaseNotesTab } =
	await import('../../src/views/cases/components/CaseNotesTab.vue')

describe('CaseNotesTab', () => {
	beforeEach(() => {
		axios.post.mockReset()
		axios.post.mockResolvedValue({ data: {} })
	})

	it('renders the resolved leaf component, not an unknown element', () => {
		const wrapper = mount(CaseNotesTab, {
			props: { objectId: 'case-1', register: 'dossiq', schema: 'case' },
		})
		expect(wrapper.find('cnnotestabcomponent').exists()).toBe(false)
		const leaf = wrapper.find('.notes-leaf-stub')
		expect(leaf.exists()).toBe(true)
		expect(leaf.attributes('data-object-id')).toBe('case-1')
		expect(leaf.attributes('data-register')).toBe('dossiq')
		expect(leaf.attributes('data-schema')).toBe('case')
	})

	it('forwards a mention from the leaf to the dossiq notification endpoint', async () => {
		const wrapper = mount(CaseNotesTab, {
			props: { objectId: 'case-1', register: 'dossiq', schema: 'case' },
		})
		const payload = {
			objectId: 'case-1',
			register: 'dossiq',
			schema: 'case',
			noteId: 'note-9',
			mentionedUserIds: ['alice'],
		}
		wrapper.findComponent(NotesLeafStub).vm.$emit('mention', payload)
		await flushPromises()
		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(axios.post).toHaveBeenCalledWith(
			'/index.php/apps/dossiq/api/notes/mention',
			payload,
		)
	})
})
