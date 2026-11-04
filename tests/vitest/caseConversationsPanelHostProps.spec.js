// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A manifest card renders CaseConversationsPanel through CnDetailWidgetHost,
 * which hands registry widgets `objectId` and `objectData`, not `caseId` and
 * `caseObject`. The card reads the case's conversations from either pair.
 *
 * @spec openspec/specs/case-management/spec.md
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const mockGet = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: { get: (...a) => mockGet(...a), post: vi.fn() },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
vi.mock('@nextcloud/l10n', () => ({ getLanguage: () => 'en', translate: (_app, s) => s, translatePlural: (_app, one, other, n) => (n === 1 ? one : other) }))
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
vi.mock('../../src/utils/caseConversation.js', () => ({
	conversationError: () => '',
	declareMajor: vi.fn(),
	startConversation: vi.fn(),
}))

const { default: CaseConversationsPanel } =
	await import('../../src/views/cases/components/CaseConversationsPanel.vue')

const seededCase = {
	id: '710d44a4-0000-4000-8000-000000000000',
	conversations: [
		{
			roomId: '184',
			subject: 'Gesprek met Sanne de Vries over de stukken',
			participants: ['Pieter Jansen', 'Sanne de Vries'],
			durationSeconds: 720,
		},
	],
	isMajor: true,
	majorChannel: { roomUrl: '/call/major' },
}

function mountPanel(propsData) {
	return shallowMount(CaseConversationsPanel, {
		propsData,
		mocks: { t: (_app, s) => s, $route: { params: {} } },
	})
}

describe('CaseConversationsPanel on a manifest card', () => {
	beforeEach(() => {
		mockGet.mockReset()
		mockGet.mockResolvedValue({ data: { available: true } })
	})

	it('reads the conversations from the host\'s objectData', async () => {
		const wrapper = mountPanel({ objectId: seededCase.id, objectData: seededCase })
		await flushPromises()

		expect(wrapper.vm.conversations).toHaveLength(1)
		expect(wrapper.find('[data-testid="conversation-records"]').exists()).toBe(true)
		expect(wrapper.text()).toContain('Gesprek met Sanne de Vries over de stukken')
		expect(wrapper.vm.isMajor).toBe(true)
		expect(wrapper.vm.majorChannelUrl).toBe('/call/major')
	})

	it('takes the case id from the host\'s objectId', () => {
		const wrapper = mountPanel({ objectId: seededCase.id })

		expect(wrapper.vm.resolvedCaseId).toBe(seededCase.id)
	})

	it('picks up objectData that arrives after mount', async () => {
		const wrapper = mountPanel({ objectId: seededCase.id, objectData: null })
		await flushPromises()
		expect(wrapper.vm.conversations).toHaveLength(0)

		await wrapper.setProps({ objectData: seededCase })

		expect(wrapper.vm.conversations).toHaveLength(1)
	})

	it('still reads caseId and caseObject passed inline', async () => {
		const wrapper = mountPanel({ caseId: 'inline-id', caseObject: seededCase })
		await flushPromises()

		expect(wrapper.vm.resolvedCaseId).toBe('inline-id')
		expect(wrapper.vm.conversations).toHaveLength(1)
	})
})

describe('a held conversation as DqZaakContact "Live gesprek" draws it', () => {
	beforeEach(() => {
		mockGet.mockReset()
		mockGet.mockResolvedValue({ data: { available: true } })
	})

	const held = {
		...seededCase,
		isMajor: false,
		conversations: [{
			roomId: '184',
			subject: 'Gesprek met Sanne de Vries over de stukken',
			startedAt: '2026-10-04T10:30:00',
			endedAt: '2026-10-04T10:42:00',
			participants: ['Pieter Jansen', 'Sanne de Vries'],
			durationSeconds: 720,
		}],
	}

	it('leaves out the empty actions row when the buttons stand in the card title', async () => {
		const wrapper = mountPanel({ objectData: held, actionsInHeader: true })
		await flushPromises()
		expect(wrapper.find('.case-conversations__actions').exists()).toBe(false)
	})

	it('marks an ended conversation with a status pill', async () => {
		const wrapper = mountPanel({ objectData: held, actionsInHeader: true })
		await flushPromises()
		const badge = wrapper.findComponent({ name: 'CnStatusBadge' })
		expect(badge.exists()).toBe(true)
		expect(badge.props('label')).toBe('Conversation over')
	})

	it('says under the list what the list holds', async () => {
		const wrapper = mountPanel({ objectData: held, actionsInHeader: true })
		await flushPromises()
		expect(wrapper.find('.case-conversations__footnote').text())
			.toBe('Every conversation you start from this case lands here: when it was held, who joined and how long it lasted.')
	})
})
