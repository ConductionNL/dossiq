// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Choosing a message template fills the message: the select lists the case
 * type's templates that fit the channel and hands back the template rendered
 * for the case.
 *
 * @spec openspec/specs/case-messages/spec.md#requirement-choosing-a-message-template-fills-the-message
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const mockGet = vi.fn()
const mockPost = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: { get: (...a) => mockGet(...a), post: (...a) => mockPost(...a) },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
vi.mock('@nextcloud/vue', () => ({
	NcSelect: defineComponent({
		name: 'NcSelect',
		props: ['modelValue', 'options', 'inputLabel', 'labelOutside', 'inputId', 'label', 'trackBy', 'placeholder'],
		emits: ['update:modelValue'],
		render() {
			return h('div', { class: 'NcSelect' })
		},
	}),
}))

const { default: MessageTemplateSelect } = await import('../../src/components/case/MessageTemplateSelect.vue')
const { fitsChannel } = await import('../../src/services/messageTemplates.js')

const TEMPLATES = [
	{ id: 't-status', name: 'Status update', channel: 'mailbox-then-email' },
	{ id: 't-mail', name: 'Receipt by e-mail', channel: 'email' },
	{ id: 't-any', name: 'Free letter' },
]

beforeEach(() => {
	mockGet.mockReset()
	mockPost.mockReset()
	mockGet.mockResolvedValue({ data: { results: TEMPLATES } })
	mockPost.mockResolvedValue({ data: { subject: 'Uw Woo-verzoek 2026-0082', body: 'Beste mevrouw De Vries,', unresolved: [] } })
})

/**
 * Mount the select for a case.
 *
 * @param {object} props Extra props.
 * @return {Promise<object>} The wrapper.
 */
async function mountSelect(props = {}) {
	const w = mount(MessageTemplateSelect, { props: { caseId: 'c-1', caseTypeId: 'ct-1', channels: ['mailbox', 'mailbox-then-email'], ...props } })
	await flushPromises()
	return w
}

describe('MessageTemplateSelect', () => {
	it('lists the case type\'s templates that fit the channel, a template without one fits every channel', async () => {
		const w = await mountSelect()
		expect(mockGet).toHaveBeenCalledWith('/apps/dossiq/api/casetypes/ct-1/email-templates')
		expect(w.findComponent({ name: 'NcSelect' }).props('options').map((o) => o.id)).toEqual(['t-status', 't-any'])
		expect(fitsChannel({ channel: 'post' }, ['mailbox'])).toBe(false)
		expect(fitsChannel({}, ['mailbox'])).toBe(true)
		expect(fitsChannel({ channel: 'post' }, [])).toBe(true)
	})

	it('fills the message with the template rendered for this case', async () => {
		const w = await mountSelect()
		w.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', TEMPLATES[0])
		await flushPromises()
		expect(mockPost).toHaveBeenCalledWith('/apps/dossiq/api/cases/c-1/email-templates/t-status/draft')
		expect(w.emitted('fill')[0][0]).toEqual({ templateId: 't-status', subject: 'Uw Woo-verzoek 2026-0082', body: 'Beste mevrouw De Vries,' })
	})

	it('shows nothing when no template fits', async () => {
		mockGet.mockResolvedValue({ data: { results: [TEMPLATES[1]] } })
		const w = await mountSelect()
		expect(w.findComponent({ name: 'NcSelect' }).exists()).toBe(false)
	})

	it('asks for nothing without a case type', async () => {
		await mountSelect({ caseTypeId: '' })
		expect(mockGet).not.toHaveBeenCalled()
	})

	it('offers every template when the host names no channel', async () => {
		const w = mount(MessageTemplateSelect, { props: { caseId: 'c-1', caseTypeId: 'ct-1' } })
		await flushPromises()
		expect(w.findComponent({ name: 'NcSelect' }).props('options').map((o) => o.id)).toEqual(['t-status', 't-mail', 't-any'])
	})

	it('shows nothing when the templates cannot be read', async () => {
		mockGet.mockRejectedValue(new Error('offline'))
		const w = await mountSelect()
		expect(w.vm.templates).toEqual([])
		expect(w.findComponent({ name: 'NcSelect' }).exists()).toBe(false)
	})

	it('says so when the template cannot be filled in, and fills nothing', async () => {
		mockPost.mockRejectedValue(new Error('render failed'))
		const w = await mountSelect()
		w.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', TEMPLATES[2])
		await flushPromises()
		expect(w.emitted('fill')).toBeUndefined()
		expect(w.find('[role="alert"]').text()).toBe('The template could not be filled in for this case.')

		mockPost.mockResolvedValue({ data: { subject: 'S', body: 'B', unresolved: [] } })
		w.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', TEMPLATES[0])
		await flushPromises()
		expect(w.find('[role="alert"]').exists()).toBe(false)
	})

	it('renders nothing when the choice is cleared or there is no case', async () => {
		const w = await mountSelect()
		w.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', null)
		await flushPromises()
		expect(w.vm.chosen).toBeNull()

		const noCase = await mountSelect({ caseId: '' })
		noCase.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', TEMPLATES[0])
		await flushPromises()
		expect(noCase.vm.chosen).toEqual(TEMPLATES[0])
		expect(mockPost).not.toHaveBeenCalled()
		expect(noCase.emitted('fill')).toBeUndefined()
	})
})
