// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The Link to case dialog over the inbox (board DqKoppelAanZaak): the case the
 * subject names is the chosen proposal, the button names its number, linking
 * to it gives the proposal as the reason and carries the two choices, and
 * linking to another case asks why before it may go.
 *
 * @spec openspec/specs/case-email-integration/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const mockGet = vi.fn()
const mockPost = vi.fn()
const mockEmit = vi.fn()

vi.mock('@nextcloud/axios', () => ({
	default: { get: (...a) => mockGet(...a), post: (...a) => mockPost(...a) },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u, generateOcsUrl: (u) => `/ocs/${u}` }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit: (...a) => mockEmit(...a) }))
const mockUser = { value: { uid: 'pieter', displayName: 'Pieter Jansen' } }
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => mockUser.value }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) => String(text).replace(/\{(\w+)\}/g, (_, key) => vars?.[key] ?? ''),
	translatePlural: (app, one, many, count, vars) => String(count === 1 ? one : many).replace('%n', count).replace(/\{(\w+)\}/g, (_, key) => vars?.[key] ?? ''),
}))

/**
 * A stub for one @nextcloud/vue control that keeps its slot and clicks.
 *
 * @param {string} name The component name.
 * @return {object} The stub component.
 */
function control(name) {
	return defineComponent({
		name,
		props: ['modelValue', 'label', 'placeholder', 'disabled', 'size', 'variant', 'name'],
		emits: ['update:modelValue', 'click'],
		render() {
			return h('div', { class: name, onClick: () => this.$emit('click') }, [
				this.$slots.icon ? this.$slots.icon() : null,
				this.$slots.default ? this.$slots.default() : null,
			])
		},
	})
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: control('NcButton'),
	NcCheckboxRadioSwitch: control('NcCheckboxRadioSwitch'),
	NcModal: control('NcModal'),
	NcTextField: control('NcTextField'),
}))

const { default: LinkToCaseDialog, caseRow } = await import('../../src/modals/LinkToCaseDialog.vue')

const ROW = {
	subject: 'Vraag over mijn kapvergunning 2026-0206',
	sender: 'henk.bakker@example.org',
	senderName: 'Henk Bakker',
	receivedAt: '2026-11-23T08:42:00+00:00',
	channel: 'email',
	recipient: 'zaken@zuiddrecht.example',
	preview: 'Goedemorgen, drie weken geleden vroeg ik een kapvergunning aan.',
	attachments: ['eik-achtertuin-1.jpg', 'eik-achtertuin-2.jpg'],
	suggestion: { case: 'case-206', identifier: '2026-0206', title: 'Kapvergunning eik Dorpsstraat 14' },
}

beforeEach(() => {
	mockGet.mockReset()
	mockPost.mockReset()
	mockEmit.mockReset()
	mockGet.mockImplementation((url) => {
		if (url.startsWith('/ocs/')) {
			return Promise.resolve({ data: { ocs: { data: [{ id: 'anouk', label: 'Anouk Bakker' }] } } })
		}
		return Promise.resolve({
			data: {
				identifier: '2026-0206',
				title: 'Kapvergunning eik Dorpsstraat 14',
				status: { name: 'In behandeling' },
				caseType: { title: 'Kapvergunning' },
				initiatorDisplayName: 'Henk Bakker',
				assignee: 'anouk',
			},
		})
	})
	mockPost.mockResolvedValue({ data: { filed: true } })
})

/**
 * The dialog over the row, after its reads.
 *
 * @return {Promise<object>} The wrapper.
 */
async function dialog() {
	const wrapper = mount(LinkToCaseDialog, { props: { object: ROW, objectId: 'entry-1' } })
	await flushPromises()
	return wrapper
}

describe('LinkToCaseDialog', () => {
	it('shows the message with its attachments and the proposal as the chosen case', async () => {
		const wrapper = await dialog()

		// The theme sizes every paragraph and heading outside .cn-look-board; the dialog is drawn in the board look.
		expect(wrapper.find('[data-testid="link-to-case-dialog"]').classes()).toContain('cn-look-board')
		expect(wrapper.find('[data-testid="link-to-case-message"]').text()).toContain('Vraag over mijn kapvergunning 2026-0206')
		expect(wrapper.find('[data-testid="link-to-case-attachments"]').text()).toContain('2 attachments go with it: eik-achtertuin-1.jpg, eik-achtertuin-2.jpg')
		const proposal = wrapper.find('[data-testid="link-to-case-proposal"]')
		expect(proposal.classes()).toContain('link-to-case__case--chosen')
		expect(proposal.text()).toContain('Kapvergunning · Henk Bakker · handler Anouk Bakker')
		expect(wrapper.find('[data-testid="link-to-case-confirm"]').text()).toContain('Link to case 2026-0206')
		expect(wrapper.find('[data-testid="link-to-case-notify"]').text()).toContain('Tell Anouk Bakker a message was added')
	})

	it('closes on Escape, also while the search field has the focus', async () => {
		const wrapper = await dialog()
		await wrapper.find('[data-testid="link-to-case-dialog"] input[type="search"], [data-testid="link-to-case-dialog"] input').trigger('keydown', { key: 'Escape' })
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('links to the proposal with the proposal as its reason and both choices', async () => {
		const wrapper = await dialog()

		await wrapper.find('[data-testid="link-to-case-confirm"]').trigger('click')
		await flushPromises()

		expect(mockPost).toHaveBeenCalledWith('/apps/dossiq/api/mail-intake/log/entry-1/file-on-case', {
			caseId: 'case-206',
			reason: 'The case number is in the subject',
			addSender: true,
			notifyHandler: true,
		})
		expect(mockEmit).toHaveBeenCalledWith('cn:page:refresh', {})
		expect(wrapper.emitted('close')).toBeTruthy()
	})

	it('asks why before it links to another case than the proposal', async () => {
		const wrapper = await dialog()

		wrapper.vm.pick({ id: 'case-300', identifier: '2026-0300', title: 'Iets anders', assignee: '' })
		await flushPromises()

		expect(wrapper.find('[data-testid="link-to-case-reason"]').exists()).toBe(true)
		expect(wrapper.vm.canLink).toBe(false)
		wrapper.vm.reason = 'Hoort bij de bouwaanvraag'
		expect(wrapper.vm.canLink).toBe(true)
	})

	it('names the reader as the handler and shows the notice disabled, with why', async () => {
		mockGet.mockImplementation(() => Promise.resolve({ data: { identifier: '2026-4833', title: 'Lindelaan', status: { name: 'Received' }, caseType: { title: 'Complaint handling' }, assignee: 'pieter' } }))
		const wrapper = await dialog()

		expect(wrapper.find('[data-testid="link-to-case-proposal"]').text()).toContain('handler Pieter Jansen')
		expect(wrapper.find('[data-testid="link-to-case-notify"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="link-to-case-notify-note"]').text()).toContain('You handle this case yourself')
		await wrapper.find('[data-testid="link-to-case-confirm"]').trigger('click')
		await flushPromises()
		expect(mockPost.mock.calls[0][1].notifyHandler).toBe(false)
	})

	it('reads a stored case into the row the dialog shows', () => {
		expect(caseRow({ '@self': { id: 'c-1' }, identifier: '2026-1', title: 'T', status: { name: 'Open' }, caseType: { title: 'Type' }, assignee: 'pieter' }))
			.toEqual({ id: 'c-1', identifier: '2026-1', title: 'T', status: 'Open', type: 'Type', requester: '', assignee: 'pieter' })
	})
})
