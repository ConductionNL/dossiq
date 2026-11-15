// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The workflow editor's step and transition panels and its status node, and the
 * StUF audit log and endpoint list, mounted with their API doubled: what each
 * edit emits, how drag and drop reorders, and how the StUF screens load, filter,
 * export and test.
 *
 * @spec openspec/specs/workflow-definition-model/spec.md
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const stub = (name) => ({ name, render: () => null })
vi.mock('@nextcloud/vue', () => ({
	NcActionButton: stub('NcActionButton'),
	NcActions: stub('NcActions'),
	NcButton: stub('NcButton'),
	NcEmptyContent: stub('NcEmptyContent'),
	NcLoadingIcon: stub('NcLoadingIcon'),
	NcSelect: stub('NcSelect'),
	NcTextField: stub('NcTextField'),
}))
const toasts = []
vi.mock('@nextcloud/dialogs', () => ({ showError: (m) => toasts.push(m) }))
vi.mock('../../src/dialogs/StufEnvelopeDialog.vue', () => ({ default: stub('StufEnvelopeDialog') }))
vi.mock('../../src/services/stufApi.js', () => ({ listMessages: vi.fn(), listEndpoints: vi.fn() }))
vi.mock('../../src/services/connectionTestApi.js', () => ({ testStufEndpoint: vi.fn() }))

const stuf = await import('../../src/services/stufApi.js')
const connection = await import('../../src/services/connectionTestApi.js')
const { default: StepConfigPanel } = await import('../../src/views/settings/components/StepConfigPanel.vue')
const { default: TransitionConfigPanel } = await import('../../src/views/settings/components/TransitionConfigPanel.vue')
const { default: WorkflowNode } = await import('../../src/views/settings/components/WorkflowNode.vue')
const { default: StufAuditLog } = await import('../../src/views/settings/StufAuditLog.vue')
const { default: StufEndpoints } = await import('../../src/views/settings/StufEndpoints.vue')

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

/**
 * The last payload a wrapper emitted under `update`.
 *
 * @param {object} w The wrapper.
 * @return {object} The payload.
 */
const lastUpdate = (w) => w.emitted('update').at(-1)[0]

beforeEach(() => {
	vi.clearAllMocks()
	toasts.length = 0
})

afterEach(() => {
	vi.useRealTimers()
	vi.restoreAllMocks()
})

describe('StepConfigPanel', () => {
	const step = { id: 's1', title: 'Intake', checklist: '[{"id":"c1","label":"A"},{"id":"c2","label":"B"},{"id":"c3","label":"C"}]', automaticActions: [] }

	it('emits the step without config when nothing is configured', async () => {
		const w = await mountIt(StepConfigPanel, { step: { ...step, config: '{bad' } })
		expect(w.vm.escalationEnabled).toBe(false)
		w.vm.addAction()
		const payload = lastUpdate(w)
		expect(payload).not.toHaveProperty('config')
		expect(payload.automaticActions).toEqual([{ type: 'createTask', title: '' }])
		expect(payload.checklist.map((c) => c.id)).toEqual(['c1', 'c2', 'c3'])
	})

	it('emits only the configured parts of the step config', async () => {
		const config = { sla: { value: 5, unit: 'days' }, requiredFields: ['summary', ' '], autoActions: [{ type: 'x' }], escalationRule: { trigger: 'breach', offset: 2, openIncident: 1 } }
		const w = await mountIt(StepConfigPanel, { step: { ...step, config } })
		expect(w.vm.escalationEnabled).toBe(true)
		w.vm.emitUpdate()
		expect(lastUpdate(w).config).toEqual({
			sla: { value: 5, unit: 'days' },
			requiredFields: ['summary'],
			autoActions: [{ type: 'x' }],
			escalationRule: { trigger: 'breach', offset: 2, offsetUnit: 'businessDays', notifyRole: '', escalateToRole: '', openIncident: true },
		})
		w.vm.escalationEnabled = false
		w.vm.onEscalationToggle()
		expect(lastUpdate(w).config).not.toHaveProperty('escalationRule')
	})

	it('adds and removes required fields and checklist items', async () => {
		const w = await mountIt(StepConfigPanel, { step })
		w.vm.addRequiredField()
		w.vm.localConfig.requiredFields[0] = 'title'
		w.vm.emitUpdate()
		expect(lastUpdate(w).config).toEqual({ requiredFields: ['title'] })
		w.vm.removeRequiredField(0)
		expect(lastUpdate(w)).not.toHaveProperty('config')
		w.vm.addChecklistItem()
		const added = lastUpdate(w).checklist.at(-1)
		expect(added).toMatchObject({ label: '', description: '' })
		expect(added.id).toMatch(/^check-\d+$/)
		w.vm.removeChecklistItem(0)
		w.vm.removeAction(0)
		expect(lastUpdate(w).checklist.map((c) => c.id)).toEqual(['c2', 'c3', added.id])
	})

	it('moves a checklist item by drag and drop, ignoring a drop on itself', async () => {
		const w = await mountIt(StepConfigPanel, { step })
		const event = { dataTransfer: {} }
		w.vm.onCheckDragStart(0, event)
		expect(event.dataTransfer.effectAllowed).toBe('move')
		w.vm.onCheckDrop(2, {})
		expect(lastUpdate(w).checklist.map((c) => c.id)).toEqual(['c2', 'c3', 'c1'])
		w.vm.onCheckDragStart(1, event)
		w.vm.onCheckDrop(1, {})
		expect(w.emitted('update')).toHaveLength(1)
		expect(w.vm.dragCheckIndex).toBeNull()
	})

	it('reloads its local copy when the step changes', async () => {
		const w = await mountIt(StepConfigPanel, { step })
		await w.setProps({ step: { id: 's2', checklist: [], config: { escalationRule: {} } } })
		expect([w.vm.localStep.id, w.vm.localChecklist, w.vm.escalationEnabled]).toEqual(['s2', [], true])
	})
})

describe('TransitionConfigPanel', () => {
	const transition = { id: 't1', label: 'Approve', allowedRoles: ['r1'], guards: '[{"type":"requiredField"}]', automaticActions: null }

	it('toggles roles and edits guards and actions, emitting the whole transition', async () => {
		const w = await mountIt(TransitionConfigPanel, { transition })
		w.vm.toggleRole('r2')
		expect(lastUpdate(w).allowedRoles).toEqual(['r1', 'r2'])
		w.vm.toggleRole('r1')
		expect(lastUpdate(w).allowedRoles).toEqual(['r2'])
		w.vm.addGuard()
		expect(lastUpdate(w).guards).toEqual([{ type: 'requiredField' }, { type: 'checklist' }])
		w.vm.removeGuard(0)
		w.vm.addAction()
		expect(lastUpdate(w)).toMatchObject({ id: 't1', label: 'Approve', guards: [{ type: 'checklist' }], automaticActions: [{ type: 'notify', message: '' }] })
		w.vm.removeAction(0)
		expect(lastUpdate(w).automaticActions).toEqual([])
		expect(transition.allowedRoles).toEqual(['r1'])
	})

	it('deletes only after confirmation, and follows a changed transition', async () => {
		const w = await mountIt(TransitionConfigPanel, { transition })
		vi.stubGlobal('confirm', vi.fn(() => false))
		w.vm.onDelete()
		expect(w.emitted('delete')).toBeUndefined()
		vi.stubGlobal('confirm', vi.fn(() => true))
		w.vm.onDelete()
		expect(w.emitted('delete')).toEqual([['t1']])
		await w.setProps({ transition: { id: 't2', guards: [{ type: 'roleGuard' }] } })
		expect([w.vm.localTransition.id, w.vm.localAllowedRoles, w.vm.localGuards]).toEqual(['t2', [], [{ type: 'roleGuard' }]])
		vi.unstubAllGlobals()
	})
})

describe('WorkflowNode', () => {
	const props = {
		status: { id: 'open', name: 'Open' },
		position: { x: 10, y: 20 },
		otherStatuses: [{ id: 'review', name: 'Review' }, { id: 'done', name: 'Done' }],
		outgoingTransitions: [{ toStatus: 'review' }],
	}

	it('places itself and offers only statuses it is not yet connected to', async () => {
		const w = await mountIt(WorkflowNode, props)
		expect(w.vm.nodeStyle).toEqual({ position: 'absolute', left: '10px', top: '20px' })
		expect(w.vm.connectableStatuses).toEqual([{ id: 'done', name: 'Done' }])
		expect([w.vm.targetName('review'), w.vm.targetName('gone')]).toEqual(['Review', 'gone'])
	})

	it('emits drag, connection and step reorder events', async () => {
		const w = await mountIt(WorkflowNode, props)
		w.vm.onMouseDown({ offsetX: 3, offsetY: 4 })
		w.vm.onConnectionStartFromPort('port-event')
		const data = {}
		const dt = { setData: (k, v) => { data[k] = v }, getData: (k) => data[k] }
		w.vm.onStepDragStart({ id: 'a' }, { dataTransfer: dt })
		expect([w.vm.draggedStepId, dt.effectAllowed]).toEqual(['a', 'move'])
		w.vm.onStepDrop({ id: 'a' }, { dataTransfer: dt })
		w.vm.onStepDrop({ id: 'b' }, { dataTransfer: dt })
		expect(w.emitted('drag-start')).toEqual([[{ offsetX: 3, offsetY: 4 }]])
		expect(w.emitted('connection-start')).toEqual([['port-event']])
		expect(w.emitted('step-reorder')).toEqual([[{ draggedId: 'a', targetId: 'b' }]])
		expect(w.vm.draggedStepId).toBeNull()
	})
})

describe('StufAuditLog', () => {
	beforeEach(() => {
		stuf.listMessages.mockResolvedValue({ items: [{ sentOn: '2026-01-01', status: 'fout', referenceNumber: 'R"1' }] })
	})

	it('loads the latest messages with the filters, reloading on each filter', async () => {
		const w = await mountIt(StufAuditLog)
		expect(stuf.listMessages).toHaveBeenCalledWith({ endpointId: '', messageKind: '', status: '', limit: 100 })
		expect(w.vm.messages).toHaveLength(1)
		expect(w.vm.berichtSoortOptions).toContain('Lk01')
		expect(w.vm.statusOptions).toContain('wacht_op_retry')
		w.vm.filters.status = 'fout'
		await flushPromises()
		expect(stuf.listMessages).toHaveBeenLastCalledWith(expect.objectContaining({ status: 'fout' }))
	})

	it('waits for typing to stop before filtering by endpoint', async () => {
		const w = await mountIt(StufAuditLog)
		vi.useFakeTimers()
		stuf.listMessages.mockClear()
		w.vm.filters.endpointId = 'e'
		await w.vm.$nextTick()
		w.vm.filters.endpointId = 'e1'
		await w.vm.$nextTick()
		vi.advanceTimersByTime(399)
		expect(stuf.listMessages).not.toHaveBeenCalled()
		vi.advanceTimersByTime(1)
		expect(stuf.listMessages).toHaveBeenCalledTimes(1)
		expect(stuf.listMessages.mock.calls[0][0].endpointId).toBe('e1')
		w.unmount()
	})

	it('shows a failed load, keeps no rows for a malformed answer, and classes statuses', async () => {
		stuf.listMessages.mockRejectedValueOnce(new Error('x'))
		const w = await mountIt(StufAuditLog)
		expect([w.vm.loadError, toasts]).toEqual(['Failed to load StUF audit log', ['Failed to load StUF audit log']])
		stuf.listMessages.mockResolvedValueOnce({})
		await w.vm.reload()
		expect([w.vm.messages, w.vm.loadError]).toEqual([[], ''])
		expect([w.vm.statusClass('fout'), w.vm.statusClass('')]).toEqual(['stuf-audit-log__status--fout', 'stuf-audit-log__status--unknown'])
		w.vm.inspect({ id: 'm' })
		expect(w.vm.inspectRow).toEqual({ id: 'm' })
	})

	it('exports the shown messages as CSV with quoted cells', async () => {
		let csv = ''
		const OriginalBlob = globalThis.Blob
		vi.stubGlobal('Blob', class extends OriginalBlob {
			constructor(parts, options) {
				super(parts, options)
				csv = parts.join('')
			}
		})
		URL.createObjectURL = vi.fn(() => 'blob:x')
		URL.revokeObjectURL = vi.fn()
		vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
		const w = await mountIt(StufAuditLog)
		w.vm.exportCsv()
		const [header, row] = csv.split('\n')
		expect(header).toBe('sentOn,direction,messageKind,role,status,httpStatus,durationMs,referenceNumber,caseIdentification')
		expect(row).toBe('"2026-01-01","","","","fout","","","R\\"1",""')
		vi.unstubAllGlobals()
	})
})

describe('StufEndpoints', () => {
	it('loads the endpoints and labels their health', async () => {
		stuf.listEndpoints.mockResolvedValueOnce({ items: [{ id: 'e1' }] })
		const w = await mountIt(StufEndpoints)
		expect(w.vm.endpoints).toEqual([{ id: 'e1' }])
		const cases = [
			[{}, 'stuf-endpoints__health--ok', 'OK'],
			[{ health: { state: 'unlinked' } }, 'stuf-endpoints__health--degraded', 'No integriq source'],
			[{ health: { state: 'integriq' } }, 'stuf-endpoints__health--ok', 'Sent through integriq'],
			[{ health: { state: 'circuit_open' } }, 'stuf-endpoints__health--circuit_open', 'Circuit open'],
			[{ health: { state: 'degraded' } }, 'stuf-endpoints__health--degraded', 'Degraded'],
		]
		for (const [row, cls, label] of cases) {
			expect([w.vm.healthClass(row), w.vm.healthLabel(row)]).toEqual([cls, label])
		}
	})

	it('tests an endpoint and keeps a failed answer as a failed result', async () => {
		stuf.listEndpoints.mockResolvedValueOnce({})
		const w = await mountIt(StufEndpoints)
		expect(w.vm.endpoints).toEqual([])
		expect(w.vm.resultFor({ id: 'e1' }).state).toBe('not_tested')
		connection.testStufEndpoint.mockResolvedValueOnce({ state: 'reachable', measuredAt: '2026-01-01T00:00:00Z' })
		await w.vm.test({ id: 'e1' })
		expect(connection.testStufEndpoint).toHaveBeenCalledWith('e1')
		expect(w.vm.results.e1.state).toBe('reachable')
		connection.testStufEndpoint.mockRejectedValueOnce({ response: { data: { error: 'TLS failed' } } })
		await w.vm.test({ id: 'e2' })
		expect(w.vm.results.e2).toMatchObject({ state: 'failed', reason: 'TLS failed' })
		connection.testStufEndpoint.mockRejectedValueOnce({})
		await w.vm.test({ id: 'e3' })
		expect([w.vm.results.e3.reason, w.vm.testing]).toEqual(['The endpoint did not answer', ''])
	})

	it('says when the endpoints cannot be loaded', async () => {
		stuf.listEndpoints.mockRejectedValueOnce(new Error('x'))
		const w = await mountIt(StufEndpoints)
		expect(w.vm.loadError).toBe('Failed to load StUF endpoints')
		expect(toasts).toEqual(['Failed to load StUF endpoints'])
	})
})
