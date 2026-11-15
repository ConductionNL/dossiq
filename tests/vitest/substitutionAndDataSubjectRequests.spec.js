// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The substitution admin page and its form, and the data subject request tab
 * (erasure preview and run, export request and state), mounted with their
 * stores and API doubled.
 *
 * @spec openspec/specs/case-management/spec.md
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const stub = (name) => ({ name, render: () => null })
vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton'),
	NcDialog: stub('NcDialog'),
	NcEmptyContent: stub('NcEmptyContent'),
	NcLoadingIcon: stub('NcLoadingIcon'),
	NcSelect: stub('NcSelect'),
	NcTextField: stub('NcTextField'),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: stub('NcButton') }))
vi.mock('@nextcloud/vue/components/NcEmptyContent', () => ({ default: stub('NcEmptyContent') }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: stub('NcNoteCard') }))
vi.mock('../../src/modals/BulkReassignModal.vue', () => ({ default: stub('BulkReassignModal') }))
vi.mock('../../src/services/substitutionApi.js', () => ({
	createSubstitution: vi.fn(),
	fetchSubstitutionActions: vi.fn(),
	listSubstitutions: vi.fn(),
	revokeSubstitution: vi.fn(),
}))
vi.mock('../../src/services/dataSubjectRequestApi.js', () => ({
	fetchSubjectExportState: vi.fn(),
	previewErasure: vi.fn(),
	refusalOf: (error) => ({ reason: error?.message || 'unknown' }),
	requestSubjectExport: vi.fn(),
	runErasure: vi.fn(),
	subjectExportDownloadUrl: (id) => `/download/${id}`,
}))
const objectStore = { fetchCollection: vi.fn() }
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => objectStore }))

const api = await import('../../src/services/substitutionApi.js')
const dsr = await import('../../src/services/dataSubjectRequestApi.js')
const { default: SubstitutionAdmin } = await import('../../src/views/admin/SubstitutionAdmin.vue')
const { default: SubstitutionFormModal } = await import('../../src/modals/SubstitutionFormModal.vue')
const { default: DataSubjectRequestTab } = await import('../../src/views/cases/components/DataSubjectRequestTab.vue')

/**
 * Mount a component and let it load.
 *
 * @param {object} component The component.
 * @param {object} [props] Its props.
 * @return {Promise<object>} The wrapper.
 */
async function mountIt(component, props = {}) {
	const w = shallowMount(component, { props })
	await flushPromises()
	return w
}

beforeEach(() => {
	vi.clearAllMocks()
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	vi.restoreAllMocks()
})

describe('SubstitutionAdmin', () => {
	beforeEach(() => {
		api.listSubstitutions.mockResolvedValue([{ id: 1, absentee: 'Ann', substitute: 'Bob' }, { id: 2, absentee: 'Carl', substitute: 'Dana' }])
	})

	it('loads the substitutions and filters them by either person', async () => {
		const w = await mountIt(SubstitutionAdmin)
		expect(w.vm.filtered).toHaveLength(2)
		w.vm.filterText = ' BO '
		expect(w.vm.filtered.map((s) => s.id)).toEqual([1])
		w.vm.filterText = 'carl'
		expect(w.vm.filtered.map((s) => s.id)).toEqual([2])
		expect(w.vm.loading).toBe(false)
	})

	it('shows no substitutions when they cannot be loaded', async () => {
		api.listSubstitutions.mockRejectedValueOnce(new Error('x'))
		const w = await mountIt(SubstitutionAdmin)
		expect([w.vm.substitutions, w.vm.loading]).toEqual([[], false])
	})

	it('closes the form or the reassignment and reloads', async () => {
		const w = await mountIt(SubstitutionAdmin)
		Object.assign(w.vm, { showForm: true, showReassign: true })
		await w.vm.onCreated()
		await w.vm.onReassigned()
		expect([w.vm.showForm, w.vm.showReassign]).toEqual([false, false])
		expect(api.listSubstitutions).toHaveBeenCalledTimes(3)
	})

	it('revokes a substitution and reloads, keeping the list when revoking fails', async () => {
		const w = await mountIt(SubstitutionAdmin)
		await w.vm.revoke(1)
		expect(api.revokeSubstitution).toHaveBeenCalledWith(1)
		expect(api.listSubstitutions).toHaveBeenCalledTimes(2)
		api.revokeSubstitution.mockRejectedValueOnce(new Error('x'))
		await w.vm.revoke(1)
		expect(api.listSubstitutions).toHaveBeenCalledTimes(2)
	})

	it('shows the actions taken under a substitution, none when they cannot be read', async () => {
		api.fetchSubstitutionActions.mockResolvedValueOnce([{ id: 'a' }])
		const w = await mountIt(SubstitutionAdmin)
		await w.vm.openActions({ id: 1 })
		expect([w.vm.selectedSub, w.vm.actions]).toEqual([{ id: 1 }, [{ id: 'a' }]])
		api.fetchSubstitutionActions.mockRejectedValueOnce(new Error('x'))
		await w.vm.openActions({ id: 2 })
		expect([w.vm.selectedSub, w.vm.actions]).toEqual([{ id: 2 }, []])
	})
})

describe('SubstitutionFormModal', () => {
	beforeEach(() => {
		objectStore.fetchCollection.mockResolvedValue([{ id: 'ct1' }, { id: 'ct2' }])
	})

	it('needs a substitute before saving', async () => {
		const w = await mountIt(SubstitutionFormModal)
		expect(w.vm.caseTypes).toHaveLength(2)
		await w.vm.submit()
		expect(w.vm.errors).toEqual({ substitute: 'A substitute is required' })
		expect(api.createSubstitution).not.toHaveBeenCalled()
		expect(w.vm.reasonOptions.map((o) => o.value)).toEqual(['verlof', 'ziekte', 'anders'])
		expect(w.vm.scopeOptions.map((o) => o.value)).toEqual(['all', 'caseTypes'])
	})

	it('registers a substitution for all work, without an absentee unless a coordinator names one', async () => {
		api.createSubstitution.mockResolvedValue({ id: 's1' })
		const w = await mountIt(SubstitutionFormModal)
		Object.assign(w.vm.form, { absentee: 'ann', substitute: ' bob ', startDate: '2026-06-01', endDate: '2026-06-10', comment: 'Holiday' })
		await w.vm.submit()
		expect(api.createSubstitution).toHaveBeenCalledWith({ substitute: 'bob', startDate: '2026-06-01', endDate: '2026-06-10', reason: 'verlof', scope: 'all', scopeRefs: [], comment: 'Holiday' })
		expect(w.emitted('created')).toEqual([[{ id: 's1' }]])
		const coordinator = await mountIt(SubstitutionFormModal, { allowCoordinator: true })
		Object.assign(coordinator.vm.form, { absentee: ' ann ', substitute: 'bob' })
		coordinator.vm.selectedReason = { value: 'ziekte' }
		coordinator.vm.selectedScope = { value: 'caseTypes' }
		coordinator.vm.selectedCaseTypes = [{ id: 'ct1' }]
		await coordinator.vm.submit()
		expect(api.createSubstitution.mock.calls[1][0]).toMatchObject({ absentee: 'ann', reason: 'ziekte', scope: 'caseTypes', scopeRefs: ['ct1'] })
	})

	it('falls back to leave and all work when the choices are cleared, and shows a refusal', async () => {
		objectStore.fetchCollection.mockRejectedValueOnce(new Error('x'))
		const w = await mountIt(SubstitutionFormModal)
		expect(w.vm.caseTypes).toEqual([])
		Object.assign(w.vm, { selectedReason: null, selectedScope: null })
		w.vm.form.substitute = 'bob'
		api.createSubstitution.mockRejectedValueOnce({ response: { data: { error: 'Overlaps another substitution' } } })
		await w.vm.submit()
		expect(api.createSubstitution.mock.calls[0][0]).toMatchObject({ reason: 'verlof', scope: 'all' })
		expect([w.vm.serverError, w.vm.saving]).toEqual(['Overlaps another substitution', false])
		api.createSubstitution.mockRejectedValueOnce({})
		await w.vm.submit()
		expect(w.vm.serverError).toBe('Failed to register substitution.')
		w.vm.onDialogClose(true)
		w.vm.onDialogClose(false)
		expect(w.emitted('close')).toHaveLength(1)
	})
})

describe('DataSubjectRequestTab', () => {
	it('loads the export state for an access request, not for an erasure', async () => {
		dsr.fetchSubjectExportState.mockResolvedValueOnce({ exportId: 'e1', downloadable: true })
		const w = await mountIt(DataSubjectRequestTab, { objectId: 'c1', object: { dataSubjectRequestType: 'inzage' } })
		expect(dsr.fetchSubjectExportState).toHaveBeenCalledWith('c1')
		expect([w.vm.isRequest, w.vm.isErasure, w.vm.downloadUrl]).toEqual([true, false, '/download/e1'])
		await mountIt(DataSubjectRequestTab, { objectId: 'c2', object: { dataSubjectRequestType: 'verwijdering' } })
		await mountIt(DataSubjectRequestTab, { objectId: 'c3', object: {} })
		expect(dsr.fetchSubjectExportState).toHaveBeenCalledTimes(1)
	})

	it('starts from the stored erasure preview and outcome', async () => {
		const w = await mountIt(DataSubjectRequestTab, {
			objectId: 'c1',
			object: { dataSubjectRequestType: 'verwijdering', erasureCounts: { erase: { cases: 2, files: '3' } }, erasureProtected: [{ id: 'p' }], erasureOutcome: { complete: true } },
		})
		expect([w.vm.hasPreview, w.vm.total('erase'), w.vm.total('keep'), w.vm.protectedItems]).toEqual([true, 5, 0, [{ id: 'p' }]])
		expect(w.vm.outcomeSentence).toBe('The platform reports the erasure complete.')
	})

	it('takes a preview, runs the erasure and counts what is left', async () => {
		dsr.previewErasure.mockResolvedValueOnce({ report: { counts: { erase: { cases: 1 } }, protected: [] } })
		dsr.runErasure.mockResolvedValueOnce({ complete: false, withheld: [1], refused: [2, 3], failed: [] })
		const w = await mountIt(DataSubjectRequestTab, { objectId: 'c1', object: { dataSubjectRequestType: 'verwijdering', erasureOutcome: {} } })
		expect([w.vm.hasPreview, w.vm.outcome]).toEqual([false, null])
		await w.vm.takePreview()
		expect([w.vm.counts, w.vm.outcome]).toEqual([{ erase: { cases: 1 } }, null])
		await w.vm.takeRun()
		expect(dsr.runErasure).toHaveBeenCalledWith('c1')
		expect(w.vm.outcomeSentence).toBe('The erasure did not finish. 3 records still hold this person.')
		dsr.previewErasure.mockResolvedValueOnce({})
		await w.vm.takePreview()
		expect([w.vm.counts, w.vm.protectedItems]).toEqual([{}, []])
	})

	it('requests an export and reads its state, and keeps a refusal', async () => {
		dsr.fetchSubjectExportState.mockResolvedValue({ exportId: 'e2' })
		const w = await mountIt(DataSubjectRequestTab, { objectId: 'c1', object: { dataSubjectRequestType: 'correctie' } })
		await w.vm.askForExport()
		expect(dsr.requestSubjectExport).toHaveBeenCalledWith('c1')
		expect(w.vm.exportState).toEqual({ exportId: 'e2' })
		dsr.requestSubjectExport.mockRejectedValueOnce(new Error('Already running'))
		await w.vm.askForExport()
		expect([w.vm.refusal, w.vm.busy]).toEqual([{ reason: 'Already running' }, false])
	})
})
