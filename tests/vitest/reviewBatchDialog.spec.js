// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The review batch dialog: ticked documents, or a filter by custodian, source system or rule.
 *
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-batches-are-assigned-to-named-reviewers-before-any-verdict-req-wrt-003
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it } from 'vitest'

const { default: ReviewBatchDialog } =
	await import('../../src/dialogs/ReviewBatchDialog.vue')

/**
 * Mount the dialog on case X with two open documents.
 *
 * @return {object} The wrapper.
 */
function mountDialog() {
	return mount(ReviewBatchDialog, {
		props: { caseId: 'case-x', documents: ['d1', 'd2'] },
		global: {
			stubs: {
				NcDialog: { template: '<div><slot /><slot name="actions" /></div>' },
				NcButton: { template: '<button v-bind="$attrs"><slot /></button>' },
				NcCheckboxRadioSwitch: { template: '<label><slot /></label>' },
				NcTextField: {
					props: ['modelValue', 'label'],
					emits: ['update:modelValue'],
					template:
						'<input v-bind="$attrs" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
				},
			},
		},
	})
}

describe('ReviewBatchDialog', () => {
	beforeEach(() => {
		axios.post.mockReset()
		axios.post.mockResolvedValue({ data: { batch: { id: 'b-1' } } })
	})

	it('sends the ticked documents when no filter is filled in', async () => {
		const wrapper = mountDialog()
		await wrapper.find('[data-testid="review-batch-name"]').setValue('Mail 2025')
		await wrapper.find('[data-testid="review-batch-assignee"]').setValue('anouk')
		await wrapper.find('[data-testid="review-batch-create"]').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(
			'/index.php/apps/dossiq/api/cases/case-x/review/batches',
			{ name: 'Mail 2025', assignee: 'anouk', documents: ['d1', 'd2'] },
		)
	})

	it('takes the documents by custodian and source system instead of the list', async () => {
		const wrapper = mountDialog()
		await wrapper
			.find('[data-testid="review-batch-name"]')
			.setValue('Mail A. de Wit')
		await wrapper.find('[data-testid="review-batch-assignee"]').setValue('anouk')
		await wrapper
			.find('[data-testid="review-batch-filter-custodian"]')
			.setValue('A. de Wit')
		await wrapper
			.find('[data-testid="review-batch-filter-sourceSystem"]')
			.setValue(' exchange ')
		await wrapper.find('[data-testid="review-batch-create"]').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(
			'/index.php/apps/dossiq/api/cases/case-x/review/batches',
			{
				name: 'Mail A. de Wit',
				assignee: 'anouk',
				filter: { custodian: 'A. de Wit', sourceSystem: 'exchange' },
			},
		)
		expect(wrapper.emitted('created')).toHaveLength(1)
	})

	it('hands the answer on, and clears the last refusal', async () => {
		const wrapper = mountDialog()
		wrapper.vm.error = 'old'
		await wrapper.vm.create()
		expect(wrapper.emitted('created')[0]).toEqual([{ batch: { id: 'b-1' } }])
		expect(wrapper.vm.error).toBe('')
		expect(wrapper.vm.saving).toBe(false)
	})

	it('names the documents another batch already holds, after the server sentence', async () => {
		const wrapper = mountDialog()
		axios.post.mockRejectedValueOnce({ response: { data: { message: 'Taken.', taken: { d1: 'Mail 2024', d2: 'B' } } } })
		await wrapper.vm.create()
		expect(wrapper.vm.error).toBe('Taken. d1: Mail 2024, d2: B')

		axios.post.mockRejectedValueOnce({ response: { data: { taken: { d1: 'X' } } } })
		await wrapper.vm.create()
		expect(wrapper.vm.error).toBe('The Review batch cannot be stored, so nothing was assigned. d1: X')

		axios.post.mockRejectedValueOnce({ response: { data: { message: 'Nee', taken: 'd1' } } })
		await wrapper.vm.create()
		expect(wrapper.vm.error).toBe('Nee')

		axios.post.mockRejectedValueOnce(new Error('down'))
		await wrapper.vm.create()
		expect(wrapper.vm.error).toBe('The Review batch cannot be stored, so nothing was assigned.')
		expect(wrapper.vm.saving).toBe(false)
		expect(wrapper.emitted('created')).toBeUndefined()
	})
})
