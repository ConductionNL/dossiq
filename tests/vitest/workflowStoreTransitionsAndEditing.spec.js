/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The workflow store's rules and editing: which transitions a case may take
 * (roles, guards, required steps), publishing, drafting a new version, export
 * and import with type mapping, template interpolation, and the step,
 * transition and node edits on the current template.
 *
 * @spec openspec/specs/workflow-definition-model/spec.md
 */

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const objectStore = {
	fetchCollection: vi.fn(),
	fetchObject: vi.fn(),
	saveObject: vi.fn(),
	deleteObject: vi.fn(),
}

vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => objectStore,
}))

vi.mock('../../src/store/modules/engineTask.js', () => ({
	useEngineTaskStore: () => ({ create: vi.fn(), error: null }),
}))

const { useWorkflowStore } = await import('../../src/store/modules/workflow.js')

/**
 * Translate as the identity, filling {placeholders}.
 *
 * @param {string} app The app id.
 * @param {string} text The text.
 * @param {object} vars The placeholders.
 * @return {string} The filled text.
 */
const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))

let store

beforeEach(() => {
	globalThis.t = t
	globalThis.OC = { requestToken: 'tok' }
	setActivePinia(createPinia())
	store = useWorkflowStore()
	for (const fn of Object.values(objectStore)) {
		fn.mockReset()
	}
})

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('getters', () => {
	it('picks the active and the draft version', () => {
		store.versions = [{ id: 1, isActive: true, isDraft: true }, { id: 2, isActive: true }, { id: 3, isDraft: true }]
		expect([store.activeVersion.id, store.draftVersion.id]).toEqual([2, 1])
		store.versions = []
		expect([store.activeVersion, store.draftVersion]).toEqual([null, null])
	})

	it('parses steps, transitions and positions from text or objects, and survives bad JSON', () => {
		expect([store.parsedSteps, store.parsedTransitions, store.parsedNodePositions]).toEqual([[], [], {}])
		store.currentTemplate = { steps: '[{"id":"s"}]', transitions: [{ id: 't' }], nodePositions: '{"a":{"x":1}}' }
		expect([store.parsedSteps, store.parsedTransitions, store.parsedNodePositions]).toEqual([[{ id: 's' }], [{ id: 't' }], { a: { x: 1 } }])
		store.currentTemplate = { steps: '{bad', transitions: '{bad', nodePositions: '{bad' }
		expect([store.parsedSteps, store.parsedTransitions, store.parsedNodePositions]).toEqual([[], [], {}])
	})
})

describe('computeAvailableTransitions', () => {
	const workflow = {
		steps: JSON.stringify([
			{ id: 'st1', status: 'open', isRequired: true, title: 'Intake' },
			{ id: 'st2', status: 'open', isRequired: false, title: 'Optional' },
		]),
		transitions: JSON.stringify([
			{ id: 't1', fromStatus: 'open', toStatus: 'review', allowedRoles: ['handler'], guards: [{ type: 'requiredField', fieldName: 'summary' }] },
			{ id: 't2', fromStatus: 'open', toStatus: 'closed', allowedRoles: ['manager'] },
			{ id: 't3', fromStatus: 'open', toStatus: 'parked', guards: [{ type: 'roleGuard' }, { type: 'other' }] },
			{ id: 't4', fromStatus: 'review', toStatus: 'closed' },
		]),
	}

	it('hides transitions of other statuses and of roles the user lacks, and names what blocks the rest', () => {
		const result = store.computeAvailableTransitions({ status: 'open', summary: ' ' }, ['handler'], workflow, [], [])
		expect(result.map((r) => r.id)).toEqual(['t1', 't3'])
		expect(result[0]).toMatchObject({ available: false, unmetConditions: ['Required field missing: summary', 'Required step not completed: Intake'] })
		expect(result[1]).toMatchObject({ available: false, unmetConditions: ['Required step not completed: Intake'] })
	})

	it('makes a transition available once its guards and the required steps are met', () => {
		const tasks = [{ workflowStepId: 'st1', status: 'completed' }]
		const result = store.computeAvailableTransitions({ status: 'open', summary: 'Done' }, ['handler'], { ...workflow, steps: JSON.parse(workflow.steps), transitions: JSON.parse(workflow.transitions) }, tasks, [])
		expect(result.map((r) => [r.id, r.available, r.unmetConditions])).toEqual([['t1', true, []], ['t3', true, []]])
	})

	it('offers nothing without transitions', () => {
		expect(store.computeAvailableTransitions({ status: 'open' }, [], {}, [], [])).toEqual([])
		expect(store.computeAvailableTransitions({ status: 'open' }, [], null, [], [])).toEqual([])
		expect(store.computeAvailableTransitions({ status: 'x' }, [], { transitions: [{ fromStatus: 'x' }] }, [], [])).toEqual([{ fromStatus: 'x', available: true, unmetConditions: [] }])
	})
})

describe('guards', () => {
	it('blocks on the first task with unchecked checklist items, read from text or a list', () => {
		const tasks = [
			{ checklist: '[]' },
			{ checklist: null },
			{ checklist: JSON.stringify([{ label: 'A', checked: true }, { label: 'B' }, { label: 'C', checked: false }]) },
		]
		expect(store.evaluateChecklistGuard({}, tasks)).toEqual({ met: false, message: '2 checklist item(s) not completed: B, C' })
		expect(store.evaluateChecklistGuard({}, [{ checklist: [{ label: 'A', checked: true }] }])).toEqual({ met: true, message: '' })
		expect(store.evaluateGuards([{ type: 'checklist' }], {}, [], [], [], 'open')).toEqual([{ met: true, message: '' }])
	})

	it('requires a document by type or title', () => {
		const guard = { type: 'requiredDocument', documentTypeName: 'Permit' }
		expect(store.evaluateGuards([guard], {}, [], [{ title: 'Other' }], [], '')).toEqual([{ met: false, message: 'Required document missing: Permit' }])
		expect(store.evaluateRequiredDocumentGuard(guard, [{ documentType: 'Permit' }]).met).toBe(true)
		expect(store.evaluateRequiredDocumentGuard(guard, [{ title: 'Permit' }]).met).toBe(true)
	})

	it('requires a field that is set and not blank', () => {
		expect(store.evaluateRequiredFieldGuard({ fieldName: 'f' }, {}).met).toBe(false)
		expect(store.evaluateRequiredFieldGuard({ fieldName: 'f' }, { f: 0 }).met).toBe(false)
		expect(store.evaluateRequiredFieldGuard({ fieldName: 'f' }, { f: 'x' }).met).toBe(true)
	})

	it('counts a required step as done only when its task is completed', () => {
		const steps = [{ id: 's1', status: 'open', isRequired: true, title: 'One' }, { id: 's2', status: 'open', isRequired: true, title: 'Two' }, { id: 's3', status: 'done', isRequired: true }]
		expect(store.evaluateRequiredSteps(steps, 'open', [{ workflowStepId: 's1', status: 'completed' }, { workflowStepId: 's2', status: 'active' }]))
			.toEqual({ met: false, messages: ['Required step not completed: Two'] })
		expect(store.evaluateRequiredSteps(steps, 'done', [{ workflowStepId: 's3', status: 'completed' }])).toEqual({ met: true, messages: [] })
	})
})

describe('publishVersion', () => {
	it('refuses to publish a graph that fails validation, without calling the server', async () => {
		store.currentTemplate = { transitions: '[]' }
		const fetchSpy = vi.fn()
		vi.stubGlobal('fetch', fetchSpy)
		const answer = await store.publishVersion('w1', [{ id: 'a' }, { id: 'b' }])
		expect(answer).toBeNull()
		expect(store.error).toBe('Workflow validation failed')
		expect(JSON.stringify(store.validationErrors)).toContain('NO_FINAL_STATUS')
		expect(fetchSpy).not.toHaveBeenCalled()
	})

	it('publishes through the definitions endpoint and keeps the published definition', async () => {
		const fetchSpy = vi.fn(async () => ({ ok: true, json: async () => ({ success: true, definition: { id: 'w1', isActive: true } }) }))
		vi.stubGlobal('fetch', fetchSpy)
		expect(await store.publishVersion('w1')).toEqual({ id: 'w1', isActive: true })
		expect(fetchSpy.mock.calls[0][0]).toContain('/apps/dossiq/api/workflow-definitions/w1/publish')
		expect(fetchSpy.mock.calls[0][1].headers.requesttoken).toBe('tok')
		expect([store.currentTemplate, store.validationErrors, store.loading]).toEqual([{ id: 'w1', isActive: true }, [], false])
	})

	it('reports a refused publish, an unreadable answer and a network failure', async () => {
		vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, json: async () => ({ error: 'Not a draft' }) })))
		expect(await store.publishVersion('w1')).toBeNull()
		expect(store.error).toBe('Not a draft')
		vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => { throw new Error('html') } })))
		await store.publishVersion('w1')
		expect(store.error).toBe('Could not publish workflow definition')
		vi.stubGlobal('fetch', vi.fn(async () => { throw new Error('offline') }))
		await store.publishVersion('w1')
		expect([store.error, store.loading]).toEqual(['offline', false])
	})
})

describe('createDraftFromVersion', () => {
	it('copies the source into a new inactive draft one version above the highest', async () => {
		store.versions = [{ version: 2 }, { version: 5 }, {}]
		objectStore.fetchObject.mockResolvedValueOnce({ title: 'W', description: 'D', caseType: 'ct', steps: '[]', transitions: '[]', nodePositions: '{}' })
		objectStore.saveObject.mockImplementationOnce(async (type, data) => ({ id: 'new', ...data }))
		const draft = await store.createDraftFromVersion('w5')
		expect(objectStore.saveObject).toHaveBeenCalledWith('workflowTemplate', {
			title: 'W', description: 'D', caseType: 'ct', version: 6, isActive: false, isDraft: true,
			steps: '[]', transitions: '[]', nodePositions: '{}', parentWorkflow: null,
		})
		expect(store.currentTemplate).toEqual(draft)
	})

	it('says so when the source is gone', async () => {
		objectStore.fetchObject.mockResolvedValueOnce(null)
		expect(await store.createDraftFromVersion('gone')).toBeNull()
		expect(store.error).toBe('Source workflow template not found')
		expect(objectStore.saveObject).not.toHaveBeenCalled()
	})
})

describe('interpolateTemplate', () => {
	it('fills case and transition paths, leaving unknown ones as written', () => {
		const text = 'Case {{case.title}} by {{case.owner.name}} moved via {{transition.label}}; {{case.missing}} {{other.x}}'
		expect(store.interpolateTemplate(text, { title: 'Permit', owner: { name: 'Ann' } }, { label: 'Approve' }))
			.toBe('Case Permit by Ann moved via Approve; {{case.missing}} {{other.x}}')
		expect(store.interpolateTemplate('{{transition.label}}', {}, null)).toBe('{{transition.label}}')
	})
})

describe('export and import', () => {
	const statusTypes = [{ id: 's-open', name: 'Open' }, { id: 's-done', name: 'Done' }]
	const roleTypes = [{ id: 'r-h', name: 'Handler' }]
	const docTypes = [{ id: 'd-p', name: 'Permit' }]

	it('exports the template with type ids replaced by names', () => {
		const template = {
			title: 'W', description: 'D', version: 3, nodePositions: '{}',
			steps: JSON.stringify([{ id: 'a', status: 's-open', assigneeRole: 'r-h' }, { id: 'b', status: 'unknown' }]),
			transitions: [{ id: 't', fromStatus: 's-open', toStatus: 's-done', allowedRoles: ['r-h', 'r-x'] }, { id: 'u', fromStatus: 'x', toStatus: 'y' }],
		}
		expect(store.exportWorkflow(template, statusTypes, roleTypes, docTypes)).toEqual({
			_format: 'dossiq-workflow-v1', title: 'W', description: 'D', version: 3, nodePositions: '{}',
			steps: [{ id: 'a', status: 'Open', assigneeRole: 'Handler' }, { id: 'b', status: 'unknown', assigneeRole: null }],
			transitions: [
				{ id: 't', fromStatus: 'Open', toStatus: 'Done', allowedRoles: ['Handler', 'r-x'] },
				{ id: 'u', fromStatus: 'x', toStatus: 'y', allowedRoles: [] },
			],
			manifest: { statusTypes: ['Open', 'Done'], roleTypes: ['Handler'], documentTypes: ['Permit'] },
		})
		expect(store.exportWorkflow({ title: 'E' }, [], [], []).steps).toEqual([])
	})

	it('refuses an import that names types this case type does not have', async () => {
		const result = await store.importWorkflow({ manifest: { statusTypes: ['Open', 'Gone'], roleTypes: ['Boss'] } }, 'ct', statusTypes, roleTypes, docTypes)
		expect(result).toEqual({ success: false, missingTypes: { statusTypes: ['Gone'], roleTypes: ['Boss'] }, template: null })
		expect(objectStore.saveObject).not.toHaveBeenCalled()
	})

	it('imports as a new draft with fresh ids and type names mapped back to ids', async () => {
		store.versions = [{ version: 4 }]
		objectStore.saveObject.mockImplementationOnce(async (type, data) => ({ id: 'imp', ...data }))
		const result = await store.importWorkflow({
			manifest: { statusTypes: ['Open'], roleTypes: ['Handler'] },
			steps: [{ id: 'old', status: 'Open', assigneeRole: 'Handler' }, { status: 'Other' }],
			transitions: [{ id: 'old-t', fromStatus: 'Open', toStatus: 'Done', allowedRoles: ['Handler', 'x'] }, { fromStatus: 'a', toStatus: 'b' }],
		}, 'ct', statusTypes, roleTypes, docTypes)
		expect(result.success).toBe(true)
		const saved = objectStore.saveObject.mock.calls[0][1]
		expect(saved).toMatchObject({ title: 'Imported workflow', description: '', caseType: 'ct', version: 5, isActive: false, isDraft: true, nodePositions: '{}' })
		const steps = JSON.parse(saved.steps)
		const transitions = JSON.parse(saved.transitions)
		expect(steps.map((s) => [s.status, s.assigneeRole])).toEqual([['s-open', 'r-h'], ['Other', null]])
		expect(steps[0].id).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/)
		expect(steps[0].id).not.toBe('old')
		expect(transitions.map((tr) => [tr.fromStatus, tr.toStatus, tr.allowedRoles])).toEqual([['s-open', 's-done', ['r-h', 'x']], ['a', 'b', []]])
		expect(store.currentTemplate).toEqual(result.template)
	})
})

describe('editing the current template', () => {
	beforeEach(() => {
		store.currentTemplate = { steps: '[]', transitions: '[]', nodePositions: '{}' }
	})

	it('adds steps per status in order, and renumbers the siblings of a removed one', () => {
		const a = store.addStep('open', { title: 'A', isRequired: true })
		const b = store.addStep('open')
		const c = store.addStep('open', { title: 'C' })
		store.addStep('done', { title: 'D' })
		expect([a.order, b.order, c.order]).toEqual([1, 2, 3])
		expect(b).toMatchObject({ title: 'New step', description: '', assigneeRole: null, isRequired: false, checklist: [], automaticActions: [] })
		store.removeStep(b.id)
		expect(store.parsedSteps.filter((s) => s.status === 'open').map((s) => [s.title, s.order])).toEqual([['A', 1], ['C', 2]])
		store.removeStep('missing')
		expect(store.parsedSteps).toHaveLength(3)
	})

	it('updates a step and a transition by id, ignoring an unknown id', () => {
		const step = store.addStep('open', { title: 'A' })
		store.updateStep(step.id, { title: 'B' })
		store.updateStep('missing', { title: 'X' })
		expect(store.parsedSteps.map((s) => s.title)).toEqual(['B'])
		const tr = store.addTransition('open', 'done', { label: 'Go', allowedRoles: ['h'] })
		expect(tr).toMatchObject({ fromStatus: 'open', toStatus: 'done', label: 'Go', guards: [], automaticActions: [], allowedRoles: ['h'] })
		store.updateTransition(tr.id, { label: 'Finish' })
		store.updateTransition('missing', { label: 'X' })
		expect(store.parsedTransitions.map((x) => x.label)).toEqual(['Finish'])
		store.removeTransition(tr.id)
		expect(store.parsedTransitions).toEqual([])
		expect(store.addTransition('a', 'b').label).toBe('')
	})

	it('moves a node and removes a status with its steps, transitions and position', () => {
		store.addStep('open', { title: 'A' })
		store.addStep('done', { title: 'B' })
		store.addTransition('open', 'done')
		store.addTransition('done', 'archived')
		store.updateNodePosition('open', 10, 20)
		store.updateNodePosition('done', 30, 40)
		expect(store.parsedNodePositions).toEqual({ open: { x: 10, y: 20 }, done: { x: 30, y: 40 } })
		store.removeStatusNode('open')
		expect(store.parsedSteps.map((s) => s.title)).toEqual(['B'])
		expect(store.parsedTransitions.map((x) => [x.fromStatus, x.toStatus])).toEqual([['done', 'archived']])
		expect(store.parsedNodePositions).toEqual({ done: { x: 30, y: 40 } })
	})

	it('edits nothing stored without a current template', () => {
		store.currentTemplate = null
		expect(store.addStep('open').title).toBe('New step')
		store.removeStep('x')
		store.updateNodePosition('open', 1, 2)
		store.removeStatusNode('open')
		expect([store.parsedSteps, store.parsedNodePositions, store.currentTemplate]).toEqual([[], {}, null])
	})
})
