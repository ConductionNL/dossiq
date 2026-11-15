// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The dialog that draws a recall sample: its defaults (at most 200, you as
 * the reviewer), the one post it makes, and a refusal kept in the dialog.
 *
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-the-recall-is-estimated-from-an-elusion-sample-with-its-uncertainty-req-wrs-002
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import RecallSampleDialog from '../../src/dialogs/RecallSampleDialog.vue'

const mockPost = vi.fn()
const mockUser = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { post: (...a) => mockPost(...a) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => mockUser() }))

const STUBS = {
	NcDialog: { template: '<div><slot /><slot name="actions" /></div>' },
	NcButton: { emits: ['click'], template: '<button v-bind="$attrs" @click="$emit(\'click\')"><slot /></button>' },
	NcTextField: { props: ['modelValue'], template: '<input :value="modelValue" />' },
}

/**
 * Mount the dialog.
 *
 * @param {object} props Its props.
 * @return {object} The wrapper.
 */
function mountDialog(props) {
	return mount(RecallSampleDialog, { props, global: { stubs: STUBS } })
}

describe('RecallSampleDialog', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		mockUser.mockReturnValue({ uid: 'anna' })
	})

	it('defaults to the null set capped at 200, reviewed by you', () => {
		expect(mountDialog({ caseId: 'c1', nullSetSize: 57 }).vm.size).toBe('57')

		const wrapper = mountDialog({ caseId: 'c1', nullSetSize: 900 })
		expect(wrapper.vm.size).toBe('200')
		expect(wrapper.vm.assignee).toBe('anna')
		expect(wrapper.text()).toContain('The sample is drawn from the 900 documents')
	})

	it('leaves the reviewer empty when nobody is logged in', () => {
		mockUser.mockReturnValue(null)

		expect(mountDialog({ caseId: 'c1' }).vm.assignee).toBe('')
	})

	it('posts the size as a number and the trimmed reviewer, then emits the drawn sample', async () => {
		mockPost.mockResolvedValue({ data: { id: 'sample-1', size: 25 } })
		const wrapper = mountDialog({ caseId: 'case/1', nullSetSize: 300 })
		wrapper.vm.size = '25'
		wrapper.vm.assignee = '  lars '

		await wrapper.find('[data-testid="recall-sample-draw"]').trigger('click')
		await flushPromises()

		expect(mockPost).toHaveBeenCalledWith(
			'/apps/dossiq/api/cases/case%2F1/review/recall/samples',
			{ size: 25, assignee: 'lars' },
		)
		expect(wrapper.emitted('drawn')).toEqual([[{ id: 'sample-1', size: 25 }]])
		expect(wrapper.vm.saving).toBe(false)
		expect(wrapper.find('[data-testid="recall-sample-error"]').exists()).toBe(false)
	})

	it('shows the server\'s refusal and draws nothing', async () => {
		mockPost.mockRejectedValue({ response: { data: { message: 'The sample is larger than the null set.' } } })
		const wrapper = mountDialog({ caseId: 'c1', nullSetSize: 10 })

		await wrapper.vm.draw()
		await flushPromises()

		expect(wrapper.find('[data-testid="recall-sample-error"]').text()).toBe('The sample is larger than the null set.')
		expect(wrapper.emitted('drawn')).toBeUndefined()
		expect(wrapper.vm.saving).toBe(false)
	})

	it('falls back to its own message when the server gives none', async () => {
		mockPost.mockRejectedValue(new Error('Network Error'))
		const wrapper = mountDialog({ caseId: 'c1', nullSetSize: 10 })

		await wrapper.vm.draw()

		expect(wrapper.vm.error).toBe('The Recall sample cannot be stored, so nothing was drawn or judged.')
	})

	it('closes on cancel', async () => {
		const wrapper = mountDialog({ caseId: 'c1' })

		await wrapper.find('[data-testid="recall-sample-cancel"]').trigger('click')

		expect(wrapper.emitted('close')).toHaveLength(1)
		expect(mockPost).not.toHaveBeenCalled()
	})
})
