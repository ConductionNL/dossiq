// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * CaseConversationsPanel's own buttons: it says so when Talk is missing,
 * starts a conversation and lists it, declares the case major once and then
 * offers the one working channel, and shows the server's reason when either
 * act is refused.
 *
 * @spec openspec/specs/case-management/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const mockGet = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: { get: (...a) => mockGet(...a), post: vi.fn() },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
vi.mock('@nextcloud/l10n', () => ({ getLanguage: () => 'en', translate: (_app, s) => s, translatePlural: (_app, one, other, n) => (n === 1 ? one : other) }))
vi.mock('@nextcloud/vue', () => {
	const slotted = (name, props = []) => ({
		name,
		props,
		emits: ['click'],
		setup(p, { slots, emit }) {
			return () => h('div', { class: name, onClick: () => emit('click') }, [slots.default?.()])
		},
	})
	return {
		NcButton: slotted('NcButton', ['disabled', 'variant', 'href']),
		NcEmptyContent: slotted('NcEmptyContent', ['name', 'description']),
		NcLoadingIcon: slotted('NcLoadingIcon', ['size']),
		NcNoteCard: slotted('NcNoteCard', ['type', 'heading']),
	}
})
vi.mock('@conduction/nextcloud-vue', () => ({
	CnStatusBadge: { name: 'CnStatusBadge', props: ['label', 'variant', 'size'], render: () => null },
}))
vi.mock('../../src/utils/caseConversation.js', () => ({
	conversationError: (e) => `refused: ${e.message}`,
	declareMajor: vi.fn(),
	startConversation: vi.fn(),
}))

const conversation = await import('../../src/utils/caseConversation.js')
const { default: CaseConversationsPanel } =
	await import('../../src/views/cases/components/CaseConversationsPanel.vue')

/**
 * Mount the panel and let the availability answer arrive.
 *
 * @param {object} props The panel's props.
 * @return {Promise<object>} The wrapper.
 */
async function mountPanel(props) {
	const wrapper = mount(CaseConversationsPanel, {
		props,
		global: { mocks: { t: (_app, s) => s, $route: { params: {} } } },
	})
	await flushPromises()
	return wrapper
}

const button = (wrapper, id) => wrapper.find(`[data-testid="${id}"]`)

describe('CaseConversationsPanel actions', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		mockGet.mockResolvedValue({ data: { available: true } })
		vi.spyOn(window, 'open').mockImplementation(() => null)
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('asks the availability endpoint and offers both buttons when Talk is there', async () => {
		const wrapper = await mountPanel({ caseId: 'c1' })

		expect(mockGet).toHaveBeenCalledWith('/apps/dossiq/api/conversations/availability')
		expect(wrapper.vm.loading).toBe(false)
		expect(button(wrapper, 'start-conversation').exists()).toBe(true)
		expect(button(wrapper, 'declare-major').exists()).toBe(true)
		expect(button(wrapper, 'open-major-channel').exists()).toBe(false)
		expect(wrapper.findComponent({ name: 'NcEmptyContent' }).props('name')).toBe('No conversations yet')
	})

	it('says Talk is not available instead of offering a button', async () => {
		mockGet.mockResolvedValue({ data: { available: false } })
		const wrapper = await mountPanel({ caseId: 'c1' })

		expect(wrapper.vm.talkUnavailable).toBe(true)
		expect(button(wrapper, 'start-conversation').exists()).toBe(false)
		expect(wrapper.findComponent({ name: 'NcEmptyContent' }).props('name')).toBe('Talk is not available')
	})

	it('treats an unreachable availability endpoint as no Talk', async () => {
		mockGet.mockRejectedValue(new Error('offline'))
		const wrapper = await mountPanel({ caseId: 'c1' })

		expect([wrapper.vm.talkUnavailable, wrapper.vm.loading]).toEqual([true, false])
		expect(button(wrapper, 'declare-major').exists()).toBe(false)
	})

	it('starts a conversation for the case and lists what it created', async () => {
		conversation.startConversation.mockResolvedValue({ roomId: '9', subject: 'Hoorzitting' })
		const wrapper = await mountPanel({ caseId: 'c1', caseObject: { conversations: [{ roomId: '8', subject: 'Eerder' }] } })

		await button(wrapper, 'start-conversation').trigger('click')
		await flushPromises()

		expect(conversation.startConversation).toHaveBeenCalledWith('c1')
		expect(wrapper.vm.conversations.map((c) => c.roomId)).toEqual(['8', '9'])
		expect(wrapper.find('[data-testid="conversation-records"]').text()).toContain('Hoorzitting')
		expect([wrapper.vm.starting, wrapper.vm.error]).toEqual([false, ''])
	})

	it('adds nothing when the server answers no conversation', async () => {
		conversation.startConversation.mockResolvedValue(null)
		const wrapper = await mountPanel({ caseId: 'c1' })

		await wrapper.vm.startConversation()

		expect(wrapper.vm.conversations).toEqual([])
	})

	it('shows the refusal when the conversation cannot start', async () => {
		conversation.startConversation.mockRejectedValue(new Error('no room'))
		const wrapper = await mountPanel({ caseId: 'c1' })

		await wrapper.vm.startConversation()
		await flushPromises()

		expect(wrapper.vm.error).toBe('refused: no room')
		expect(wrapper.vm.starting).toBe(false)
		const note = wrapper.findComponent({ name: 'NcNoteCard' })
		expect(note.props('heading')).toBe('We could not start the conversation')
		expect(note.text()).toBe('refused: no room')
	})

	it('starts and declares nothing without a case id', async () => {
		const wrapper = await mountPanel({})

		await wrapper.vm.startConversation()
		await wrapper.vm.declareMajor()

		expect(conversation.startConversation).not.toHaveBeenCalled()
		expect(conversation.declareMajor).not.toHaveBeenCalled()
	})

	it('declares the case major and then offers the working channel', async () => {
		conversation.declareMajor.mockResolvedValue('/call/major-room')
		const wrapper = await mountPanel({ objectId: 'c2' })

		await button(wrapper, 'declare-major').trigger('click')
		await flushPromises()

		expect(conversation.declareMajor).toHaveBeenCalledWith('c2')
		expect([wrapper.vm.isMajor, wrapper.vm.majorChannelUrl, wrapper.vm.declaring]).toEqual([true, '/call/major-room', false])
		expect(button(wrapper, 'declare-major').exists()).toBe(false)
		expect(button(wrapper, 'open-major-channel').exists()).toBe(true)
	})

	it('stays not major when the declaration is refused', async () => {
		conversation.declareMajor.mockRejectedValue(new Error('no responders'))
		const wrapper = await mountPanel({ caseId: 'c1' })

		await wrapper.vm.declareMajor()

		expect([wrapper.vm.isMajor, wrapper.vm.error, wrapper.vm.declaring]).toEqual([false, 'refused: no responders', false])
	})

	it('opens the one working channel in a new tab', async () => {
		const wrapper = await mountPanel({ caseId: 'c1', caseObject: { isMajor: true, majorChannel: { roomUrl: '/call/one' } } })

		await button(wrapper, 'open-major-channel').trigger('click')

		expect(window.open).toHaveBeenCalledWith('/call/one', '_blank', 'noopener')
	})

	it('opens nothing when the case has no channel url', async () => {
		const wrapper = await mountPanel({ caseId: 'c1', caseObject: { isMajor: true } })

		wrapper.vm.openMajorChannel()

		expect(window.open).not.toHaveBeenCalled()
	})

	it('keeps the open-channel button when the other buttons sit in the card title', async () => {
		const wrapper = await mountPanel({ caseId: 'c1', actionsInHeader: true, caseObject: { isMajor: true, majorChannel: { roomUrl: '/r' } } })

		expect(button(wrapper, 'start-conversation').exists()).toBe(false)
		expect(button(wrapper, 'open-major-channel').exists()).toBe(true)
	})
})
