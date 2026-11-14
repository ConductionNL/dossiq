// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * FileRequestDialog, the branches the behaviour spec does not reach: the
 * refusal reason a send-indicator gives, and the parties listing failing to
 * read.
 *
 * @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-005-a-file-request-shall-be-addressed-to-a-party-of-the-case
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
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		String(text).replace(/\{(\w+)\}/g, (_, key) => vars?.[key] ?? ''),
}))

/**
 * A minimal control stub.
 *
 * @param {string} name The component name.
 * @return {object} The stub component.
 */
function control(name) {
	return defineComponent({
		name,
		props: ['modelValue', 'value', 'label', 'placeholder', 'type', 'name', 'disabled', 'size', 'variant'],
		emits: ['update:modelValue', 'click'],
		render() {
			return h('div', { class: name, onClick: () => this.$emit('click') },
				this.$slots.default ? this.$slots.default() : [])
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

const { default: FileRequestDialog } = await import('../../src/modals/FileRequestDialog.vue')

beforeEach(() => {
	mockGet.mockReset()
	mockPost.mockReset()
	mockGet.mockResolvedValue({ data: { parties: [] } })
})

describe('FileRequestDialog uncovered branches', () => {
	it('names the indicator that refused a party, rather than the no-address reason', async () => {
		const wrapper = mount(FileRequestDialog, { props: { caseId: 'case-1' } })
		await flushPromises()

		expect(wrapper.vm.reasonFor({ sendRefusal: 'geheimhouding' }))
			.toBe('Refused by the indicator geheimhouding on this party')
		expect(wrapper.vm.reasonFor({}))
			.toBe('No email address, so this party cannot be asked')
	})

	it('reads no parties and says so when the listing fails', async () => {
		const wrapper = mount(FileRequestDialog, { props: { caseId: 'case-1' } })
		await flushPromises()

		mockGet.mockRejectedValueOnce(new Error('offline'))
		await wrapper.vm.loadParties()

		expect(wrapper.vm.parties).toEqual([])
		expect(wrapper.vm.selected).toBe('')
		expect(wrapper.vm.error).toBe('The parties of this case could not be read')
		expect(wrapper.vm.loading).toBe(false)
	})
})
