// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqDocumentGenereren: a required fill the case lacks stays in the card
 * with a Verplicht pill and "still to fill in", the dialog says the draft waits
 * for it, and the template's own notes follow.
 *
 * @spec openspec/specs/generate-fills-per-template/spec.md#requirement-the-generate-dialog-shows-what-the-chosen-template-fills-in
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

/**
 * A stub that renders its slots.
 *
 * @param {string} name The component name.
 * @return {object} The stub.
 */
function stub(name) {
	return defineComponent({
		name,
		props: ['modelValue', 'label', 'options', 'type', 'disabled'],
		emits: ['update:modelValue', 'click', 'closing'],
		render() {
			return h('div', { class: name }, [this.$slots.default?.(), this.$slots.actions?.()])
		},
	})
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton'),
	NcDialog: stub('NcDialog'),
	NcNoteCard: stub('NcNoteCard'),
}))

const { default: GenerateDocumentDialog } = await import('../../src/dialogs/GenerateDocumentDialog.vue')

const ADJOURNMENT = {
	id: 'verdagingsbrief',
	title: 'Verdagingsbrief',
	category: 'document',
	fills: [
		{ label: 'Statement of reasons', field: 'extensionReason', required: true },
		{ label: 'Status after generating', value: 'Draft (concept)' },
	],
}
const DECISION = { id: 'woo-besluit-openbaar', title: 'Woo-besluit openbaar', category: 'document', fills: [], notes: ['Na verzenden start de bezwaartermijn.', ''] }

/**
 * Mount the dialog over one template and a case without the reasons.
 *
 * @param {object} template The library entry.
 * @return {Promise<object>} The wrapper.
 */
async function mountWith(template) {
	axios.get.mockImplementation((url) => Promise.resolve({
		data: String(url).includes('/api/templates') ? { results: [template] } : { id: 'case-1', title: 'Fietspad', identifier: '2026-0110' },
	}))
	const wrapper = mount(GenerateDocumentDialog, {
		props: { open: true, caseId: 'case-1' },
		global: { mocks: { $route: { params: { id: 'case-1' } } } },
	})
	await flushPromises()
	wrapper.vm.kase = { id: 'case-1', title: 'Fietspad', identifier: '2026-0110' }
	await flushPromises()
	return wrapper
}

describe('GenerateDocumentDialog required fills', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('marks the required fill and says the draft waits for it', async () => {
		const wrapper = await mountWith(ADJOURNMENT)

		expect(wrapper.find('[data-testid="generate-document-required"]').text()).toBe('Required')
		expect(wrapper.find('.generate-document__missing').text()).toBe('Still to fill in, not in the case')
		expect(wrapper.find('[data-testid="generate-document-missing"]').text()).toBe('A required field is missing: statement of reasons. The draft stays in Draft until you have filled it in; approval can only come after that.')
		expect(wrapper.text()).toContain('Draft (concept)')
	})

	it('shows the template\'s notes and no missing line when nothing required is missing', async () => {
		const wrapper = await mountWith(DECISION)

		expect(wrapper.find('[data-testid="generate-document-missing"]').exists()).toBe(false)
		const notes = wrapper.findAll('[data-testid="generate-document-template-note"]')
		expect(notes.map((note) => note.text())).toEqual(['Na verzenden start de bezwaartermijn.'])
	})
})
