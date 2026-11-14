// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Sharing a case with another cloud: the field labels, when it may be sent, and what is emitted.
 *
 * @spec openspec/specs/federated-case-collaboration/spec.md
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

function plain (name) {
  return defineComponent({ name, render() {
	return h('div', [this.$slots.default?.(), this.$slots.actions?.()])
} })
}
vi.mock('@nextcloud/vue', () => ({ NcButton: plain('NcButton'), NcDialog: plain('NcDialog') }))

const { default: CreateFederatedShareDialog } = await import('../../src/dialogs/CreateFederatedShareDialog.vue')

/**
 * Mount the dialog for a case.
 *
 * @return {object} The wrapper.
 */
function dialog() {
	return mount(CreateFederatedShareDialog, { props: { open: true, caseId: 'c-1', documents: [] } })
}

describe('CreateFederatedShareDialog', () => {
	it('offers every allowed field under its label', () => {
		expect(dialog().vm.allowedFields).toEqual([
			{ value: 'title', label: 'Title' },
			{ value: 'description', label: 'Description' },
			{ value: 'status', label: 'Status' },
			{ value: 'caseType', label: 'Case type' },
			{ value: 'priority', label: 'Priority' },
			{ value: 'dueDate', label: 'Due date' },
			{ value: 'requestedDate', label: 'Requested date' },
		])
	})

	it('labels an unknown field by its own name', () => {
		expect(dialog().vm.fieldLabel('zaakgeheim')).toBe('zaakgeheim')
	})

	it('needs a cloud id and at least one field', () => {
		const w = dialog()
		expect(w.vm.isValid).toBe(false)
		w.vm.form.remoteCloudId = '  ilse@other.example  '
		expect(w.vm.isValid).toBe(false)
		w.vm.form.sharedFields = ['title']
		expect(w.vm.isValid).toBe(true)
		w.vm.form.remoteCloudId = '   '
		expect(w.vm.isValid).toBe(false)
	})

	it('emits the shaped share: trimmed cloud id, de-duplicated fields and documents', async () => {
		const w = dialog()
		w.vm.form.remoteCloudId = ' ilse@other.example '
		w.vm.form.sharedFields = ['title', 'status', 'title']
		w.vm.form.sharedDocuments = ['d-1', 'd-1']

		await w.vm.createFederatedShare()

		expect(w.emitted('created')).toEqual([[{
			caseId: 'c-1',
			remoteCloudId: 'ilse@other.example',
			sharedFields: ['title', 'status'],
			sharedDocuments: ['d-1'],
		}]])
		expect(w.vm.saving).toBe(false)
	})
})
