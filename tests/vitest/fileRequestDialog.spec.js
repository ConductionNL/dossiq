// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The file request names a party of the case.
 *
 * Nextcloud's own file request asks for an address the handler has to know by
 * heart, which is the failure this dialog exists to end: the recipients are
 * the people linked to the case, and a party with no address is listed and
 * disabled with the reason rather than hidden. A dialog that hid them would
 * reproduce exactly the "nobody to send to" it replaces.
 *
 * @spec openspec/changes/people-on-the-case/specs/people-on-the-case/spec.md#requirement-req-poc-005-a-file-request-shall-be-addressed-to-a-party-of-the-case
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const mockGet = vi.fn()
const mockPost = vi.fn()
const mockShowSuccess = vi.fn()
const mockShowError = vi.fn()

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: (...a) => mockGet(...a),
		post: (...a) => mockPost(...a),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: (...a) => mockShowSuccess(...a),
	showError: (...a) => mockShowError(...a),
}))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		String(text).replace(/\{(\w+)\}/g, (_, key) => vars?.[key] ?? ''),
}))

/**
 * A stub for one @nextcloud/vue control that keeps its v-model and attributes.
 *
 * @param {string} name The component name.
 * @return {object} The stub component.
 */
function control(name) {
	return defineComponent({
		name,
		props: [
			'modelValue',
			'value',
			'label',
			'placeholder',
			'type',
			'name',
			'disabled',
			'size',
			'variant',
		],
		emits: ['update:modelValue', 'click'],
		render() {
			return h(
				'div',
				{
					class: name,
					'data-label': this.label,
					'data-disabled': this.disabled ? 'true' : 'false',
					onClick: () => this.$emit('click'),
				},
				this.$slots.default ? this.$slots.default() : [],
			)
		},
	})
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: control('NcButton'),
	NcCheckboxRadioSwitch: control('NcCheckboxRadioSwitch'),
	NcLoadingIcon: control('NcLoadingIcon'),
	NcModal: control('NcModal'),
	NcTextField: control('NcTextField'),
}))

// Imported AFTER the mocks so the dialog sees the stubbed packages.
const { default: FileRequestDialog } =
	await import('../../src/modals/FileRequestDialog.vue')

/**
 * Mount the dialog on a case, the way the registry mounts it.
 *
 * @return {object} The mounted wrapper.
 */
function mountDialog() {
	return mount(FileRequestDialog, { props: { caseId: 'case-1' } })
}

beforeEach(() => {
	mockGet.mockReset()
	mockPost.mockReset()
	mockShowSuccess.mockReset()
	mockShowError.mockReset()
	mockPost.mockResolvedValue({ data: { recipient: 'piet@example.nl' } })
	mockGet.mockResolvedValue({
		data: {
			parties: [
				{
					id: 'contact-8',
					name: 'Piet Pietersen',
					email: 'piet@example.nl',
					canBeAsked: true,
				},
				{
					id: 'user:jan',
					name: 'Jan de Vries',
					email: '',
					canBeAsked: false,
				},
			],
		},
	})
})

describe('FileRequestDialog', () => {
	it('lists every party and disables the one with no address, with the reason', async () => {
		const wrapper = mountDialog()
		await flushPromises()

		expect(mockGet).toHaveBeenCalledWith(
			'/apps/dossiq/api/cases/case-1/file-requests/parties',
		)
		const askable = wrapper.findAll('[data-testid="file-request-party"]')
		const unavailable = wrapper.findAll(
			'[data-testid="file-request-party-unavailable"]',
		)
		expect(askable).toHaveLength(1)
		expect(unavailable).toHaveLength(1)
		expect(unavailable[0].text()).toContain('Jan de Vries')
		expect(unavailable[0].text()).toContain('No email address')
		// The first party who can be asked is preselected, so the common case
		// is one click.
		expect(wrapper.vm.selected).toBe('contact-8')
	})

	it('sends the request to the selected party with the note and the window', async () => {
		const wrapper = mountDialog()
		await flushPromises()

		await wrapper.setData({ note: 'The lease, please', days: '7' })
		await wrapper.vm.send()

		expect(mockPost).toHaveBeenCalledWith(
			'/apps/dossiq/api/cases/case-1/file-requests',
			{
				personId: 'contact-8',
				note: 'The lease, please',
				days: 7,
			},
		)
		expect(mockShowSuccess).toHaveBeenCalled()
		expect(wrapper.emitted('close')).toBeTruthy()
	})

	it("shows the server's reason when the request cannot be sent, and stays open", async () => {
		mockPost.mockRejectedValue({
			response: {
				data: {
					error: 'This person has no email address, so there is nobody to send the request to',
				},
			},
		})
		const wrapper = mountDialog()
		await flushPromises()

		await wrapper.vm.send()

		expect(wrapper.find('[data-testid="file-request-error"]').text()).toContain(
			'no email address',
		)
		expect(wrapper.emitted('close')).toBeFalsy()
	})

	it('says so when nobody is linked to the case yet', async () => {
		mockGet.mockResolvedValue({ data: { parties: [] } })
		const wrapper = mountDialog()
		await flushPromises()

		expect(wrapper.find('[data-testid="file-request-empty"]').exists()).toBe(
			true,
		)
		expect(wrapper.vm.selected).toBe('')
	})

	it('sends nothing while no party is selected', async () => {
		mockGet.mockResolvedValue({
			data: {
				parties: [
					{ id: 'user:jan', name: 'Jan', email: '', canBeAsked: false },
				],
			},
		})
		const wrapper = mountDialog()
		await flushPromises()

		await wrapper.vm.send()

		expect(mockPost).not.toHaveBeenCalled()
	})
})
