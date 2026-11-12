// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case list's bulk-action handlers (which dialog opens with which scope,
 * and what happens once it finishes), the mandate editor's defaults and
 * validation, the case copy dialog, the role assignments table, the ZGW
 * mapping dialog, the access link dialog, and the assignment and role dialogs.
 *
 * @spec openspec/changes/one-case-list/specs/case-bulk-status-transition/spec.md
 */

import axios from '@nextcloud/axios'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const stub = (name) => ({ name, render: () => null })
const mounted = []
const emitted = []
vi.mock('vue', async (orig) => {
	const vue = await orig()
	return {
		...vue,
		createApp: (component, props) => {
			const app = { component, props, mount: vi.fn(), unmount: vi.fn() }
			mounted.push(app)
			return app
		},
	}
})
vi.mock('@nextcloud/event-bus', () => ({ emit: (name, payload) => emitted.push([name, payload]), subscribe: () => {}, unsubscribe: () => {} }))
vi.mock('../../src/dialogs/BulkTransitionDialog.vue', () => ({ default: stub('BulkTransitionDialog') }))
vi.mock('../../src/dialogs/ReassignSelectionDialog.vue', () => ({ default: stub('ReassignSelectionDialog') }))
vi.mock('../../src/dialogs/EndAssignmentDialog.vue', () => ({ default: stub('EndAssignmentDialog') }))
vi.mock('../../src/services/bulkJobApi.js', () => ({ countMatchingCases: vi.fn(async () => 0) }))
vi.mock('../../src/utils/casesChanged.js', () => ({ announceCasesChanged: vi.fn() }))
vi.mock('../../src/utils/selectionScope.js', () => ({ readLocationFilters: () => ({ status: 'open' }) }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDialog: stub('CnDialog') }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton'),
	NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch'),
	NcDialog: stub('NcDialog'),
	NcEmptyContent: stub('NcEmptyContent'),
	NcLoadingIcon: stub('NcLoadingIcon'),
	NcModal: stub('NcModal'),
	NcSelect: stub('NcSelect'),
	NcTextField: stub('NcTextField'),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: stub('NcButton') }))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({ default: stub('NcCheckboxRadioSwitch') }))
vi.mock('@nextcloud/vue/components/NcTextField', () => ({ default: stub('NcTextField') }))

const bulk = await import('../../src/utils/caseBulkActions.js')
const { countMatchingCases } = await import('../../src/services/bulkJobApi.js')
const { announceCasesChanged } = await import('../../src/utils/casesChanged.js')
const { default: MandaatEditor } = await import('../../src/modals/MandaatEditor.vue')
const { default: CaseCopyDialog } = await import('../../src/dialogs/CaseCopyDialog.vue')
const { default: RoleAssignmentsTable } = await import('../../src/views/settings/components/RoleAssignmentsTable.vue')
const { default: ZgwMappingDialog } = await import('../../src/dialogs/ZgwMappingDialog.vue')
const { default: CreateAccessLinkDialog } = await import('../../src/dialogs/CreateAccessLinkDialog.vue')
const { default: AddAssignmentDialog } = await import('../../src/dialogs/AddAssignmentDialog.vue')
const { default: RolEditorDialog } = await import('../../src/dialogs/RolEditorDialog.vue')

globalThis.t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))

beforeEach(() => {
	mounted.length = 0
	emitted.length = 0
	vi.clearAllMocks()
	document.body.innerHTML = ''
	for (const verb of ['get', 'post', 'patch']) {
		axios[verb].mockReset()
	}
})

afterEach(() => {
	vi.useRealTimers()
})

describe('bulk actions on the case list', () => {
	it('opens nothing for an empty or missing selection', async () => {
		await bulk.reassignSelection({ selectedIds: [] })
		await bulk.reassignSelection({})
		bulk.transitionSelection({ selectedIds: null })
		await flushPromises()
		expect(mounted).toEqual([])
		expect(countMatchingCases).not.toHaveBeenCalled()
	})

	it('offers the whole result only when it is larger than the selection', async () => {
		countMatchingCases.mockResolvedValueOnce(40)
		await bulk.reassignSelection({ selectedIds: ['a', 'b'] })
		expect(mounted[0].props).toMatchObject({ open: true, selectedIds: ['a', 'b'], filters: { status: 'open' }, matchingTotal: 40 })
		expect(mounted[0].mount).toHaveBeenCalledTimes(1)
		countMatchingCases.mockResolvedValueOnce(2)
		await bulk.reassignSelection({ selectedIds: ['a', 'b'] })
		expect(mounted[1].props.matchingTotal).toBe(0)
	})

	it('refreshes the list after a reassignment and removes the dialog when it closes', async () => {
		await bulk.reassignSelection({ selectedIds: ['a'] })
		const app = mounted[0]
		expect(document.body.children).toHaveLength(1)
		app.props.onReassigned()
		expect(announceCasesChanged).toHaveBeenCalledTimes(1)
		app.props['onUpdate:open'](true)
		expect(app.unmount).not.toHaveBeenCalled()
		app.props['onUpdate:open'](false)
		expect([app.unmount.mock.calls.length, document.body.children.length]).toEqual([1, 0])
	})

	it('opens the bulk dialog in the mode of each action', async () => {
		bulk.transitionSelection({ selectedIds: ['a'] })
		bulk.suspendSelection({ selectedIds: ['a'] })
		bulk.resumeSelection({ selectedIds: ['a'] })
		bulk.extendTermSelection({ selectedIds: ['a'] })
		await flushPromises()
		expect(mounted.map((app) => app.props.mode)).toEqual(['transition', 'suspend', 'resume', 'extend'])
		expect(mounted[0].props.caseIds).toEqual(['a'])
	})

	it('closes, refreshes the list and raises the notice when a bulk job completes', async () => {
		bulk.transitionSelection({ selectedIds: ['a'] })
		await flushPromises()
		const app = mounted[0]
		app.props.onCompleted({ outcome: { notice: { type: 'warning', text: '2 skipped' } } })
		expect([app.unmount.mock.calls.length, announceCasesChanged.mock.calls.length]).toEqual([1, 1])
		expect(emitted).toEqual([['cn:page:notice', { notice: { type: 'warning', text: '2 skipped' } }]])
		bulk.suspendSelection({ selectedIds: ['b'] })
		await flushPromises()
		mounted[1].props.onClose()
		expect([mounted[1].unmount.mock.calls.length, document.body.children.length]).toEqual([1, 0])
	})

	it('reads a notice from the job or its outcome, and only an object', () => {
		expect(bulk.noticeOfJob({ notice: { a: 1 } })).toEqual({ a: 1 })
		expect(bulk.noticeOfJob({ notice: { a: 1 }, outcome: { notice: { b: 2 } } })).toEqual({ a: 1 })
		expect([bulk.noticeOfJob({ notice: ['x'] }), bulk.noticeOfJob({ notice: 'x' }), bulk.noticeOfJob(null)]).toEqual([null, null, null])
		bulk.raiseJobNotice({})
		expect(emitted).toEqual([])
	})
})

describe('MandaatEditor', () => {
	it('starts a new mandate effective today with decision authority', () => {
		vi.useFakeTimers({ now: new Date('2026-05-15T12:00:00Z'), toFake: ['Date'] })
		const w = shallowMount(MandaatEditor)
		expect([w.vm.title, w.vm.form.inWerkingtreding, w.vm.selectedType.id, w.vm.selectedRole, w.vm.voorwaardenJson]).toEqual(['New mandaat', '2026-05-15', 'beslissingsbevoegdheid', null, '{}'])
		expect(w.vm.typeOptions).toHaveLength(4)
	})

	it('names every missing field and refuses invalid conditions', async () => {
		const w = shallowMount(MandaatEditor)
		w.vm.form.competenceType = ''
		w.vm.voorwaardenJson = '{ nope'
		await w.vm.save()
		expect(Object.keys(w.vm.errors)).toEqual(['mandateNumber', 'description', 'competenceType', 'legalBasis', 'terms'])
		expect(w.emitted('save')).toBeUndefined()
	})

	it('edits an existing mandate and saves the parsed conditions', async () => {
		const mandaat = { mandateNumber: 'M-1', description: 'Permits', competenceType: 'doormandaat', legalBasis: 'Awb 10:3', toegewezenRol: 'r2', terms: { max: 5 } }
		const w = shallowMount(MandaatEditor, { props: { mandaat, roleOptions: [{ id: 'r1' }, { id: 'r2', label: 'Lead' }] } })
		expect([w.vm.title, w.vm.selectedType.id, w.vm.selectedRole]).toEqual(['Edit mandaat', 'doormandaat', { id: 'r2', label: 'Lead' }])
		w.vm.voorwaardenJson = '{"max": 9}'
		await w.vm.save()
		expect(w.emitted('save')[0][0]).toMatchObject({ mandateNumber: 'M-1', terms: { max: 9 } })
		w.vm.voorwaardenJson = '  '
		w.vm.form.competenceType = 'odd'
		await w.vm.save()
		expect([w.emitted('save')[1][0].terms, w.vm.selectedType.id, w.vm.saving]).toEqual([{}, 'beslissingsbevoegdheid', false])
	})
})

describe('CaseCopyDialog', () => {
	const mountCopy = async (props, push = vi.fn()) => {
		const w = shallowMount(CaseCopyDialog, { props, global: { mocks: { $route: { params: { id: 'route-case' } }, $router: { push } } } })
		await flushPromises()
		return w
	}

	it('proposes a title from the case, falling back when the case cannot be read', async () => {
		axios.get.mockResolvedValueOnce({ data: { title: 'Dakkapel' } })
		const w = await mountCopy({ caseId: 'c 1' })
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/dossiq/case/c%201')
		expect([w.vm.title, w.vm.canConfirm]).toEqual(['Copy of Dakkapel', true])
		axios.get.mockRejectedValueOnce(new Error('x'))
		const fromRoute = await mountCopy({})
		expect([fromRoute.vm.targetCaseId, fromRoute.vm.title]).toEqual(['route-case', 'Copy'])
	})

	it('copies the case with a trimmed title and opens the copy', async () => {
		axios.get.mockResolvedValueOnce({ data: {} })
		const push = vi.fn()
		const w = await mountCopy({ caseId: 'c1' }, push)
		Object.assign(w.vm, { title: '  New one  ', withDocuments: true })
		axios.post.mockResolvedValueOnce({ data: { id: 42 } })
		await w.vm.confirm()
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/dossiq/api/case/c1/copy', { title: 'New one', documents: true })
		expect(w.emitted('close')).toHaveLength(1)
		expect(push).toHaveBeenCalledWith({ name: 'CaseDetail', params: { id: '42' } })
	})

	it('confirms nothing without a title and explains a refused copy', async () => {
		axios.get.mockResolvedValueOnce({ data: {} })
		const w = await mountCopy({ caseId: 'c1' })
		w.vm.title = '   '
		expect(w.vm.canConfirm).toBe(false)
		await w.vm.confirm()
		expect(axios.post).not.toHaveBeenCalled()
		w.vm.title = 'Copy'
		axios.post.mockRejectedValueOnce({ response: { data: { code: 'copy_failed' } } })
		await w.vm.confirm()
		expect([w.vm.error, w.vm.busy, w.emitted('close')]).toEqual(['The copy could not be created.', false, undefined])
		axios.post.mockResolvedValueOnce({ data: {} })
		await w.vm.confirm()
		expect(w.emitted('close')).toHaveLength(1)
	})
})

describe('RoleAssignmentsTable', () => {
	it('tells observers and substitutes from regular assignments and labels the role', () => {
		const w = shallowMount(RoleAssignmentsTable, { props: { roleOptions: [{ id: 'r1', label: 'Lead' }] } })
		expect([w.vm.isWaarnemer({ allocationType: 'Observer' }), w.vm.isWaarnemer({ type: 'plaatsvervanger' }), w.vm.isWaarnemer({})]).toEqual([true, true, false])
		expect([w.vm.typeClass({ type: 'observer' }), w.vm.typeClass({})]).toEqual(['toewijzingen-table__type--waarnemer', 'toewijzingen-table__type--regular'])
		expect([w.vm.roleLabel({ roleId: 'r1' }), w.vm.roleLabel({ role: 'x', rolLabel: 'Named' }), w.vm.roleLabel({ role: 'x' }), w.vm.roleLabel({})]).toEqual(['Lead', 'Named', 'x', '—'])
	})

	it('adds and ends an assignment, keeping the dialog open on failure', async () => {
		const w = shallowMount(RoleAssignmentsTable)
		w.vm.addOpen = true
		axios.post.mockRejectedValueOnce(new Error('x'))
		await w.vm.onAdd({ role: 'r1' })
		expect([w.vm.addOpen, w.emitted('reload')]).toEqual([true, undefined])
		axios.post.mockResolvedValueOnce({})
		await w.vm.onAdd({ role: 'r1' })
		expect(axios.post).toHaveBeenLastCalledWith('/index.php/apps/dossiq/api/mandate/toewijzingen', { role: 'r1' })
		expect([w.vm.addOpen, w.emitted('reload')]).toEqual([false, [[]]])
		w.vm.openEnd({ id: 'a 1', role: 'r1' })
		axios.patch.mockRejectedValueOnce(new Error('x'))
		await w.vm.onEnd('2026-06-01')
		expect(w.vm.ending).toEqual({ id: 'a 1', role: 'r1' })
		axios.patch.mockResolvedValueOnce({})
		await w.vm.onEnd('2026-06-01')
		expect(axios.patch).toHaveBeenLastCalledWith('/index.php/apps/dossiq/api/mandate/toewijzingen/a%201', { id: 'a 1', role: 'r1', validUntil: '2026-06-01' })
		expect([w.vm.ending, w.emitted('reload')]).toEqual([null, [[], []]])
	})
})

describe('ZgwMappingDialog', () => {
	it('fills the form from the mapping each time it opens', async () => {
		const mapping = { enabled: false, sourceRegister: 'dossiq', sourceSchema: 'case', propertyMapping: { a: 'b' } }
		const w = shallowMount(ZgwMappingDialog, { props: { open: true, resourceKey: 'zaken', mapping } })
		expect([w.vm.form, JSON.parse(w.vm.propertyMappingJson), w.vm.valueMappingJson]).toEqual([{ enabled: false, sourceRegister: 'dossiq', sourceSchema: 'case' }, { a: 'b' }, '{}'])
		const closed = shallowMount(ZgwMappingDialog, { props: { open: false, mapping } })
		expect(closed.vm.form.sourceRegister).toBe('')
		await closed.setProps({ open: true, mapping: {} })
		expect(closed.vm.form).toEqual({ enabled: true, sourceRegister: '', sourceSchema: '' })
	})

	it('saves the parsed mappings for the resource, or names the JSON error', () => {
		const w = shallowMount(ZgwMappingDialog, { props: { open: true, resourceKey: 'zaken' } })
		w.vm.reverseMappingJson = '{"x": 1}'
		w.vm.onSave()
		expect(w.emitted('save')[0][0]).toEqual({ enabled: true, sourceRegister: '', sourceSchema: '', zgwResource: 'zaken', zgwApiVersion: '1', propertyMapping: {}, reverseMapping: { x: 1 }, valueMapping: {}, queryParameterMapping: {} })
		w.vm.queryParamMappingJson = '{ broken'
		w.vm.onSave()
		expect(w.vm.jsonError).toMatch(/^Invalid JSON in one of the mapping fields: /)
		expect(w.emitted('save')).toHaveLength(1)
	})
})

describe('CreateAccessLinkDialog', () => {
	it('expires a new link in two weeks and toggles the shared documents', () => {
		vi.useFakeTimers({ now: new Date('2026-05-15T12:00:00Z'), toFake: ['Date'] })
		const w = shallowMount(CreateAccessLinkDialog, { props: { caseId: 'c1' } })
		expect(w.vm.form.expiresAt).toBe('2026-05-29')
		w.vm.toggleDocument('d1', true)
		w.vm.toggleDocument('d2', true)
		w.vm.toggleDocument('d1', true)
		expect(w.vm.form.documents).toEqual(['d2', 'd1'])
		w.vm.toggleDocument('d2', false)
		expect(w.vm.form.documents).toEqual(['d1'])
	})

	it('grants read plus the chosen capabilities, without an empty password', () => {
		const w = shallowMount(CreateAccessLinkDialog, { props: { caseId: 'c1' } })
		w.vm.createLink()
		expect(w.emitted('created')[0][0]).toMatchObject({ caseId: 'c1', shareType: 'link', capabilities: ['read', 'comment'], password: null, sharedDocuments: [] })
		Object.assign(w.vm.form, { comment: false, upload: true, password: 'secret', label: 'Neighbour' })
		w.vm.createLink()
		expect(w.emitted('created')[1][0]).toMatchObject({ capabilities: ['read', 'upload'], password: 'secret', label: 'Neighbour' })
		expect(w.vm.saving).toBe(false)
	})
})

describe('AddAssignmentDialog', () => {
	it('starts a regular assignment from today and requires a person and a role', () => {
		vi.useFakeTimers({ now: new Date('2026-05-15T12:00:00Z'), toFake: ['Date'] })
		const w = shallowMount(AddAssignmentDialog, { props: { roleOptions: [{ id: 'r1', label: 'Lead' }] } })
		expect([w.vm.form.vanaf, w.vm.selectedType.id, w.vm.selectedRole]).toEqual(['2026-05-15', 'reguliere', null])
		w.vm.save()
		expect([w.vm.errors, w.emitted('save')]).toEqual([{ persoonId: 'Person is required', roleId: 'Role is required' }, undefined])
	})

	it('saves the assignment once a person and a role are chosen', () => {
		const w = shallowMount(AddAssignmentDialog, { props: { roleOptions: [{ id: 'r1', label: 'Lead' }] } })
		Object.assign(w.vm.form, { persoonId: 'ann', roleId: 'r1', allocationType: 'odd' })
		expect([w.vm.selectedRole, w.vm.selectedType.id, w.vm.typeOptions.length]).toEqual([{ id: 'r1', label: 'Lead' }, 'reguliere', 3])
		w.vm.save()
		expect([w.vm.errors, w.emitted('save')[0][0]]).toEqual([{}, expect.objectContaining({ persoonId: 'ann', roleId: 'r1' })])
	})
})

describe('RolEditorDialog', () => {
	it('starts a new employee role and requires a name', () => {
		const w = shallowMount(RolEditorDialog, { props: { parentOptions: [{ id: 'p0' }, { id: 'p1' }] } })
		expect([w.vm.title, w.vm.selectedType.id, w.vm.selectedParent.id, w.vm.form.mandateLevel]).toEqual(['New role', 'employee', 'p0', 1])
		w.vm.save()
		expect([w.vm.errors, w.emitted('save')]).toEqual([{ name: 'Naam is required' }, undefined])
	})

	it('edits a role and saves it', () => {
		const role = { name: 'Head of permits', type: 'manager', parentRole: 'p1', department: 'VTH', mandateLevel: 3 }
		const w = shallowMount(RolEditorDialog, { props: { role, parentOptions: [{ id: 'p0' }, { id: 'p1' }] } })
		expect([w.vm.title, w.vm.selectedType.id, w.vm.selectedParent.id]).toEqual(['Edit role', 'manager', 'p1'])
		w.vm.save()
		expect(w.emitted('save')[0][0]).toEqual({ name: 'Head of permits', type: 'manager', parentRole: 'p1', department: 'VTH', team: '', mandateLevel: 3 })
		w.vm.form.type = 'odd'
		expect(w.vm.selectedType.id).toBe('employee')
	})
})
