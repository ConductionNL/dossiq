// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The List view of a case type's flow (flow-page-list-beside-canvas): the
 * rows in order with their kinds, moving a step within its status, the store
 * writing that move onto the one template the canvas reads, the list
 * component's rows and events, and the editor showing the list and opening
 * the same step panel from it.
 *
 * The editor's canvas children are stubbed exactly as in
 * workflowEditorSmoke.spec.js, for the same reason: they pull NcActions and
 * with it a Nextcloud runtime this test does not need.
 *
 * @spec openspec/changes/flow-page-list-beside-canvas/specs/visual-workflow-editor/spec.md#requirement-a-case-types-flow-reads-as-a-list-beside-the-canvas
 * @spec openspec/changes/flow-page-list-beside-canvas/specs/visual-workflow-editor/spec.md#requirement-the-step-panel-follows-the-board-in-both-views
 */

import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('../../src/views/settings/components/WorkflowNode.vue', () => ({
	default: { name: 'WorkflowNode', props: ['status', 'steps', 'position', 'selected', 'otherStatuses', 'outgoingTransitions'], render: () => h('div') },
}))
vi.mock('../../src/views/settings/components/WorkflowTransitionArrow.vue', () => ({
	default: { name: 'WorkflowTransitionArrow', props: ['transition', 'fromPos', 'toPos', 'selected'], render: () => h('g') },
}))
vi.mock('../../src/views/settings/components/WorkflowPalette.vue', () => ({
	default: { name: 'WorkflowPalette', render: () => h('div', { class: 'workflow-palette-stub' }) },
}))
vi.mock('../../src/views/settings/components/StepConfigPanel.vue', () => ({
	default: {
		name: 'StepConfigPanel',
		props: ['step', 'roleTypes', 'readOnly'],
		render() {
			return h('div', { class: 'step-config-panel-stub' }, this.step.title)
		},
	},
}))
vi.mock('../../src/views/settings/components/TransitionConfigPanel.vue', () => ({
	default: { name: 'TransitionConfigPanel', props: ['transition', 'roleTypes', 'documentTypes'], render: () => h('div') },
}))

const { flowListRows, moveStepWithin, stepKind, DEFAULT_TRIGGER } = await import('../../src/utils/flowList.js')
const { default: WorkflowList } = await import('../../src/views/settings/components/WorkflowList.vue')
const { default: WorkflowEditor } = await import('../../src/views/settings/WorkflowEditor.vue')
const { default: StatusStepPanel } = await import('../../src/views/settings/components/StatusStepPanel.vue')
const { useWorkflowStore } = await import('../../src/store/modules/workflow.js')

const statuses = [
	{ id: 's-progress', name: 'In progress', order: 2 },
	{ id: 's-received', name: 'Received', order: 1 },
]
const steps = [
	{ id: 'st-confirm', title: 'Confirmation of receipt', status: 's-received', order: 2, kind: 'message' },
	{ id: 'st-check', title: 'Check completeness', status: 's-received', order: 1 },
]

describe('flowListRows', () => {
	it('lists the trigger first, then statuses with their steps in order, numbered, with kinds', () => {
		const list = flowListRows(statuses, steps)
		expect(list.trigger).toBe(DEFAULT_TRIGGER)
		expect(list.rows.map((r) => `${r.number} ${r.label} (${r.kind})`)).toEqual([
			'1 Received (status)',
			'2 Check completeness (task)',
			'3 Confirmation of receipt (message)',
			'4 In progress (status)',
		])
		expect(list.rows[1]).toMatchObject({ first: true, last: false, statusId: 's-received' })
		expect(list.rows[2]).toMatchObject({ first: false, last: true })
	})

	it('keeps a trigger the template names and survives empty input', () => {
		expect(flowListRows([], [], 'Application received').trigger).toBe('Application received')
		expect(flowListRows(null, null).rows).toEqual([])
	})

	it('reads a step without a known kind as a task', () => {
		expect(stepKind({})).toBe('task')
		expect(stepKind({ kind: 'Action' })).toBe('action')
		expect(stepKind({ kind: 'gateway' })).toBe('task')
	})
})

describe('moveStepWithin', () => {
	it('moves a step up within its status and renumbers that status only', () => {
		const other = { id: 'st-other', title: 'Other', status: 's-progress', order: 7 }
		const moved = moveStepWithin([...steps, other], 'st-confirm', 'up')
		expect(moved.find((s) => s.id === 'st-confirm').order).toBe(1)
		expect(moved.find((s) => s.id === 'st-check').order).toBe(2)
		expect(moved.find((s) => s.id === 'st-other').order).toBe(7)
		expect(steps.find((s) => s.id === 'st-confirm').order).toBe(2)
	})

	it('refuses a move past the edge, an unknown step or direction', () => {
		expect(moveStepWithin(steps, 'st-check', 'up')).toBeNull()
		expect(moveStepWithin(steps, 'st-confirm', 'down')).toBeNull()
		expect(moveStepWithin(steps, 'missing', 'up')).toBeNull()
		expect(moveStepWithin(steps, 'st-confirm', 'sideways')).toBeNull()
	})
})

describe('workflow store moveStep', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('writes the new order onto the one template the canvas reads', () => {
		const store = useWorkflowStore()
		store.currentTemplate = { id: 'tpl-1', isDraft: true, steps: JSON.stringify(steps) }
		expect(store.moveStep('st-confirm', 'up')).toBe(true)
		const ordered = [...store.parsedSteps].sort((a, b) => a.order - b.order).map((s) => s.id)
		expect(ordered).toEqual(['st-confirm', 'st-check'])
		expect(typeof store.currentTemplate.steps).toBe('string')
		expect(store.moveStep('st-confirm', 'up')).toBe(false)
	})
})

describe('WorkflowList', () => {
	it('renders the trigger and the rows with their pills', () => {
		const wrapper = mount(WorkflowList, { props: { statuses, steps } })
		expect(wrapper.find('[data-testid="workflow-list-trigger"]').text()).toBe('When: Case created')
		const rows = wrapper.findAll('li').map((li) => [
			li.find('.workflow-list__number').text(),
			li.find('.workflow-list__label').text(),
			li.find('.workflow-list__pill').attributes('label'),
		].join(' '))
		expect(rows).toEqual([
			'1 Received Status',
			'2 Check completeness Task',
			'3 Confirmation of receipt Message',
			'4 In progress Status',
		])
	})

	it('emits moves, edits and deletes, and disables what cannot move', async () => {
		const wrapper = mount(WorkflowList, { props: { statuses, steps } })
		const confirm = wrapper.find('[data-testid="workflow-list-row-st-confirm"]')
		expect(confirm.find('[data-testid="workflow-list-down"]').attributes('disabled')).toBeDefined()
		await confirm.find('[data-testid="workflow-list-up"]').trigger('click')
		await confirm.find('[data-testid="workflow-list-edit"]').trigger('click')
		await confirm.find('[data-testid="workflow-list-delete"]').trigger('click')
		await wrapper.find('[data-testid="workflow-list-row-s-received"] [data-testid="workflow-list-add-step"]').trigger('click')
		expect(wrapper.emitted('moveStep')).toEqual([['st-confirm', 'up']])
		expect(wrapper.emitted('editStep')).toEqual([['st-confirm']])
		expect(wrapper.emitted('deleteStep')).toEqual([['st-confirm']])
		expect(wrapper.emitted('addStep')).toEqual([['s-received']])
	})

	it('offers no move or delete on a published flow', () => {
		const wrapper = mount(WorkflowList, { props: { statuses, steps, readOnly: true } })
		const check = wrapper.find('[data-testid="workflow-list-row-st-check"]')
		expect(check.find('[data-testid="workflow-list-delete"]').attributes('disabled')).toBeDefined()
		expect(check.find('[data-testid="workflow-list-edit"]').attributes('disabled')).toBeUndefined()
	})
})

describe('WorkflowEditor in the List view', () => {
	/**
	 * @param {object} workflowStore Mock store.
	 * @return {object} The mounted editor in list view.
	 */
	function mountList(workflowStore) {
		const objectStore = {
			fetchCollection: vi.fn(() => Promise.resolve(statuses)),
			fetchObjects: vi.fn(() => Promise.resolve([])),
			fetchCollectionPage: vi.fn(() => Promise.resolve({ results: [] })),
		}
		return mount(
			{
				...WorkflowEditor,
				computed: {
					...WorkflowEditor.computed,
					workflowStore: () => workflowStore,
					objectStore: () => objectStore,
				},
			},
			{ props: { caseTypeId: 'ct-1', templateId: 'tpl-1', view: 'list' } },
		)
	}

	it('shows the list instead of the canvas and opens the same step panel from it', async () => {
		const store = {
			currentTemplate: { id: 'tpl-1', isDraft: true },
			parsedSteps: steps,
			parsedTransitions: [],
			parsedNodePositions: { 's-progress': { x: 0, y: 0 }, 's-received': { x: 0, y: 0 } },
			getTemplate: vi.fn(() => Promise.resolve(null)),
			moveStep: vi.fn(() => true),
			updateNodePosition: vi.fn(),
		}
		const wrapper = mountList(store)
		await flushPromises()
		expect(wrapper.find('[data-testid="workflow-list"]').exists()).toBe(true)
		expect(wrapper.find('.workflow-palette-stub').exists()).toBe(false)

		await wrapper.find('[data-testid="workflow-list-row-st-confirm"] [data-testid="workflow-list-up"]').trigger('click')
		expect(store.moveStep).toHaveBeenCalledWith('st-confirm', 'up')
		expect(wrapper.emitted('dirty')).toBeTruthy()

		await wrapper.find('[data-testid="workflow-list-row-st-check"] [data-testid="workflow-list-edit"]').trigger('click')
		expect(wrapper.find('.step-config-panel-stub').text()).toBe('Check completeness')
	})

	it('does not mark the flow changed when a move is refused', async () => {
		const store = {
			currentTemplate: { id: 'tpl-1', isDraft: true },
			parsedSteps: steps,
			parsedTransitions: [],
			parsedNodePositions: {},
			getTemplate: vi.fn(() => Promise.resolve(null)),
			moveStep: vi.fn(() => false),
			updateNodePosition: vi.fn(),
		}
		const wrapper = mountList(store)
		await flushPromises()
		wrapper.vm.onMoveStep('st-check', 'up')
		expect(wrapper.emitted('dirty')).toBeFalsy()
	})
})

describe('StatusStepPanel', () => {
	/**
	 * @param {object} props Extra props.
	 * @return {object} The mounted panel on the status In progress.
	 */
	function mountPanel(props = {}) {
		return mount(StatusStepPanel, {
			props: {
				status: { id: 's-progress', name: 'In progress', publicLabel: 'Being handled', phaseTermDays: 10 },
				steps: [{ id: 'st-a', title: 'Assess' }],
				statuses,
				nextStatus: 's-received',
				...props,
			},
		})
	}

	it('shows Step name, Name of the status, Steps in this status, Next status and Deadline, with Done and Delete step', () => {
		const w = mountPanel()
		expect(w.find('[data-testid="status-step-name"]').element.value).toBe('In progress')
		expect(w.find('[data-testid="status-step-public-label"]').element.value).toBe('Being handled')
		expect(w.find('[data-testid="status-step-steps"]').text()).toBe('Assess')
		const options = w.findAll('[data-testid="status-step-next"] option').map((o) => o.text())
		expect(options).toEqual(['None', 'Received'])
		expect(w.find('[data-testid="status-step-next"]').element.value).toBe('s-received')
		expect(w.find('[data-testid="status-step-deadline"]').element.value).toBe('10')
		expect(w.text()).toContain('Delete step')
		expect(w.text()).toContain('Done')
	})

	it('hands the changed fields to the editor on Done and closes', async () => {
		const w = mountPanel()
		await w.find('[data-testid="status-step-name"]').setValue('  Assessment  ')
		await w.find('[data-testid="status-step-next"]').setValue('')
		await w.find('[data-testid="status-step-deadline"]').setValue('5')
		w.vm.done()
		const [[change]] = w.emitted('update')
		expect(change.status).toMatchObject({ id: 's-progress', name: 'Assessment', publicLabel: 'Being handled', phaseTermDays: 5 })
		expect(change.nextStatus).toBeNull()
		expect(w.emitted('close')).toHaveLength(1)
	})

	it('keeps the old name when the field is emptied and drops a negative deadline', () => {
		const w = mountPanel()
		w.vm.local.name = '   '
		w.vm.local.phaseTermDays = -3
		w.vm.done()
		const [[change]] = w.emitted('update')
		expect(change.status.name).toBe('In progress')
		expect(change.status.phaseTermDays).toBeNull()
		expect(change.nextStatus).toBe('s-received')
	})

	it('only closes on a published flow and offers no Delete step there', () => {
		const w = mountPanel({ readOnly: true })
		expect(w.find('[data-testid="status-step-delete"]').exists()).toBe(false)
		w.vm.done()
		expect(w.emitted('update')).toBeFalsy()
		expect(w.emitted('close')).toHaveLength(1)
	})

	it('emits delete with the status id and re-reads a new status', async () => {
		const w = mountPanel()
		w.vm.$emit('delete', 's-progress')
		expect(w.emitted('delete')).toEqual([['s-progress']])
		await w.setProps({ status: { id: 's-received', name: 'Received' }, nextStatus: '' })
		expect(w.vm.local).toEqual({ name: 'Received', publicLabel: '', nextStatus: '', phaseTermDays: null })
	})
})

describe('WorkflowEditor status step', () => {
	/**
	 * @param {Array<object>} transitions The template's transitions.
	 * @param {object|null} saved What saving the status answers.
	 * @return {Promise<{wrapper: object, store: object, objectStore: object}>} The editor in list view.
	 */
	async function mountWithStatusPanel(transitions, saved) {
		const store = {
			currentTemplate: { id: 'tpl-1', isDraft: true },
			parsedSteps: steps,
			parsedTransitions: transitions,
			parsedNodePositions: {},
			getTemplate: vi.fn(() => Promise.resolve(null)),
			updateNodePosition: vi.fn(),
			addTransition: vi.fn(),
			removeTransition: vi.fn(),
			updateTransition: vi.fn(),
		}
		const objectStore = {
			fetchCollection: vi.fn(() => Promise.resolve(statuses.map((s) => ({ ...s })))),
			saveObject: vi.fn(() => Promise.resolve(saved)),
		}
		const wrapper = mount(
			{
				...WorkflowEditor,
				computed: {
					...WorkflowEditor.computed,
					workflowStore: () => store,
					objectStore: () => objectStore,
				},
			},
			{ props: { caseTypeId: 'ct-1', templateId: 'tpl-1', view: 'list' } },
		)
		await flushPromises()
		return { wrapper, store, objectStore }
	}

	it('opens the status step panel from the list and reads its next status from the transitions', async () => {
		const { wrapper } = await mountWithStatusPanel([{ id: 'tr-1', fromStatus: 's-received', toStatus: 's-progress' }], null)
		await wrapper.find('[data-testid="workflow-list-row-s-received"] [data-testid="workflow-list-edit-status"]').trigger('click')
		const panel = wrapper.findComponent(StatusStepPanel)
		expect(panel.exists()).toBe(true)
		expect(panel.props('nextStatus')).toBe('s-progress')
		expect(panel.props('steps').map((s) => s.id)).toEqual(['st-check', 'st-confirm'])
	})

	it('saves the status and retargets its outgoing transition', async () => {
		const saved = { id: 's-received', name: 'Intake', order: 1 }
		const { wrapper, store, objectStore } = await mountWithStatusPanel([{ id: 'tr-1', fromStatus: 's-received', toStatus: 's-progress' }], saved)
		await wrapper.vm.onStatusStepUpdate({ status: { id: 's-received', name: 'Intake' }, nextStatus: 's-other' })
		expect(objectStore.saveObject).toHaveBeenCalledWith('statusType', { id: 's-received', name: 'Intake' })
		expect(store.updateTransition).toHaveBeenCalledWith('tr-1', { toStatus: 's-other' })
		expect(wrapper.vm.statusNodes.find((s) => s.id === 's-received').name).toBe('Intake')
		expect(wrapper.emitted('dirty')).toBeTruthy()
	})

	it('adds a transition when there was none and removes it when cleared', async () => {
		const added = await mountWithStatusPanel([], { id: 's-progress', name: 'In progress' })
		await added.wrapper.vm.onStatusStepUpdate({ status: { id: 's-progress' }, nextStatus: 's-received' })
		expect(added.store.addTransition).toHaveBeenCalledWith('s-progress', 's-received')
		const removed = await mountWithStatusPanel([{ id: 'tr-9', fromStatus: 's-progress', toStatus: 's-received' }], { id: 's-progress' })
		await removed.wrapper.vm.onStatusStepUpdate({ status: { id: 's-progress' }, nextStatus: null })
		expect(removed.store.removeTransition).toHaveBeenCalledWith('tr-9')
		const same = await mountWithStatusPanel([{ id: 'tr-9', fromStatus: 's-progress', toStatus: 's-received' }], { id: 's-progress' })
		await same.wrapper.vm.onStatusStepUpdate({ status: { id: 's-progress' }, nextStatus: 's-received' })
		expect(same.store.updateTransition).not.toHaveBeenCalled()
	})

	it('says so when the status cannot be saved and changes nothing else', async () => {
		const { wrapper, store } = await mountWithStatusPanel([], null)
		await wrapper.vm.onStatusStepUpdate({ status: { id: 's-progress' }, nextStatus: 's-received' })
		expect(wrapper.vm.validationErrors).toEqual([{ type: 'error', message: 'Failed to save status' }])
		expect(store.addTransition).not.toHaveBeenCalled()
		expect(wrapper.emitted('dirty')).toBeFalsy()
	})
})
