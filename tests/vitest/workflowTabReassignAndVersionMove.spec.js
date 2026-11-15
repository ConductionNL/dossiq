// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The workflow settings tab (versions, save, publish, draft from published,
 * export and import), the bulk reassignment dialog (preview and execute), and
 * the dialog that moves a case to another case type version, mounted with
 * their stores and API doubled.
 *
 * @spec openspec/specs/workflow-definition-model/spec.md
 */

import axios from '@nextcloud/axios'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { reactive } from 'vue'

const stub = (name) => ({ name, render: () => null })
vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton'),
	NcLoadingIcon: stub('NcLoadingIcon'),
	NcDialog: stub('NcDialog'),
	NcEmptyContent: stub('NcEmptyContent'),
	NcSelect: stub('NcSelect'),
	NcTextField: stub('NcTextField'),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: stub('NcButton') }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: stub('NcNoteCard') }))
vi.mock('@nextcloud/vue/components/NcSelect', () => ({ default: stub('NcSelect') }))
vi.mock('@nextcloud/vue/components/NcTextArea', () => ({ default: stub('NcTextArea') }))
vi.mock('../../src/views/settings/WorkflowEditor.vue', () => ({
	default: { name: 'WorkflowEditor', render: () => null },
}))

const bus = []
vi.mock('@nextcloud/event-bus', () => ({ emit: (name, payload) => bus.push([name, payload]) }))

const workflowStore = reactive({
	versions: [],
	currentTemplate: null,
	activeVersion: null,
	draftVersion: null,
	validationErrors: [],
	listVersions: vi.fn(),
	getTemplate: vi.fn(),
	createTemplate: vi.fn(),
	saveTemplate: vi.fn(),
	publishVersion: vi.fn(),
	createDraftFromVersion: vi.fn(),
	exportWorkflow: vi.fn(() => ({ _format: 'dossiq-workflow-v1' })),
	importWorkflow: vi.fn(),
})
const objectStore = { fetchCollection: vi.fn() }
vi.mock('../../src/store/modules/workflow.js', () => ({ useWorkflowStore: () => workflowStore }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => objectStore }))
vi.mock('../../src/services/substitutionApi.js', () => ({ executeReassignment: vi.fn(), previewReassignment: vi.fn() }))

const substitution = await import('../../src/services/substitutionApi.js')
const { default: WorkflowTab } = await import('../../src/views/settings/tabs/WorkflowTab.vue')
const { default: BulkReassignModal } = await import('../../src/modals/BulkReassignModal.vue')
const { default: CaseVersionMoveDialog } = await import('../../src/dialogs/CaseVersionMoveDialog.vue')

beforeEach(() => {
	vi.clearAllMocks()
	bus.length = 0
	Object.assign(workflowStore, { versions: [], currentTemplate: null, activeVersion: null, draftVersion: null, validationErrors: [] })
	for (const value of Object.values(workflowStore)) {
		if (vi.isMockFunction(value)) {
			value.mockReset()
		}
	}
	workflowStore.exportWorkflow.mockImplementation(() => ({ _format: 'dossiq-workflow-v1' }))
	for (const verb of ['get', 'post']) {
		axios[verb].mockReset()
	}
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	vi.restoreAllMocks()
})

/**
 * Mount a component and let it load.
 *
 * @param {object} component The component.
 * @param {object} [props] Its props.
 * @param {object} [mocks] Extra global mocks.
 * @return {Promise<object>} The wrapper.
 */
async function mountIt(component, props = {}, mocks = {}) {
	const w = shallowMount(component, { props, global: { mocks } })
	await flushPromises()
	return w
}

describe('WorkflowTab', () => {
	it('opens the draft first, else the active version, else the newest', async () => {
		workflowStore.listVersions.mockImplementation(async () => {
			workflowStore.versions = [{ id: 'v3' }, { id: 'v2' }]
			workflowStore.draftVersion = { id: 'v2' }
		})
		const w = await mountIt(WorkflowTab, { caseTypeId: 'ct' })
		expect(workflowStore.listVersions).toHaveBeenCalledWith('ct')
		expect(w.vm.selectedVersionId).toBe('v2')
		expect(workflowStore.getTemplate).toHaveBeenCalledWith('v2')
		workflowStore.listVersions.mockImplementation(async () => {
			workflowStore.versions = [{ id: 'v9' }]
			workflowStore.draftVersion = null
		})
		await w.vm.loadVersions()
		expect(w.vm.selectedVersionId).toBe('v9')
		expect(w.vm.loading).toBe(false)
	})

	it('selects nothing without versions', async () => {
		const w = await mountIt(WorkflowTab, { caseTypeId: 'ct' })
		expect([w.vm.selectedVersionId, workflowStore.getTemplate.mock.calls.length, w.vm.hasDraft]).toEqual([null, 0, false])
	})

	it('describes the template it shows against the active version', async () => {
		const w = await mountIt(WorkflowTab, { caseTypeId: 'ct' })
		expect(w.vm.versionNotice).toBeNull()
		workflowStore.currentTemplate = { id: 'v1', version: 1, isDraft: false }
		workflowStore.activeVersion = { id: 'v2', version: 2 }
		workflowStore.versions = [{ id: 'v3', isDraft: true }]
		const fresh = await mountIt(WorkflowTab, { caseTypeId: 'ct' })
		expect(fresh.vm.versionNotice).toBe('Viewing version 1. Active version is 2.')
		expect([fresh.vm.currentIsDraft, fresh.vm.currentIsPublished, fresh.vm.hasDraft]).toEqual([false, true, true])
		workflowStore.currentTemplate = { id: 'v2', version: 2, isDraft: false }
		const active = await mountIt(WorkflowTab, { caseTypeId: 'ct' })
		expect(active.vm.versionNotice).toBeNull()
	})

	it('reloads the chosen version and clears the edit state', async () => {
		const w = await mountIt(WorkflowTab, { caseTypeId: 'ct' })
		w.vm.dirty = true
		w.vm.publishErrors = ['x']
		await w.vm.onVersionChange()
		expect(workflowStore.getTemplate).not.toHaveBeenCalled()
		w.vm.selectedVersionId = 'v1'
		await w.vm.onVersionChange()
		expect(workflowStore.getTemplate).toHaveBeenCalledWith('v1')
		expect([w.vm.dirty, w.vm.publishErrors]).toEqual([false, []])
	})

	it('creates a first workflow and selects it', async () => {
		const w = await mountIt(WorkflowTab, { caseTypeId: 'ct' })
		workflowStore.createTemplate.mockResolvedValueOnce({ id: 'new' })
		await w.vm.createWorkflow()
		expect(workflowStore.createTemplate).toHaveBeenCalledWith('ct', 'Workflow')
		expect(w.vm.selectedVersionId).toBe('new')
		workflowStore.createTemplate.mockResolvedValueOnce(null)
		w.vm.selectedVersionId = 'keep'
		await w.vm.createWorkflow()
		expect([w.vm.selectedVersionId, w.vm.loading]).toEqual(['keep', false])
	})

	/**
	 * A workflow tab context with its methods bound and an editor double.
	 *
	 * @param {boolean} valid What the editor's validate() answers.
	 * @return {object} The context.
	 */
	function tabContext(valid) {
		const ctx = {
			...WorkflowTab.data(),
			caseTypeId: 'ct',
			workflowStore,
			currentTemplate: workflowStore.currentTemplate,
			$refs: { editor: { validate: () => valid, statusNodes: [{ id: 'open' }] } },
		}
		for (const [name, fn] of Object.entries(WorkflowTab.methods)) {
			ctx[name] = fn.bind(ctx)
		}
		return ctx
	}

	it('saves unsaved edits before publishing, and shows the validation errors of a refused publish', async () => {
		workflowStore.currentTemplate = { id: 'v1', isDraft: true }
		const ctx = tabContext(true)
		ctx.selectedVersionId = 'v1'
		ctx.dirty = true
		workflowStore.publishVersion.mockResolvedValueOnce({ id: 'v1' })
		await ctx.publish()
		expect(workflowStore.saveTemplate).toHaveBeenCalledWith({ id: 'v1', isDraft: true })
		expect(workflowStore.publishVersion).toHaveBeenCalledWith('v1', [{ id: 'open' }])
		expect(workflowStore.listVersions).toHaveBeenCalledWith('ct')
		expect([ctx.dirty, ctx.publishing]).toEqual([false, false])
		workflowStore.publishVersion.mockResolvedValueOnce(null)
		workflowStore.validationErrors = [{ rule: 'NO_FINAL_STATUS' }]
		await ctx.publish()
		expect(ctx.publishErrors).toEqual([{ rule: 'NO_FINAL_STATUS' }])
		expect(workflowStore.saveTemplate).toHaveBeenCalledTimes(1)
	})

	it('stops before publishing when the editor finds the graph invalid', async () => {
		workflowStore.validationErrors = [{ rule: 'ORPHAN_NODE' }]
		const ctx = tabContext(false)
		await ctx.publish()
		expect(workflowStore.publishVersion).not.toHaveBeenCalled()
		expect([ctx.publishErrors, ctx.publishing]).toEqual([[{ rule: 'ORPHAN_NODE' }], false])
	})

	it('drafts a new version from the published one', async () => {
		const w = await mountIt(WorkflowTab, { caseTypeId: 'ct' })
		w.vm.selectedVersionId = 'v1'
		workflowStore.createDraftFromVersion.mockResolvedValueOnce({ id: 'v2' })
		await w.vm.editPublished()
		expect(workflowStore.createDraftFromVersion).toHaveBeenCalledWith('v1')
		expect(w.vm.selectedVersionId).toBe('v2')
		workflowStore.createDraftFromVersion.mockResolvedValueOnce(null)
		await w.vm.editPublished()
		expect(w.vm.selectedVersionId).toBe('v2')
	})

	it('exports the current template as a named JSON download with the case type vocabulary', async () => {
		URL.createObjectURL = vi.fn(() => 'blob:x')
		URL.revokeObjectURL = vi.fn()
		let downloadName = ''
		vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function () { downloadName = this.download })
		objectStore.fetchCollection.mockImplementation(async (type) => (type === 'statusType' ? [{ id: 's' }] : null))
		const w = await mountIt(WorkflowTab, { caseTypeId: 'ct' })
		await w.vm.exportWorkflow()
		expect(workflowStore.exportWorkflow).not.toHaveBeenCalled()
		workflowStore.currentTemplate = { title: 'Omgevings Vergunning', version: 3 }
		const ready = await mountIt(WorkflowTab, { caseTypeId: 'ct' })
		await ready.vm.exportWorkflow()
		expect(objectStore.fetchCollection.mock.calls.map((c) => c[0])).toEqual(['statusType', 'roleType', 'documentType'])
		expect(objectStore.fetchCollection.mock.calls[0][1]).toEqual({ caseType: 'ct', _limit: 100 })
		expect(workflowStore.exportWorkflow).toHaveBeenCalledWith({ title: 'Omgevings Vergunning', version: 3 }, [{ id: 's' }], [], [])
		expect(downloadName).toBe('omgevings-vergunning-v3-workflow.json')
	})

	it('imports a workflow file, reporting the types it misses', async () => {
		objectStore.fetchCollection.mockResolvedValue([])
		const w = await mountIt(WorkflowTab, { caseTypeId: 'ct' })
		const event = (text) => ({ target: { value: 'f.json', files: text === null ? [] : [{ text: async () => text }] } })
		await w.vm.handleImport(event(null))
		expect(workflowStore.importWorkflow).not.toHaveBeenCalled()
		workflowStore.importWorkflow.mockResolvedValueOnce({ success: false, missingTypes: { statusTypes: ['Gone'] } })
		const missing = event('{"title":"W"}')
		await w.vm.handleImport(missing)
		expect(workflowStore.importWorkflow).toHaveBeenCalledWith({ title: 'W' }, 'ct', [], [], [])
		expect([w.vm.importReport, missing.target.value]).toEqual([{ statusTypes: ['Gone'] }, ''])
		workflowStore.importWorkflow.mockResolvedValueOnce({ success: true, template: { id: 'imp' } })
		await w.vm.handleImport(event('{}'))
		expect([w.vm.selectedVersionId, w.vm.importReport]).toEqual(['imp', null])
		const bad = event('not json')
		await w.vm.handleImport(bad)
		expect(bad.target.value).toBe('')
		expect(console.error).toHaveBeenCalled()
	})
})

describe('BulkReassignModal', () => {
	beforeEach(() => {
		objectStore.fetchCollection.mockResolvedValue([{ id: 'ct1', title: 'Permit' }])
	})

	it('loads the case types and lays out the preview rows', async () => {
		const w = await mountIt(BulkReassignModal)
		expect(w.vm.caseTypeMap).toEqual({ ct1: 'Permit' })
		expect([w.vm.previewCount, w.vm.previewRows, w.vm.canExecute]).toEqual([0, [], false])
		substitution.previewReassignment.mockResolvedValueOnce({
			cases: [{ id: 'c1', title: 'Case', caseType: 'ct1', status: 'open', deadline: '2026-06-01T10:00:00Z' }, { id: 'c2' }],
			tasks: [{ id: 't1', title: 'Task', status: 'active', dueDate: '2026-06-02' }],
		})
		w.vm.fromUser = ' ann '
		w.vm.selectedCaseType = { id: 'ct1' }
		await w.vm.loadPreview()
		expect(substitution.previewReassignment).toHaveBeenCalledWith('ann', 'ct1')
		expect(w.vm.previewRows).toEqual([
			{ type: 'case', id: 'c1', title: 'Case', caseTypeName: 'Permit', status: 'open', deadline: '2026-06-01' },
			{ type: 'case', id: 'c2', title: '—', caseTypeName: '', status: '', deadline: '' },
			{ type: 'task', id: 't1', title: 'Task', caseTypeName: '', status: 'active', deadline: '2026-06-02' },
		])
		expect(w.vm.canExecute).toBe(false)
		w.vm.toUser = 'bob'
		expect(w.vm.canExecute).toBe(true)
	})

	it('executes the reassignment and reports it', async () => {
		const w = await mountIt(BulkReassignModal)
		Object.assign(w.vm, { fromUser: 'ann', toUser: ' bob ', preview: { cases: [{}], tasks: [] } })
		substitution.executeReassignment.mockResolvedValueOnce({ moved: 1 })
		await w.vm.execute()
		expect(substitution.executeReassignment).toHaveBeenCalledWith('ann', 'bob', undefined)
		expect([w.vm.results, w.vm.preview]).toEqual([{ moved: 1 }, null])
		expect(w.emitted('reassigned')).toEqual([[{ moved: 1 }]])
	})

	it('shows preview and execute failures, and has no case types when they cannot load', async () => {
		objectStore.fetchCollection.mockRejectedValueOnce(new Error('x'))
		const w = await mountIt(BulkReassignModal)
		expect(w.vm.caseTypes).toEqual([])
		substitution.previewReassignment.mockRejectedValueOnce({ response: { data: { error: 'Unknown user' } } })
		await w.vm.loadPreview()
		expect(w.vm.serverError).toBe('Unknown user')
		substitution.previewReassignment.mockRejectedValueOnce({})
		await w.vm.loadPreview()
		expect([w.vm.serverError, w.vm.loadingPreview]).toEqual(['Preview failed.', false])
		substitution.executeReassignment.mockRejectedValueOnce(new Error('Locked'))
		await w.vm.execute()
		substitution.executeReassignment.mockRejectedValueOnce({})
		await w.vm.execute()
		expect([w.vm.serverError, w.vm.executing]).toEqual(['Reassignment failed.', false])
		w.vm.onDialogClose(true)
		w.vm.onDialogClose(false)
		expect(w.emitted('close')).toHaveLength(1)
	})
})

describe('CaseVersionMoveDialog', () => {
	/**
	 * A move preview with every list empty.
	 *
	 * @param {object} over Fields to set.
	 * @return {object} The preview.
	 */
	const preview = (over) => ({ fields: { added: [], answered: [], removed: [] }, statuses: { added: [], removed: [] }, refusals: [], run: {}, status: {}, ...over })

	const endpoint = '/index.php/apps/dossiq/api/case/c%201/version-move'

	it('loads the versions the case can move to', async () => {
		axios.get.mockResolvedValueOnce({ data: { current: { version: 1 }, targets: [{ id: 'v2', version: 2 }] } })
		const w = await mountIt(CaseVersionMoveDialog, { caseId: 'c 1' })
		expect(axios.get.mock.calls[0][0]).toBe(endpoint)
		expect(w.vm.targetOptions).toEqual([{ id: 'v2', label: 'Version 2' }])
		expect(w.vm.current).toEqual({ version: 1 })
	})

	it('previews a target and moves the case only with a reason', async () => {
		axios.get.mockResolvedValueOnce({ data: { targets: [] } })
		const w = await mountIt(CaseVersionMoveDialog, {}, { $route: { params: { id: 'c 1' } } })
		axios.get.mockResolvedValueOnce({ data: { preview: preview({ canMove: true, status: { mapped: true, to: 'Open' } }) } })
		w.vm.target = 'v2'
		await flushPromises()
		expect(axios.get.mock.calls[1]).toEqual([endpoint, { params: { target: 'v2' } }])
		expect(w.vm.statusLine).toBe('The case stays in Open.')
		expect(w.vm.canConfirm).toBe(false)
		await w.vm.confirm()
		expect(axios.post).not.toHaveBeenCalled()
		w.vm.reason = ' Law changed '
		expect(w.vm.canConfirm).toBe(true)
		axios.post.mockResolvedValueOnce({})
		await w.vm.confirm()
		expect(axios.post).toHaveBeenCalledWith(endpoint, { target: 'v2', reason: 'Law changed' })
		expect(bus).toEqual([['cn:page:refresh', {}]])
		expect(w.emitted('close')).toHaveLength(1)
	})

	it('says when the case has nowhere to land, and shows refusals', async () => {
		axios.get.mockRejectedValueOnce({ response: { data: { message: 'No versions' } } })
		const w = await mountIt(CaseVersionMoveDialog, { caseId: 'c1' })
		expect([w.vm.error, w.vm.targets, w.vm.statusLine]).toEqual(['No versions', [], 'This case has nowhere to land.'])
		axios.get.mockRejectedValueOnce({})
		w.vm.target = 'v2'
		await flushPromises()
		expect([w.vm.error, w.vm.preview]).toEqual(['The case was not moved.', null])
		w.vm.target = ''
		await flushPromises()
		expect(w.vm.preview).toBeNull()
		Object.assign(w.vm, { target: 'v3', reason: 'r' })
		axios.get.mockResolvedValueOnce({ data: { preview: preview({ canMove: true }) } })
		await flushPromises()
		axios.post.mockRejectedValueOnce({ response: { data: { message: 'Locked case' } } })
		await w.vm.confirm()
		expect([w.vm.error, w.vm.busy]).toEqual(['Locked case', false])
		expect(w.emitted('close')).toBeUndefined()
	})
})
