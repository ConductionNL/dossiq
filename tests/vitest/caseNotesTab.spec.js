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
import { showError, showSuccess, showWarning } from '@nextcloud/dialogs'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

vi.mock('@nextcloud/dialogs', () => ({
	showError: vi.fn(),
	showSuccess: vi.fn(),
	showWarning: vi.fn(),
}))

const NotesLeafStub = defineComponent({
	name: 'NotesLeafStub',
	props: ['objectId', 'register', 'schema', 'apiBase', 'noteActions'],
	emits: ['mention', 'note-action'],
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

/**
 * The note push has a caller.
 *
 * 🔴 WHAT THESE TESTS ARE FOR. `notes#push` shipped routed, guarded and
 * covered by `tests/e2e/note-sync.spec.ts`, and `grep -rn "notes/push" src/`
 * answered nothing: a green suite around an endpoint no page could reach.
 * So these assert the CALL SITE, not the method. A test that only reached
 * into `onNoteAction()` would pass with the `@note-action` listener deleted
 * from the template, which is exactly the state this change came to end.
 * Every one of them starts by emitting the event the LIBRARY emits.
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */
describe('CaseNotesTab sends one note to the neighbouring register', () => {
	const NOTE = {
		id: 7,
		message: 'Gebeld met de aanvrager',
		actorId: 'admin',
		visibility: 'public',
	}

	beforeEach(() => {
		axios.post.mockReset()
		axios.post.mockResolvedValue({ data: {} })
		showSuccess.mockReset()
		showError.mockReset()
		showWarning.mockReset()
	})

	/**
	 * Mount the tab and hand back the leaf the library would render.
	 *
	 * @return {object} `{ wrapper, leaf }`.
	 */
	function mountTab() {
		const wrapper = mount(CaseNotesTab, {
			props: { objectId: 'case-1', register: 'dossiq', schema: 'case' },
		})
		return { wrapper, leaf: wrapper.findComponent(NotesLeafStub) }
	}

	it('offers the send action to the notes component', () => {
		const { leaf } = mountTab()
		// The prop the library reads. Without an entry here the library
		// renders no button, and the endpoint is unreachable again.
		const actions = leaf.props('noteActions')
		expect(actions).toHaveLength(1)
		expect(actions[0].id).toBe('push-to-neighbouring-register')
		expect(actions[0].label).toBe('Send to the neighbouring register')
	})

	it('posts the note the reader picked to the push endpoint', async () => {
		const { leaf } = mountTab()
		axios.post.mockResolvedValue({
			data: { outcome: 'sent', reason: '', caseRecord: 'written' },
		})

		leaf.vm.$emit('note-action', {
			action: 'push-to-neighbouring-register',
			note: NOTE,
		})
		await flushPromises()

		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(axios.post).toHaveBeenCalledWith(
			'/index.php/apps/dossiq/api/cases/case-1/notes/push',
			{ note: NOTE },
		)
		expect(showSuccess).toHaveBeenCalledWith(
			'Note sent to the neighbouring register',
		)
	})

	it('ignores an action it does not own', async () => {
		const { leaf } = mountTab()
		leaf.vm.$emit('note-action', { action: 'something-else', note: NOTE })
		await flushPromises()
		expect(axios.post).not.toHaveBeenCalled()
	})

	it("repeats the server's own reason when the note stayed here", async () => {
		const { leaf } = mountTab()
		axios.post.mockResolvedValue({
			data: {
				outcome: 'not-sent',
				reason: 'This note is internal, so it stays here.',
				caseRecord: 'written',
			},
		})

		leaf.vm.$emit('note-action', {
			action: 'push-to-neighbouring-register',
			note: NOTE,
		})
		await flushPromises()

		// The server's sentence, not ours: it names the thing to change.
		expect(showWarning).toHaveBeenCalledWith(
			'This note is internal, so it stays here.',
		)
		expect(showError).not.toHaveBeenCalled()
	})

	it('says a refused push failed, with the reason the register gave', async () => {
		const { leaf } = mountTab()
		axios.post.mockResolvedValue({
			data: {
				outcome: 'failed',
				reason: 'Set note_informatieobjecttype first.',
				caseRecord: 'lost',
			},
		})

		leaf.vm.$emit('note-action', {
			action: 'push-to-neighbouring-register',
			note: NOTE,
		})
		await flushPromises()

		expect(showError).toHaveBeenCalledWith(
			'Set note_informatieobjecttype first.',
		)
	})

	it('a transport failure is said out loud rather than swallowed', async () => {
		const { leaf } = mountTab()
		axios.post.mockRejectedValue(new Error('network down'))

		leaf.vm.$emit('note-action', {
			action: 'push-to-neighbouring-register',
			note: NOTE,
		})
		await flushPromises()

		// Unlike the mention forward, this one the reader asked for, so a
		// silent warn in the console would be the app losing the answer.
		expect(showError).toHaveBeenCalledWith('The note could not be sent')
	})
})
