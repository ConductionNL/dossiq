// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DeelzaakCreateModal mounted: how it reads the parent case and the case
 * types, which sub-case types it offers, when it refuses, and how the form
 * reacts to the handler before anything is submitted.
 *
 * @spec openspec/specs/deelzaak-support/spec.md#requirement-sub-case-creation-from-parent-case
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'behandelaar' }) }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('../../src/services/deelzaakApi.js', () => ({ createSubCase: vi.fn() }))
vi.mock('vue-material-design-icons/AlertCircleOutline.vue', () => ({ default: { render: () => null } }))

const loggerError = vi.fn()
vi.mock('../../src/logger.js', () => ({ default: { error: (...a) => loggerError(...a), warn: vi.fn(), info: vi.fn() } }))

const objectStore = { fetchObject: vi.fn(), fetchCollection: vi.fn() }
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => objectStore }))
const deelzaakStore = { validateSubCase: vi.fn() }
vi.mock('../../src/store/modules/deelzaak.js', () => ({ useDeelzaakStore: () => deelzaakStore }))

vi.mock('@nextcloud/vue', async () => (await import('./helpers/ncFormStubs.js')).ncFormStubs())

const { default: DeelzaakCreateModal } = await import('../../src/modals/DeelzaakCreateModal.vue')

const PARENT = 'aaaaaaaa-0000-4000-8000-000000000001'
const CASE_TYPES = [
	{ id: 'ct-advies', title: 'Advies' },
	{ id: 'x1', slug: 'vergunning', title: 'Vergunning' },
	{ id: 'x2', uuid: 'uuid-melding', title: 'Melding' },
	{ id: 'ct-bezwaar', title: 'Bezwaar' },
]
const PARENT_TYPE = { id: 'ct-parent', title: 'Omgevingsvergunning', subCaseTypes: ['ct-advies', 'vergunning', 'uuid-melding'] }

/**
 * Answer the parent case and its case type from the store.
 *
 * @param {object} parent The parent case.
 * @param {object} parentType The parent's case type.
 */
function storeAnswers(parent, parentType = PARENT_TYPE) {
	objectStore.fetchObject.mockImplementation((schema, id) => {
		if (schema === 'case' && id === PARENT) {
			return Promise.resolve(parent)
		}
		if (schema === 'caseType' && id === parentType.id) {
			return Promise.resolve(parentType)
		}
		return Promise.reject(new Error('unexpected ' + schema + ' ' + id))
	})
}

/**
 * Mount the modal and let its reads settle.
 *
 * @param {object} props Extra props.
 * @return {Promise<object>} The wrapper.
 */
async function mountModal(props = {}) {
	const wrapper = mount(DeelzaakCreateModal, { props: { parentCase: PARENT, ...props } })
	await flushPromises()
	return wrapper
}

describe('DeelzaakCreateModal mounted', () => {
	beforeEach(() => {
		objectStore.fetchObject.mockReset()
		objectStore.fetchCollection.mockReset()
		objectStore.fetchCollection.mockResolvedValue(CASE_TYPES)
		loggerError.mockReset()
		vi.spyOn(console, 'error').mockImplementation(() => {})
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('reads the parent and its case type, and offers only the allowed sub-case types by id, slug or uuid', async () => {
		storeAnswers({ id: PARENT, caseType: 'ct-parent' })
		const wrapper = await mountModal()

		expect(objectStore.fetchObject).toHaveBeenCalledWith('case', PARENT)
		expect(objectStore.fetchObject).toHaveBeenCalledWith('caseType', 'ct-parent')
		expect(objectStore.fetchCollection).toHaveBeenCalledWith('caseType', { _limit: 200 })
		expect(wrapper.find('.nc-frame__name').text()).toBe('Create sub-case')
		expect(wrapper.find('.parent-context-value').text()).toBe('Omgevingsvergunning')
		expect(wrapper.findAll('.nc-select li').map((li) => li.text())).toEqual(['Advies', 'Vergunning', 'Melding'])
		expect(wrapper.find('.nc-loading').exists()).toBe(false)
		const create = wrapper.find('.nc-button--primary')
		expect(create.text()).toBe('Create sub-case')
		expect(create.attributes('disabled')).toBeDefined()
	})

	it('uses a passed parent case type without reading it again, and names it when it has no title', async () => {
		storeAnswers({ id: PARENT, caseType: 'ct-parent' })
		const wrapper = await mountModal({ parentCaseType: { id: 'ct-p', name: 'Handhaving', subCaseTypes: ['ct-bezwaar'] } })

		expect(objectStore.fetchObject).toHaveBeenCalledTimes(1)
		expect(wrapper.find('.parent-context-value').text()).toBe('Handhaving')
		expect(wrapper.findAll('.nc-select li').map((li) => li.text())).toEqual(['Bezwaar'])
	})

	it('says a parent type without sub-case types allows none, and offers no create button', async () => {
		storeAnswers({ id: PARENT, caseType: 'ct-parent' }, { id: 'ct-parent', title: 'Melding', subCaseTypes: [] })
		const wrapper = await mountModal()

		expect(wrapper.find('.nc-empty strong').text()).toBe('No allowed sub-case types')
		expect(wrapper.find('.nc-select').exists()).toBe(false)
		expect(wrapper.find('.nc-button--primary').exists()).toBe(false)
	})

	it('refuses a parent that is itself a sub-case', async () => {
		storeAnswers({ id: PARENT, caseType: 'ct-parent', parentCase: 'grandparent' })
		const wrapper = await mountModal()

		expect(wrapper.find('[data-testid="deelzaak-create-refusal"] strong').text()).toBe('No sub-case can be added here')
		expect(wrapper.find('[data-testid="deelzaak-create-refusal"] p').text()).toBe('Sub-cases cannot themselves have sub-cases.')
		expect(wrapper.find('.nc-button--primary').exists()).toBe(false)
	})

	it('refuses a closed parent', async () => {
		storeAnswers({ id: PARENT, caseType: 'ct-parent', endDate: '2026-01-01' })
		const wrapper = await mountModal()

		expect(wrapper.find('[data-testid="deelzaak-create-refusal"] p').text()).toBe('This case is closed; sub-cases can no longer be added.')
	})

	it('logs a parent that cannot be read and still stops loading', async () => {
		const failure = new Error('403')
		objectStore.fetchObject.mockRejectedValue(failure)
		const wrapper = await mountModal()

		expect(loggerError).toHaveBeenCalledWith('DeelzaakCreateModal: failed to load the parent case', { error: failure })
		expect(wrapper.find('.nc-loading').exists()).toBe(false)
		expect(wrapper.find('.nc-empty strong').text()).toBe('No allowed sub-case types')
	})

	it('treats case types that cannot be read, or are not a list, as none', async () => {
		storeAnswers({ id: PARENT, caseType: 'ct-parent' })
		objectStore.fetchCollection.mockRejectedValueOnce(new Error('timeout'))
		const failed = await mountModal()
		expect(failed.vm.caseTypes).toEqual([])
		expect(console.error).toHaveBeenCalledWith('[DeelzaakCreateModal] Failed to load caseTypes', expect.any(Error))
		expect(failed.find('.nc-empty strong').text()).toBe('No allowed sub-case types')

		objectStore.fetchCollection.mockResolvedValueOnce({ results: CASE_TYPES })
		const odd = await mountModal()
		expect(odd.vm.caseTypes).toEqual([])
		expect(odd.vm.loadingTypes).toBe(false)
	})

	it('shows the validation errors and clears each one when the handler corrects it', async () => {
		storeAnswers({ id: PARENT, caseType: 'ct-parent' })
		const wrapper = await mountModal()

		expect(wrapper.vm.validate()).toBe(false)
		await flushPromises()
		const alerts = wrapper.findAll('.form-error').map((p) => p.text())
		expect(alerts).toEqual(['Sub-case type is required', 'Title is required'])
		expect(wrapper.find('.nc-text--error').exists()).toBe(true)

		wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', CASE_TYPES[0])
		await flushPromises()
		expect(wrapper.vm.form.caseType).toBe('ct-advies')
		expect(wrapper.vm.selectedCaseType).toEqual(CASE_TYPES[0])
		expect(wrapper.vm.errors.caseType).toBe('')

		wrapper.findComponent({ name: 'NcTextField' }).vm.$emit('update:modelValue', 'Advies welstand')
		await flushPromises()
		expect(wrapper.vm.form.title).toBe('Advies welstand')
		expect(wrapper.vm.errors.title).toBe('')
		expect(wrapper.findAll('.form-error')).toHaveLength(0)
		expect(wrapper.vm.validate()).toBe(true)
		await flushPromises()
		expect(wrapper.find('.nc-button--primary').attributes('disabled')).toBeUndefined()
	})

	it('treats a whitespace title as missing and clears the type when the selection is cleared', async () => {
		storeAnswers({ id: PARENT, caseType: 'ct-parent' })
		const wrapper = await mountModal()

		wrapper.vm.form.title = '   '
		wrapper.vm.selectedCaseType = CASE_TYPES[0]
		expect(wrapper.vm.validate()).toBe(false)
		expect(wrapper.vm.errors).toEqual({ title: 'Title is required' })

		wrapper.vm.onCaseTypeSelected(null)
		expect(wrapper.vm.form.caseType).toBe(null)
	})

	it('closes on cancel, on the dialog closing, and when the dialog is closed but not when it opens', async () => {
		storeAnswers({ id: PARENT, caseType: 'ct-parent' })
		const wrapper = await mountModal()

		await wrapper.find('.nc-button--tertiary').trigger('click')
		expect(wrapper.emitted('close')).toHaveLength(1)

		const dialog = wrapper.findComponent({ name: 'NcDialog' })
		dialog.vm.$emit('update:open', true)
		expect(wrapper.emitted('close')).toHaveLength(1)
		dialog.vm.$emit('update:open', false)
		expect(wrapper.emitted('close')).toHaveLength(2)
		dialog.vm.$emit('closing')
		expect(wrapper.emitted('close')).toHaveLength(3)
	})

	it('shows a server error under the form', async () => {
		storeAnswers({ id: PARENT, caseType: 'ct-parent' })
		const wrapper = await mountModal()

		wrapper.vm.serverError = 'Parent case is locked'
		await flushPromises()
		expect(wrapper.find('.form-error--server').text()).toBe('Parent case is locked')
	})
})
