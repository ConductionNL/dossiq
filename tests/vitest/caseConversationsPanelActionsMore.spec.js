// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * CaseConversationsPanel's own buttons: whether Talk is there at all, starting
 * a conversation from the case, declaring the case major and opening the one
 * working channel. Only the HTTP boundary and Nextcloud globals are mocked;
 * the panel runs the real utils/caseConversation.js against them.
 *
 * @spec openspec/specs/case-management/spec.md
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const mockGet = vi.fn()
const mockPost = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: { get: (...a) => mockGet(...a), post: (...a) => mockPost(...a) },
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params = {}) => url.replace('{caseId}', params.caseId),
}))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn(), subscribe: vi.fn(), unsubscribe: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({
	getLanguage: () => 'en',
	translate: (_app, s, vars = {}) => s.replace(/\{(\w+)\}/g, (m, k) => vars[k] ?? m),
	translatePlural: (_app, one, other, n) => (n === 1 ? one : other),
}))
vi.mock('@nextcloud/vue', () => {
	const stub = (name) => ({ name, render: () => null })
	return {
		NcButton: stub('NcButton'),
		NcEmptyContent: stub('NcEmptyContent'),
		NcLoadingIcon: stub('NcLoadingIcon'),
		NcNoteCard: stub('NcNoteCard'),
	}
})
vi.mock('@conduction/nextcloud-vue', () => ({
	CnStatusBadge: { name: 'CnStatusBadge', props: ['label', 'variant', 'size'], render: () => null },
}))

const { default: CaseConversationsPanel } =
	await import('../../src/views/cases/components/CaseConversationsPanel.vue')

const CASE_ID = '710d44a4-0000-4000-8000-000000000000'

function mountPanel(propsData = {}) {
	return shallowMount(CaseConversationsPanel, {
		propsData,
		mocks: { t: (_app, s) => s, $route: { params: {} } },
	})
}

describe('CaseConversationsPanel asks whether Talk is there', () => {
	beforeEach(() => {
		mockGet.mockReset()
		mockPost.mockReset()
	})

	it('asks the availability endpoint once on mount and stops loading', async () => {
		mockGet.mockResolvedValue({ data: { available: true } })
		const wrapper = mountPanel({ caseId: CASE_ID })
		expect(wrapper.vm.loading).toBe(true)
		await flushPromises()

		expect(mockGet).toHaveBeenCalledTimes(1)
		expect(mockGet).toHaveBeenCalledWith('/apps/dossiq/api/conversations/availability')
		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.vm.talkUnavailable).toBe(false)
	})

	it('says Talk is not available when the instance answers anything but available: true', async () => {
		mockGet.mockResolvedValue({ data: { available: 'yes' } })
		const wrapper = mountPanel({ caseId: CASE_ID })
		await flushPromises()

		expect(wrapper.vm.talkUnavailable).toBe(true)
		const empty = wrapper.findComponent({ name: 'NcEmptyContent' })
		expect(empty.attributes('name')).toBe('Talk is not available')
		expect(wrapper.find('.case-conversations__actions').exists()).toBe(false)
	})

	it('treats a failing availability request as no Talk, and stops loading', async () => {
		mockGet.mockRejectedValue(new Error('network'))
		const wrapper = mountPanel({ caseId: CASE_ID })
		await flushPromises()

		expect(wrapper.vm.talkUnavailable).toBe(true)
		expect(wrapper.vm.loading).toBe(false)
	})
})

describe('CaseConversationsPanel starts a conversation', () => {
	beforeEach(() => {
		mockGet.mockReset()
		mockPost.mockReset()
		mockGet.mockResolvedValue({ data: { available: true } })
		window.open = vi.fn()
	})

	afterEach(() => {
		delete window.open
	})

	it('posts to the case, opens the room and appends the recorded conversation', async () => {
		const existing = { roomId: '1', subject: 'Earlier', participants: [] }
		const started = { roomId: '2', subject: 'New', roomUrl: 'https://talk/2', participants: [] }
		mockPost.mockResolvedValue({ data: { conversation: started } })
		const wrapper = mountPanel({ caseId: CASE_ID, caseObject: { conversations: [existing] } })
		await flushPromises()

		await wrapper.vm.startConversation()

		expect(mockPost).toHaveBeenCalledWith(`/apps/dossiq/api/cases/${CASE_ID}/conversations`, {})
		expect(window.open).toHaveBeenCalledWith('https://talk/2', '_blank', 'noopener')
		expect(wrapper.vm.conversations).toEqual([existing, started])
		expect(wrapper.vm.starting).toBe(false)
		expect(wrapper.vm.error).toBe('')
	})

	it('adds nothing when the server records no conversation', async () => {
		mockPost.mockResolvedValue({ data: {} })
		const wrapper = mountPanel({ caseId: CASE_ID })
		await flushPromises()

		await wrapper.vm.startConversation()

		expect(wrapper.vm.conversations).toEqual([])
		expect(window.open).not.toHaveBeenCalled()
	})

	it('shows the server\'s reason when the start is refused, and clears an earlier error first', async () => {
		mockPost.mockRejectedValue({ response: { data: { reason: 'Talk refused the room' } } })
		const wrapper = mountPanel({ caseId: CASE_ID })
		await flushPromises()
		wrapper.vm.error = 'stale'

		await wrapper.vm.startConversation()

		expect(wrapper.vm.error).toBe('Talk refused the room')
		expect(wrapper.vm.starting).toBe(false)
		const note = wrapper.findComponent({ name: 'NcNoteCard' })
		expect(note.exists()).toBe(true)
		expect(note.attributes('heading')).toBe('We could not start the conversation')
	})

	it('does nothing without a case id', async () => {
		const wrapper = mountPanel({})
		await flushPromises()

		await wrapper.vm.startConversation()

		expect(mockPost).not.toHaveBeenCalled()
		expect(wrapper.vm.starting).toBe(false)
	})
})

describe('CaseConversationsPanel declares the case major', () => {
	beforeEach(() => {
		mockGet.mockReset()
		mockPost.mockReset()
		mockGet.mockResolvedValue({ data: { available: true } })
		window.open = vi.fn()
	})

	afterEach(() => {
		delete window.open
	})

	it('posts the declaration, keeps the channel address and marks the case major', async () => {
		mockPost.mockResolvedValue({ data: { channel: { roomUrl: 'https://talk/major' } } })
		const wrapper = mountPanel({ caseId: CASE_ID })
		await flushPromises()

		await wrapper.vm.declareMajor()

		expect(mockPost).toHaveBeenCalledWith(`/apps/dossiq/api/cases/${CASE_ID}/major`, {})
		expect(wrapper.vm.isMajor).toBe(true)
		expect(wrapper.vm.majorChannelUrl).toBe('https://talk/major')
		expect(wrapper.vm.declaring).toBe(false)
		expect(wrapper.find('[data-testid="open-major-channel"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="declare-major"]').exists()).toBe(false)
	})

	it('names the responders it could not find and leaves the case not major', async () => {
		mockPost.mockRejectedValue({ response: { data: { unresolved: ['Team brandweer', 'GHOR'] } } })
		const wrapper = mountPanel({ caseId: CASE_ID })
		await flushPromises()

		await wrapper.vm.declareMajor()

		expect(wrapper.vm.error).toBe('We could not find these responders: Team brandweer, GHOR')
		expect(wrapper.vm.isMajor).toBe(false)
		expect(wrapper.vm.declaring).toBe(false)
	})

	it('reads "Unknown error" when the refusal carries no reason', async () => {
		mockPost.mockRejectedValue(new Error('boom'))
		const wrapper = mountPanel({ caseId: CASE_ID })
		await flushPromises()

		await wrapper.vm.declareMajor()

		expect(wrapper.vm.error).toBe('Unknown error')
	})

	it('does nothing without a case id', async () => {
		const wrapper = mountPanel({})
		await flushPromises()

		await wrapper.vm.declareMajor()

		expect(mockPost).not.toHaveBeenCalled()
		expect(wrapper.vm.isMajor).toBe(false)
	})
})

describe('CaseConversationsPanel opens the working channel', () => {
	beforeEach(() => {
		mockGet.mockReset()
		mockGet.mockResolvedValue({ data: { available: true } })
		window.open = vi.fn()
	})

	afterEach(() => {
		delete window.open
	})

	it('opens the one channel the case has in a new tab', async () => {
		const wrapper = mountPanel({
			caseId: CASE_ID,
			caseObject: { isMajor: true, majorChannel: { roomUrl: 'https://talk/major' } },
		})
		await flushPromises()

		wrapper.vm.openMajorChannel()

		expect(window.open).toHaveBeenCalledWith('https://talk/major', '_blank', 'noopener')
	})

	it('opens nothing while the case has no channel address', async () => {
		const wrapper = mountPanel({ caseId: CASE_ID, caseObject: { isMajor: true } })
		await flushPromises()

		wrapper.vm.openMajorChannel()

		expect(wrapper.vm.majorChannelUrl).toBe('')
		expect(window.open).not.toHaveBeenCalled()
	})

	it('reads a non-array conversations field as no conversations and isMajor only when true', async () => {
		const wrapper = mountPanel({ caseId: CASE_ID, caseObject: { conversations: 'x', isMajor: 'true' } })
		await flushPromises()

		expect(wrapper.vm.conversations).toEqual([])
		expect(wrapper.vm.isMajor).toBe(false)
	})
})
