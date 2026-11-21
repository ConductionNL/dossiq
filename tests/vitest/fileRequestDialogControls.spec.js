// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The file request dialog through its controls, not only its methods.
 *
 * The sibling spec drives send() directly. These cases go through what the
 * handler touches: picking another party, typing the note and the window,
 * Cancel and the modal's own close, the refusal reason for a party that has
 * an address but must not be written to, and the two failure messages.
 *
 * @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-005-a-file-request-shall-be-addressed-to-a-party-of-the-case
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
 * A stub for one @nextcloud/vue control that can emit what the real one does.
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
		emits: ['update:modelValue', 'click', 'close'],
		render() {
			return h(
				'div',
				{ class: name, 'data-label': this.label },
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

const { default: FileRequestDialog } =
	await import('../../src/modals/FileRequestDialog.vue')

/**
 * Mount the dialog on a case and let the parties load.
 *
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountLoaded() {
	const wrapper = mount(FileRequestDialog, { props: { caseId: 'case-1' } })
	await flushPromises()
	return wrapper
}

/**
 * The text field whose label is the given one.
 *
 * @param {object} wrapper The mounted dialog.
 * @param {string} label The field label.
 * @return {object} The field's component wrapper.
 */
function field(wrapper, label) {
	return wrapper
		.findAllComponents({ name: 'NcTextField' })
		.find((c) => c.props('label') === label)
}

beforeEach(() => {
	mockGet.mockReset()
	mockPost.mockReset()
	mockShowSuccess.mockReset()
	mockShowError.mockReset()
	mockPost.mockResolvedValue({ data: { recipient: 'kees@example.nl' } })
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
					id: 'contact-9',
					name: 'Kees Klaassen',
					email: 'kees@example.nl',
					canBeAsked: true,
				},
				{
					id: 'contact-10',
					name: 'Anna Achter',
					email: 'anna@example.nl',
					canBeAsked: false,
					sendRefusal: 'GEHEIM',
				},
			],
		},
	})
})

describe('FileRequestDialog controls', () => {
	it('sends to the party picked on the radio, with the typed note and window', async () => {
		const wrapper = await mountLoaded()
		const radios = wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
		expect(radios).toHaveLength(3)

		radios[1].vm.$emit('update:modelValue', 'contact-9')
		field(wrapper, 'What do you need from them?').vm.$emit(
			'update:modelValue',
			'The signed permit',
		)
		field(wrapper, 'Days the request stands').vm.$emit(
			'update:modelValue',
			'3',
		)
		await wrapper.vm.$nextTick()

		const send = wrapper
			.findAllComponents({ name: 'NcButton' })
			.find((c) => c.attributes('data-testid') === 'file-request-send')
		send.vm.$emit('click')
		await flushPromises()

		expect(mockPost).toHaveBeenCalledTimes(1)
		expect(mockPost).toHaveBeenCalledWith(
			'/apps/dossiq/api/cases/case-1/file-requests',
			{ personId: 'contact-9', note: 'The signed permit', days: 3 },
		)
		expect(mockShowSuccess).toHaveBeenCalledWith(
			'The request is on its way to kees@example.nl',
		)
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('names the indicator for a party that has an address but must not be written to', async () => {
		const wrapper = await mountLoaded()

		const reasons = wrapper.findAll('[data-testid="file-request-party-reason"]')
		expect(reasons).toHaveLength(1)
		expect(reasons[0].text()).toBe(
			'Refused by the indicator GEHEIM on this party',
		)
		const radios = wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
		expect(radios[2].props('disabled')).toBe(true)
		expect(radios[0].props('disabled')).toBe(false)
	})

	it('closes on Cancel without sending anything', async () => {
		const wrapper = await mountLoaded()
		const cancel = wrapper
			.findAllComponents({ name: 'NcButton' })
			.find((c) => c.attributes('data-testid') !== 'file-request-send')

		cancel.vm.$emit('click')

		expect(wrapper.emitted('close')).toHaveLength(1)
		expect(mockPost).not.toHaveBeenCalled()
	})

	it("closes when the modal's own close fires", async () => {
		const wrapper = await mountLoaded()

		wrapper.findComponent({ name: 'NcModal' }).vm.$emit('close')

		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('says the parties could not be read when the listing fails', async () => {
		mockGet.mockRejectedValue(new Error('500'))
		const wrapper = await mountLoaded()

		expect(wrapper.vm.parties).toEqual([])
		expect(wrapper.vm.selected).toBe('')
		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.find('[data-testid="file-request-error"]').text()).toBe(
			'The parties of this case could not be read',
		)
	})

	it('treats a listing without a parties array as nobody linked', async () => {
		mockGet.mockResolvedValue({ data: { parties: 'nope' } })
		const wrapper = await mountLoaded()

		expect(wrapper.vm.parties).toEqual([])
		expect(wrapper.find('[data-testid="file-request-empty"]').exists()).toBe(
			true,
		)
	})

	it('falls back to its own message when the server gives no reason', async () => {
		mockPost.mockRejectedValue(new Error('network'))
		const wrapper = await mountLoaded()

		await wrapper.vm.send()

		expect(wrapper.vm.error).toBe('The request could not be sent')
		expect(mockShowError).toHaveBeenCalledWith('The request could not be sent')
		expect(wrapper.vm.sending).toBe(false)
		expect(wrapper.emitted('close')).toBeFalsy()
	})

	it('sends a zero window when the days field holds no number', async () => {
		const wrapper = await mountLoaded()
		await wrapper.setData({ days: 'abc' })

		await wrapper.vm.send()

		expect(mockPost.mock.calls[0][1].days).toBe(0)
	})
})
