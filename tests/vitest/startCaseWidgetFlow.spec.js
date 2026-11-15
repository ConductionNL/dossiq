// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The start-a-case widget, mounted with the stores and the intake API doubled:
 * which case types it offers, the initiator and intake requirement steps, and
 * the case it creates and opens.
 *
 * @spec openspec/specs/case-management/spec.md
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const stub = (name) => ({ name, render: () => null })
vi.mock('@nextcloud/vue', () => ({ NcEmptyContent: stub('NcEmptyContent'), NcLoadingIcon: stub('NcLoadingIcon'), NcNoteCard: stub('NcNoteCard') }))
vi.mock('../../src/modals/InitiatorPickerModal.vue', () => ({ default: stub('InitiatorPickerModal') }))
vi.mock('../../src/modals/IntakeRequirementsModal.vue', () => ({ default: stub('IntakeRequirementsModal') }))
vi.mock('../../src/services/intakeTriageApi.js', () => ({ fetchIntakeRequirements: vi.fn() }))
vi.mock('../../src/utils/intakeRequirements.js', () => ({
	asksAnything: (declaration) => Boolean(declaration?.asks),
	refusalSentence: (error) => error?.response?.data?.reason || '',
}))
const store = { fetchCollection: vi.fn(), saveObject: vi.fn() }
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => store }))
vi.mock('../../src/store/store.js', () => ({ initializeStores: vi.fn(async () => {}) }))

const intake = await import('../../src/services/intakeTriageApi.js')
const { default: StartCaseWidget } = await import('../../src/views/widgets/StartCaseWidget.vue')

/**
 * Mount the widget and let it load.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountWidget() {
	const w = shallowMount(StartCaseWidget, { props: { title: 'Start' } })
	await flushPromises()
	return w
}

let location

beforeEach(() => {
	vi.clearAllMocks()
	vi.spyOn(console, 'error').mockImplementation(() => {})
	store.fetchCollection.mockResolvedValue([{ id: 'ct1', title: 'Permit' }, { id: 'ct2', isDraft: true }, { id: 'ct3', validUntil: '2000-01-01' }])
	location = { href: '' }
	Object.defineProperty(window, 'location', { value: location, configurable: true })
})

afterEach(() => {
	vi.restoreAllMocks()
})

describe('StartCaseWidget', () => {
	it('offers only the case types a case can start on', async () => {
		const w = await mountWidget()
		expect(store.fetchCollection).toHaveBeenCalledWith('caseType', { _limit: 50, isDraft: false })
		expect(w.vm.caseTypes.map((ct) => ct.id)).toEqual(['ct1'])
		store.fetchCollection.mockRejectedValueOnce(new Error('x'))
		await w.vm.fetchCaseTypes()
		expect([w.vm.caseTypes, w.vm.loading]).toEqual([[], false])
		store.fetchCollection.mockResolvedValueOnce(null)
		await w.vm.fetchCaseTypes()
		expect(w.vm.caseTypes).toEqual([])
	})

	it('asks for the initiator first, then creates the case and opens it', async () => {
		intake.fetchIntakeRequirements.mockResolvedValueOnce({})
		store.saveObject.mockResolvedValueOnce({ id: 'new-case' })
		const w = await mountWidget()
		w.vm.startCase({ id: 'ct1', title: 'Permit' })
		expect(w.vm.pendingCaseType).toEqual({ id: 'ct1', title: 'Permit' })
		await w.vm.onInitiatorConfirmed({ initiator: 'bsn-1' })
		expect(intake.fetchIntakeRequirements).toHaveBeenCalledWith('ct1')
		const [type, data] = store.saveObject.mock.calls[0]
		expect(type).toBe('case')
		expect(data).toMatchObject({ title: 'Permit', caseType: 'ct1', initiator: 'bsn-1' })
		expect(data.startDate).toMatch(/^\d{4}-\d{2}-\d{2}$/)
		expect(location.href).toMatch(/\/apps\/dossiq\/cases\/new-case$/)
		expect([w.vm.pendingCaseType, w.vm.creating, w.vm.creatingId]).toEqual([null, false, null])
	})

	it('collects the intake requirements before creating, and drops them on cancel', async () => {
		intake.fetchIntakeRequirements.mockResolvedValue({ asks: ['address'] })
		store.saveObject.mockResolvedValueOnce({})
		const w = await mountWidget()
		w.vm.startCase({ id: 'ct1', title: 'Permit' })
		await w.vm.onInitiatorSkipped()
		expect(store.saveObject).not.toHaveBeenCalled()
		expect([w.vm.pendingRequirements.id, w.vm.pendingDeclaration, w.vm.pendingExtraFields]).toEqual(['ct1', { asks: ['address'] }, {}])
		await w.vm.onRequirementsAnswered({ address: 'Dorpsstraat 1' })
		expect(store.saveObject.mock.calls[0][1]).toMatchObject({ caseType: 'ct1', address: 'Dorpsstraat 1' })
		expect([w.vm.pendingRequirements, w.vm.pendingDeclaration]).toEqual([null, null])
		expect(location.href).toBe('')
		await w.vm.startWithRequirements({ id: 'ct1' }, { a: 1 })
		w.vm.cancelRequirements()
		expect([w.vm.pendingRequirements, w.vm.pendingExtraFields]).toEqual([null, {}])
	})

	it('starts nothing while creating or without a case type, and explains a refused case', async () => {
		const w = await mountWidget()
		w.vm.creating = true
		w.vm.startCase({ id: 'ct1' })
		await w.vm.createCase({ id: 'ct1' })
		expect([w.vm.pendingCaseType, store.saveObject.mock.calls.length]).toEqual([null, 0])
		w.vm.creating = false
		await w.vm.startWithRequirements(null)
		await w.vm.createCase(null)
		expect(intake.fetchIntakeRequirements).not.toHaveBeenCalled()
		store.saveObject.mockRejectedValueOnce({ response: { data: { reason: 'Outside opening hours' } } })
		await w.vm.createCase({ id: 'ct1', title: 'Permit' })
		expect(w.vm.refusal).toBe('Outside opening hours')
		store.saveObject.mockRejectedValueOnce({})
		await w.vm.createCase({ id: 'ct1', title: 'Permit' })
		expect([w.vm.refusal, w.vm.creating]).toEqual(['The case could not be opened.', false])
	})
})
