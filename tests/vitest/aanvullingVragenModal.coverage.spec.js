// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * AanvullingVragenModal, the branches the behaviour spec does not reach: the
 * case-type limit read giving up without a case, without a case type, or on a
 * failed read, and the per-field refusals for a missing recipient, a term
 * under a day and a missing rationale.
 *
 * @spec openspec/specs/termijn-pause-extension/spec.md
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
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, s, vars = {}) => s.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m)),
}))
vi.mock('../../src/services/caseTermsApi.js', () => ({ requestInformation: vi.fn() }))

/**
 * A stand-in for one @nextcloud/vue control.
 *
 * @param {string} name The component name.
 * @return {object} The stub.
 */
function control(name) {
	return defineComponent({
		name,
		props: ['modelValue', 'label', 'error', 'disabled', 'variant', 'type', 'size', 'name', 'options', 'inputLabel', 'max'],
		emits: ['update:modelValue', 'close', 'click'],
		render() {
			return h('div', { class: name, onClick: () => this.$emit('click') }, this.$slots.default?.() ?? [])
		},
	})
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: control('NcButton'),
	NcDialog: control('NcDialog'),
	NcNoteCard: control('NcNoteCard'),
	NcSelect: control('NcSelect'),
	NcTextField: control('NcTextField'),
	NcTextArea: control('NcTextArea'),
}))

const { default: AanvullingVragenModal } = await import('../../src/modals/AanvullingVragenModal.vue')

/**
 * Mount the form open on one case.
 *
 * @param {object} props Extra props.
 * @return {Promise<object>} The wrapper.
 */
async function mountForm(props = { caseId: 'case-1', open: true }) {
	const wrapper = mount(AanvullingVragenModal, {
		props,
		global: { mocks: { $route: { params: {} } } },
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	mockGet.mockReset()
	mockPost.mockReset()
	mockGet.mockResolvedValue({ data: { caseType: '', pauseReasons: [] } })
})

describe('AanvullingVragenModal uncovered branches', () => {
	it('reads no limit for a case whose id does not resolve', async () => {
		const wrapper = await mountForm({ caseId: '@objectId', open: true })
		mockGet.mockClear()

		await wrapper.vm.loadCaseTypeLimits()

		expect(mockGet).not.toHaveBeenCalled()
		expect(wrapper.vm.maxDays).toBe(0)
	})

	it('empties the pause reasons when the lifecycle read fails and asks for no case type', async () => {
		const wrapper = await mountForm()
		mockGet.mockReset()
		mockGet.mockRejectedValueOnce(new Error('lifecycle down'))

		await wrapper.vm.loadCaseTypeLimits()

		expect(wrapper.vm.pauseReasons).toEqual([])
		// The lifecycle failing means no case type, so the catalog is never read.
		expect(mockGet).toHaveBeenCalledTimes(1)
	})

	it('stops after the lifecycle read when it names no case type', async () => {
		const wrapper = await mountForm()
		mockGet.mockReset()
		mockGet.mockResolvedValueOnce({ data: { caseType: '', pauseReasons: [{ key: 'x' }] } })

		await wrapper.vm.loadCaseTypeLimits()

		expect(wrapper.vm.pauseReasons).toEqual([{ key: 'x' }])
		expect(mockGet).toHaveBeenCalledTimes(1)
	})

	it('reads no maximum when the case type catalog read fails', async () => {
		const wrapper = await mountForm()
		mockGet.mockReset()
		mockGet
			.mockResolvedValueOnce({ data: { caseType: 'ct-9', pauseReasons: [] } })
			.mockRejectedValueOnce(new Error('catalog down'))

		await wrapper.vm.loadCaseTypeLimits()

		expect(mockGet).toHaveBeenCalledTimes(2)
		expect(wrapper.vm.maxDays).toBe(0)
	})

	it('names the first missing field, in order, and passes a complete request', async () => {
		const wrapper = await mountForm()

		expect(wrapper.vm.formRefusal({ items: [], recipient: 'a@b.nl', durationDays: 7, rationale: 'r' }).field).toBe('items')
		expect(wrapper.vm.formRefusal({ items: ['x'], recipient: '', durationDays: 7, rationale: 'r' }))
			.toEqual({ field: 'recipient', sentence: 'Name who the request goes to.' })
		expect(wrapper.vm.formRefusal({ items: ['x'], recipient: 'a@b.nl', durationDays: 0, rationale: 'r' }))
			.toEqual({ field: 'durationDays', sentence: 'Give the applicant a term of at least one day.' })
		expect(wrapper.vm.formRefusal({ items: ['x'], recipient: 'a@b.nl', durationDays: 7, rationale: '' }))
			.toEqual({ field: 'rationale', sentence: 'Say why the information is needed.' })
		expect(wrapper.vm.formRefusal({ items: ['x'], recipient: 'a@b.nl', durationDays: 7, rationale: 'r' })).toBe(null)
	})
})
