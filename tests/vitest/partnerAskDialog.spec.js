// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case page's "Ask a partner" dialog: it refuses an incomplete ask
 * without sending, sends a complete one to portaliq and closes, and keeps
 * itself open with portaliq's refusal.
 *
 * @spec openspec/specs/partner-tasks-in-the-portal/spec.md (portaliq, REQ-PTP-002)
 */

import { flushPromises, mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const mockPost = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { post: (...a) => mockPost(...a) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))

/**
 * A stand-in for one @nextcloud/vue control.
 *
 * @param {string} name The component name.
 * @return {object} The stub.
 */
function control(name) {
	return defineComponent({
		name,
		props: ['modelValue', 'label', 'type', 'variant', 'disabled', 'name', 'size'],
		emits: ['update:modelValue', 'close', 'click'],
		render() {
			return h('div', { class: name, onClick: () => this.$emit('click') }, this.$slots.default?.() ?? [])
		},
	})
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: control('NcButton'),
	NcCheckboxRadioSwitch: control('NcCheckboxRadioSwitch'),
	NcDialog: control('NcDialog'),
	NcNoteCard: control('NcNoteCard'),
	NcTextArea: control('NcTextArea'),
	NcTextField: control('NcTextField'),
}))

const { default: PartnerAskDialog } = await import('../../src/dialogs/PartnerAskDialog.vue')

/**
 * Mount the dialog open on one case.
 *
 * @return {object} The wrapper.
 */
function mountDialog() {
	return mount(PartnerAskDialog, {
		props: { caseId: 'case-1', open: true },
		global: { mocks: { $route: { params: {} } } },
	})
}

/**
 * Fill a text control by its test id.
 *
 * @param {object} wrapper The dialog.
 * @param {string} id The data-testid.
 * @param {string|boolean} value The value.
 */
function fill(wrapper, id, value) {
	wrapper.findComponent(`[data-testid="${id}"]`).vm.$emit('update:modelValue', value)
}

describe('PartnerAskDialog', () => {

	it('sends nothing while the ask is incomplete and says why', async () => {
		const wrapper = mountDialog()

		await wrapper.find('[data-testid="partner-ask-send"]').trigger('click')
		await flushPromises()

		expect(mockPost).not.toHaveBeenCalled()
		expect(wrapper.text()).toContain('Say what the partner is asked for.')
		expect(wrapper.emitted('sent')).toBeUndefined()
	})

	it('sends a complete ask to portaliq and closes', async () => {
		mockPost.mockResolvedValue({ data: { task: { id: 't-1' } } })
		const wrapper = mountDialog()
		fill(wrapper, 'partner-ask-title', 'Welstandsadvies')
		fill(wrapper, 'partner-ask-kvk', '12345678')
		fill(wrapper, 'partner-ask-email', 'welstand@example.nl')
		fill(wrapper, 'partner-ask-due', '2026-11-29')
		fill(wrapper, 'partner-ask-pdf', true)

		await wrapper.find('[data-testid="partner-ask-send"]').trigger('click')
		await flushPromises()

		expect(mockPost).toHaveBeenCalledWith('/apps/portaliq/api/partner-tasks/ask', expect.objectContaining({
			register: 'dossiq',
			schema: 'case',
			caseId: 'case-1',
			title: 'Welstandsadvies',
			dueAt: '2026-11-29',
			partner: { kvk: '12345678', email: 'welstand@example.nl' },
			uploadRules: [{ mimeType: 'application/pdf', required: true }],
		}))
		expect(wrapper.emitted('sent')).toEqual([[{ task: { id: 't-1' } }]])
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('stays open with portaliq\'s refusal', async () => {
		const refused = new Error('403'); refused.response = { status: 403 }; mockPost.mockRejectedValue(refused)
		const wrapper = mountDialog()
		fill(wrapper, 'partner-ask-title', 'Welstandsadvies')
		fill(wrapper, 'partner-ask-kvk', '12345678')
		fill(wrapper, 'partner-ask-email', 'welstand@example.nl')

		await wrapper.find('[data-testid="partner-ask-send"]').trigger('click')
		await flushPromises()

		expect(wrapper.text()).toContain('You may not ask a partner on this case.')
		expect(wrapper.emitted('close')).toBeUndefined()
	})

	it('is offered on the case page by the ask-partner header action', () => {
		const manifest = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/manifest.json'), 'utf8'))
		const found = []
		const walk = (node) => {
			if (Array.isArray(node)) {
				node.forEach(walk)
			} else if (node && typeof node === 'object') {
				if (node.id === 'ask-partner') {
					found.push(node)
				}
				Object.values(node).forEach(walk)
			}
		}
		walk(manifest)

		expect(found).toHaveLength(1)
		expect(found[0]).toMatchObject({ type: 'open-modal', target: 'PartnerAskDialog', props: { caseId: '@objectId', open: true } })
	})
})
