// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * SubstitutionFormModal's submit: the payload it registers, the absentee it
 * sends only for a coordinator, the refusal before anything is sent without a
 * substitute, and the server's reason when the registration fails.
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const mockCreate = vi.fn()
vi.mock('../../src/services/substitutionApi.js', () => ({
	createSubstitution: (...a) => mockCreate(...a),
}))
vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => ({ fetchCollection: vi.fn().mockResolvedValue([]) }),
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: { name: 'NcButton', template: '<button><slot /></button>' },
	NcDialog: { name: 'NcDialog', template: '<div><slot /><slot name="actions" /></div>' },
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<span />' },
	NcSelect: { name: 'NcSelect', template: '<div />' },
	NcTextField: { name: 'NcTextField', template: '<input />' },
}))

const { default: SubstitutionFormModal } = await import('../../src/modals/SubstitutionFormModal.vue')

/**
 * Mount the form and fill it.
 *
 * @param {object} props The props.
 * @return {Promise<object>} The wrapper.
 */
async function filled(props = {}) {
	const wrapper = mount(SubstitutionFormModal, { props })
	await flushPromises()
	Object.assign(wrapper.vm.form, {
		absentee: ' anna ',
		substitute: ' bob ',
		startDate: '2026-11-01',
		endDate: '2026-11-30',
		comment: 'Vakantie',
	})
	return wrapper
}

beforeEach(() => {
	mockCreate.mockReset()
})

describe('SubstitutionFormModal submit', () => {
	it('registers the substitution for the person themself and emits it', async () => {
		mockCreate.mockResolvedValue({ id: 's-1' })
		const wrapper = await filled()
		await wrapper.vm.submit()

		expect(mockCreate).toHaveBeenCalledWith({
			substitute: 'bob',
			startDate: '2026-11-01',
			endDate: '2026-11-30',
			reason: 'verlof',
			scope: 'all',
			scopeRefs: [],
			comment: 'Vakantie',
		})
		expect(wrapper.emitted('created')).toEqual([[{ id: 's-1' }]])
		expect(wrapper.vm.saving).toBe(false)
		expect(wrapper.vm.serverError).toBe('')
	})

	it('sends the absentee only for a coordinator who named one', async () => {
		mockCreate.mockResolvedValue({})
		const coordinator = await filled({ allowCoordinator: true })
		await coordinator.vm.submit()
		expect(mockCreate.mock.calls[0][0].absentee).toBe('anna')

		const blank = await filled({ allowCoordinator: true })
		blank.vm.form.absentee = '   '
		await blank.vm.submit()
		expect('absentee' in mockCreate.mock.calls[1][0]).toBe(false)

		const handler = await filled({ allowCoordinator: false })
		await handler.vm.submit()
		expect('absentee' in mockCreate.mock.calls[2][0]).toBe(false)
	})

	it('sends the chosen reason, the case types of a case-type scope, and falls back when nothing is chosen', async () => {
		mockCreate.mockResolvedValue({})
		const wrapper = await filled()
		wrapper.vm.selectedReason = { value: 'ziekte' }
		wrapper.vm.selectedScope = { value: 'caseTypes' }
		wrapper.vm.selectedCaseTypes = [{ id: 'ct-1' }, { id: 'ct-2' }]
		await wrapper.vm.submit()
		expect(mockCreate.mock.calls[0][0]).toMatchObject({
			reason: 'ziekte',
			scope: 'caseTypes',
			scopeRefs: ['ct-1', 'ct-2'],
		})

		wrapper.vm.selectedReason = null
		wrapper.vm.selectedScope = null
		await wrapper.vm.submit()
		expect(mockCreate.mock.calls[1][0]).toMatchObject({ reason: 'verlof', scope: 'all', scopeRefs: [] })
	})

	it('refuses without a substitute before anything is sent', async () => {
		const wrapper = await filled()
		wrapper.vm.form.substitute = '  '
		wrapper.vm.serverError = 'old'
		await wrapper.vm.submit()

		expect(mockCreate).not.toHaveBeenCalled()
		expect(wrapper.vm.errors.substitute).toBe('A substitute is required')
		expect(wrapper.vm.serverError).toBe('')
		expect(wrapper.vm.saving).toBe(false)
		expect(wrapper.emitted('created')).toBeUndefined()
	})

	it.each([
		[{ response: { data: { error: 'Not allowed to act for anna' } }, message: 'x' }, 'Not allowed to act for anna'],
		[{ message: 'Network Error' }, 'Network Error'],
		[{}, 'Failed to register substitution.'],
	])('shows the reason of a failed registration (%j)', async (error, shown) => {
		mockCreate.mockRejectedValue(error)
		const wrapper = await filled()
		await wrapper.vm.submit()

		expect(wrapper.vm.serverError).toBe(shown)
		expect(wrapper.vm.saving).toBe(false)
		expect(wrapper.emitted('created')).toBeUndefined()
	})
})
