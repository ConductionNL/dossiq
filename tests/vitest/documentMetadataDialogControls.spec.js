// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DocumentMetadataDialog through its controls: every picker writes back the
 * value the dialog submits (the reduce on the id pickers keeps the id, not
 * the option object), the text fields write the title and description, and
 * Cancel and the modal's own close both close it without saving.
 *
 * @spec openspec/specs/document-zaakdossier/spec.md
 */


import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const mockGet = vi.fn()
const mockPost = vi.fn()
const mockPatch = vi.fn()

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: (...a) => mockGet(...a),
		post: (...a) => mockPost(...a),
		patch: (...a) => mockPatch(...a),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
const mockShowError = vi.fn()
const mockShowSuccess = vi.fn()
vi.mock('@nextcloud/dialogs', () => ({
	showError: (...a) => mockShowError(...a),
	showSuccess: (...a) => mockShowSuccess(...a),
}))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))

/**
 * A form-control stub that keeps its v-model and forwards a click.
 *
 * @param {string} name The component name.
 * @return {object} The stub component.
 */
function control(name) {
	return defineComponent({
		name,
		props: [
			'modelValue', 'inputLabel', 'label', 'options', 'reduce', 'clearable',
			'required', 'placeholder', 'multiple', 'taggable', 'pushTags', 'size',
			'value', 'error', 'disabled', 'type',
		],
		emits: ['update:modelValue', 'click'],
		render() {
			return h('div', {
				class: name,
				onClick: () => this.$emit('click'),
			}, this.$slots.default ? this.$slots.default() : [])
		},
	})
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: control('NcButton'),
	NcEmptyContent: control('NcEmptyContent'),
	NcModal: control('NcModal'),
	NcProgressBar: control('NcProgressBar'),
	NcSelect: control('NcSelect'),
	NcTextArea: control('NcTextArea'),
	NcTextField: control('NcTextField'),
}))

const { default: DocumentMetadataDialog } =
	await import('../../src/modals/DocumentMetadataDialog.vue')

beforeEach(() => {
	mockGet.mockReset()
	mockPost.mockReset()
	mockPatch.mockReset()
	mockShowError.mockReset()
	mockShowSuccess.mockReset()
	mockGet.mockResolvedValue({ data: { results: [] } })
	mockPost.mockResolvedValue({ data: {} })
	mockPatch.mockResolvedValue({ data: {} })
})


/**
 * Mount the dialog for an upload and let its reads settle.
 *
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountUpload() {
	const wrapper = mount(DocumentMetadataDialog, {
		props: { files: [], caseId: 'case-1' },
	})
	await flushPromises()
	return wrapper
}

/**
 * The picker carrying the given test id.
 *
 * @param {object} wrapper The mounted dialog.
 * @param {string} testId The data-testid.
 * @return {object} The picker's component wrapper.
 */
function picker(wrapper, testId) {
	return wrapper
		.findAllComponents({ name: 'NcSelect' })
		.find((c) => c.attributes('data-testid') === testId)
}

describe('DocumentMetadataDialog controls', () => {
	it('keeps the id from the type, direction and confidentiality pickers', async () => {
		const wrapper = await mountUpload()
		const idPickers = wrapper
			.findAllComponents({ name: 'NcSelect' })
			.filter((c) => typeof c.props('reduce') === 'function')

		// Type, direction and confidentiality reduce their option to its id.
		expect(idPickers).toHaveLength(3)
		for (const select of idPickers) {
			expect(select.props('reduce')({ id: 'x-1', label: 'X' })).toBe('x-1')
		}

		idPickers[0].vm.$emit('update:modelValue', 'iot-7')
		idPickers[1].vm.$emit('update:modelValue', 'incoming')
		idPickers[2].vm.$emit('update:modelValue', 'zaakvertrouwelijk')
		await flushPromises()

		expect(wrapper.vm.selectedType).toBe('iot-7')
		expect(wrapper.vm.selectedDirection).toBe('incoming')
		expect(wrapper.vm.selectedClassification).toBe('zaakvertrouwelijk')
		expect(wrapper.vm.canSubmit).toBe(true)
	})

	it('shows the sender and recipients pickers for an internal document and writes both back', async () => {
		const wrapper = await mountUpload()
		const sender = picker(wrapper, 'document-sender')
		const recipients = picker(wrapper, 'document-recipients')
		expect(sender).toBeTruthy()
		expect(recipients).toBeTruthy()

		const piet = { id: 'p-1', label: 'Piet' }
		const kees = { id: 'p-2', label: 'Kees' }
		sender.vm.$emit('update:modelValue', piet)
		recipients.vm.$emit('update:modelValue', [kees])
		await flushPromises()

		expect(wrapper.vm.sender).toEqual(piet)
		expect(wrapper.vm.recipients).toEqual([kees])
	})

	it('drops the sender picker once the direction says outgoing', async () => {
		const wrapper = await mountUpload()

		picker(wrapper, 'document-direction').vm.$emit(
			'update:modelValue',
			'outgoing',
		)
		await flushPromises()

		expect(picker(wrapper, 'document-sender')).toBeUndefined()
		expect(picker(wrapper, 'document-recipients')).toBeTruthy()
	})

	it('writes the keywords, title and description back', async () => {
		const wrapper = await mountUpload()

		picker(wrapper, 'document-keywords').vm.$emit('update:modelValue', [
			'bouw',
			'advies',
		])
		wrapper
			.findComponent({ name: 'NcTextField' })
			.vm.$emit('update:modelValue', 'Advies welstand')
		wrapper
			.findComponent({ name: 'NcTextArea' })
			.vm.$emit('update:modelValue', 'Het advies van de commissie')
		await flushPromises()

		expect(wrapper.vm.keywords).toEqual(['bouw', 'advies'])
		expect(wrapper.vm.title).toBe('Advies welstand')
		expect(wrapper.vm.description).toBe('Het advies van de commissie')
	})

	it('closes on Cancel and on the modal close, without posting anything', async () => {
		const wrapper = await mountUpload()
		const cancel = wrapper
			.findAllComponents({ name: 'NcButton' })
			.find((c) => c.attributes('data-testid') !== 'document-properties-save')

		cancel.vm.$emit('click')
		wrapper.findComponent({ name: 'NcModal' }).vm.$emit('close')

		expect(wrapper.emitted('close')).toHaveLength(2)
		expect(mockPost).not.toHaveBeenCalled()
		expect(mockPatch).not.toHaveBeenCalled()
	})
})
