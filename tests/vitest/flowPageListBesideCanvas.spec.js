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
			li.find('.workflow-list__pill').text(),
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
