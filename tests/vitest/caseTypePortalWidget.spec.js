// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case type's portal page: it reads the case type and its statuses,
 * shows what the applicant may still do, and saves the section, showing the
 * guard's own sentence when the save is refused.
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const mockGet = vi.fn()
const mockPatch = vi.fn()
const mockShowError = vi.fn()
const mockShowSuccess = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: { get: (...a) => mockGet(...a), patch: (...a) => mockPatch(...a) },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
vi.mock('@nextcloud/dialogs', () => ({
	showError: (...a) => mockShowError(...a),
	showSuccess: (...a) => mockShowSuccess(...a),
}))

/**
 * A stub that renders its slot and keeps its props readable.
 *
 * @param {string} name The component name.
 * @param {string} tag The element to render.
 * @return {object} The stub.
 */
function stub(name, tag = 'div') {
	return defineComponent({
		name,
		inheritAttrs: false,
		props: ['modelValue', 'options', 'multiple', 'inputLabel', 'label', 'disabled', 'variant', 'size'],
		emits: ['update:modelValue', 'click'],
		render() {
			return h(tag, { ...this.$attrs, onClick: () => this.$emit('click') }, this.$slots.default?.())
		},
	})
}
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: stub('NcButton', 'button') }))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({ default: stub('NcCheckboxRadioSwitch') }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: stub('NcLoadingIcon') }))
vi.mock('@nextcloud/vue/components/NcSelect', () => ({ default: stub('NcSelect') }))
vi.mock('@nextcloud/vue/components/NcTextField', () => ({ default: stub('NcTextField') }))

const { default: CaseTypePortalWidget } = await import('../../src/components/caseType/CaseTypePortalWidget.vue')

const CASE_TYPE = {
	portalWritable: [{ field: 'description', enabled: true, openStatuses: ['st-intake', 'st-gone'] }],
	portalAmendmentWindow: { openStatuses: ['st-intake'], closedReason: 'Closed now' },
	portalIdentityKind: ['reference'],
}
const STATUSES = [
	{ id: 'st-intake', name: 'Intake' },
	{ uuid: 'st-review', title: 'Review' },
	{ name: 'No id' },
]

/**
 * Answer the two reads.
 *
 * @param {object} caseType The stored case type.
 * @param {Array} statuses The blueprint's status types.
 * @return {void}
 */
function answer(caseType = CASE_TYPE, statuses = STATUSES) {
	mockGet.mockImplementation((url) => Promise.resolve(
		url.endsWith('/blueprint') ? { data: { statusTypes: statuses } } : { data: caseType },
	))
}

/**
 * Mount the widget for a case type id.
 *
 * @param {string} id The route's id.
 * @return {Promise<object>} The wrapper.
 */
async function mountWidget(id = 'ct 1') {
	const w = mount(CaseTypePortalWidget, { global: { mocks: { $route: { params: { id } } } } })
	await flushPromises()
	return w
}

beforeEach(() => {
	mockGet.mockReset()
	mockPatch.mockReset()
	mockShowError.mockReset()
	mockShowSuccess.mockReset()
	answer()
})

describe('CaseTypePortalWidget', () => {
	it('reads the case type and the blueprint of the routed id', async () => {
		await mountWidget('ct 1')

		expect(mockGet.mock.calls.map((c) => c[0]).sort()).toEqual([
			'/apps/dossiq/api/case-types/ct%201/blueprint',
			'/apps/openregister/api/objects/dossiq/caseType/ct%201',
		])
	})

	it('offers the statuses with an id, and keeps a stored unknown id visible', async () => {
		const w = await mountWidget()
		const selects = w.findAllComponents({ name: 'NcSelect' })

		expect(selects[0].props('options')).toEqual([
			{ id: 'st-intake', label: 'Intake' },
			{ id: 'st-review', label: 'Review' },
		])
		expect(selects[0].props('modelValue')).toEqual([
			{ id: 'st-intake', label: 'Intake' },
			{ id: 'st-gone', label: 'st-gone' },
		])
	})

	it('shows the reference way in as disabled with its note', async () => {
		const w = await mountWidget()
		const reference = w.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
			.find((c) => c.attributes('data-testid') === 'case-type-portal-way-in-reference')

		expect(reference.props('modelValue')).toBe(true)
		expect(reference.props('disabled')).toBe(true)
		expect(w.find('[data-testid="case-type-portal-ways-in"]').text()).toContain('Available once the portal checks the address against the case.')
	})

	it('a failed read says so instead of showing the form', async () => {
		mockGet.mockRejectedValue(new Error('down'))
		const w = await mountWidget()

		expect(w.text()).toContain('The portal settings of this case type could not be read.')
		expect(w.find('[data-testid="case-type-portal-save"]').exists()).toBe(false)
	})

	it('choosing statuses writes their ids into the state that is saved', async () => {
		mockPatch.mockResolvedValue({ data: {} })
		const w = await mountWidget()
		const amendment = w.findAllComponents({ name: 'NcSelect' })
			.find((c) => c.attributes('data-testid') === 'case-type-portal-amendment')

		amendment.vm.$emit('update:modelValue', [{ id: 'st-review', label: 'Review' }])
		await w.find('[data-testid="case-type-portal-save"]').trigger('click')
		await flushPromises()

		expect(mockPatch).toHaveBeenCalledTimes(1)
		expect(mockPatch.mock.calls[0][0]).toBe('/apps/openregister/api/objects/dossiq/caseType/ct%201')
		expect(mockPatch.mock.calls[0][1].portalAmendmentWindow.openStatuses).toEqual(['st-review'])
		expect(mockShowSuccess).toHaveBeenCalledWith('Portal settings saved')
		expect(mockShowError).not.toHaveBeenCalled()
	})

	it('a refused save shows the guard sentence and no toast', async () => {
		mockPatch.mockRejectedValue({ response: { data: { message: 'A closed window needs a sentence.' } } })
		const w = await mountWidget()

		await w.find('[data-testid="case-type-portal-save"]').trigger('click')
		await flushPromises()

		expect(w.find('[role="alert"]').text()).toBe('A closed window needs a sentence.')
		expect(mockShowError).not.toHaveBeenCalled()
		expect(mockShowSuccess).not.toHaveBeenCalled()
	})

	it('a save that fails without a sentence shows the generic error', async () => {
		mockPatch.mockRejectedValue(new Error('network'))
		const w = await mountWidget()

		await w.find('[data-testid="case-type-portal-save"]').trigger('click')
		await flushPromises()

		expect(w.find('[role="alert"]').exists()).toBe(false)
		expect(mockShowError).toHaveBeenCalledWith('The portal settings could not be saved.')
		expect(w.findComponent({ name: 'NcButton' }).props('disabled')).toBe(false)
	})
})

describe('CaseTypePortalWidget withdrawal and writable editing', () => {
	const WITH_WITHDRAWAL = {
		...CASE_TYPE,
		portalWithdrawal: {
			targetStatus: 'st-review',
			openStatuses: ['st-intake'],
			closedReason: 'Closed',
			confirmText: 'Are you sure?',
		},
	}

	it('renders the withdrawal block when a target status is stored, and saves its edits', async () => {
		answer(WITH_WITHDRAWAL)
		mockPatch.mockResolvedValue({ data: {} })
		const w = await mountWidget()

		expect(w.find('[data-testid="case-type-portal-withdrawal"]').exists()).toBe(true)

		const selects = w.findAllComponents({ name: 'NcSelect' })
		// The status-after-withdrawal select is the only single-value one.
		const targetStatus = selects.find((s) => s.props('inputLabel') === 'Status after withdrawal')
		targetStatus.vm.$emit('update:modelValue', { id: 'st-intake', label: 'Intake' })
		// The confirmation and closed-reason text fields are unique to the block.
		const fields = w.findAllComponents({ name: 'NcTextField' })
		fields.find((f) => f.props('label') === 'Confirmation text').vm.$emit('update:modelValue', 'Really?')
		fields.find((f) => f.props('label') === 'Sentence when closed' && f.props('modelValue') === 'Closed').vm.$emit('update:modelValue', 'Not now')
		await flushPromises()

		await w.find('[data-testid="case-type-portal-save"]').trigger('click')
		await flushPromises()

		const body = mockPatch.mock.calls[0][1]
		expect(body.portalWithdrawal.targetStatus).toBe('st-intake')
		expect(body.portalWithdrawal.confirmText).toBe('Really?')
		expect(body.portalWithdrawal.closedReason).toBe('Not now')
	})

	it('turning the withdrawal off drops it from what is saved', async () => {
		answer(WITH_WITHDRAWAL)
		mockPatch.mockResolvedValue({ data: {} })
		const w = await mountWidget()

		w.find('[data-testid="case-type-portal-withdrawal"]').element.dispatchEvent(new Event('click'))
		w.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
			.find((c) => c.attributes('data-testid') === 'case-type-portal-withdrawal')
			.vm.$emit('update:modelValue', false)
		await flushPromises()

		await w.find('[data-testid="case-type-portal-save"]').trigger('click')
		await flushPromises()

		expect(mockPatch.mock.calls[0][1].portalWithdrawal.targetStatus ?? '').toBe('')
	})

	it('choosing statuses for a writable field writes their ids', async () => {
		mockPatch.mockResolvedValue({ data: {} })
		const w = await mountWidget()

		const writableSelect = w.findAllComponents({ name: 'NcSelect' })
			.find((s) => JSON.stringify(s.props('modelValue')) === JSON.stringify([{ id: 'st-intake', label: 'Intake' }, { id: 'st-gone', label: 'st-gone' }]))
		writableSelect.vm.$emit('update:modelValue', [{ id: 'st-review', label: 'Review' }])
		await flushPromises()

		await w.find('[data-testid="case-type-portal-save"]').trigger('click')
		await flushPromises()

		const row = mockPatch.mock.calls[0][1].portalWritable.find((r) => r.field === 'description')
		expect(row.openStatuses).toEqual(['st-review'])
	})

	it('labels the ceiling fields and the ways in', async () => {
		const w = await mountWidget()

		expect(w.vm.fieldLabel('description')).toBe('Description of the request')
		expect(w.vm.fieldLabel('other')).toBe('other')
		expect(w.vm.wayInLabel('reference')).toContain('case number')
		expect(w.vm.wayInLabel('account')).toContain('DigiD')
		expect(w.vm.idsOf(null)).toEqual([])
		expect(w.vm.optionsFor(null)).toEqual([])
	})
})
