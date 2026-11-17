// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The visual workflow editor operated through its canvas and child
 * components: loading, selection, dragging and panning, zoom, drawing
 * connections, palette and keyboard additions, and the step and transition
 * panels, each checked against what reaches the stores and what is emitted.
 *
 * @spec openspec/specs/workflow-definition-model/spec.md
 * @spec openspec/specs/visual-workflow-editor/spec.md#requirement-keyboard-operable-canvas
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

/**
 * A child stub that renders a marker element and takes the given props.
 *
 * @param {string} name The component name.
 * @param {Array<string>} props Its props.
 * @param {string} [tag] The element to render.
 * @return {object} The stub.
 */
function childStub(name, props, tag = 'div') {
	return { name, props, render: () => h(tag, { class: `${name}-stub` }) }
}

vi.mock('../../src/views/settings/components/WorkflowNode.vue', () => ({
	default: childStub('WorkflowNode', ['status', 'steps', 'position', 'selected', 'otherStatuses', 'outgoingTransitions']),
}))
vi.mock('../../src/views/settings/components/WorkflowTransitionArrow.vue', () => ({
	default: childStub('WorkflowTransitionArrow', ['transition', 'fromPos', 'toPos', 'selected'], 'g'),
}))
vi.mock('../../src/views/settings/components/WorkflowPalette.vue', () => ({
	default: childStub('WorkflowPalette', []),
}))
vi.mock('../../src/views/settings/components/WorkflowValidationBanner.vue', () => ({
	default: childStub('WorkflowValidationBanner', ['errors']),
}))
vi.mock('../../src/views/settings/components/StepConfigPanel.vue', () => ({
	default: childStub('StepConfigPanel', ['step', 'roleTypes', 'readOnly']),
}))
vi.mock('../../src/views/settings/components/TransitionConfigPanel.vue', () => ({
	default: childStub('TransitionConfigPanel', ['transition', 'roleTypes', 'documentTypes']),
}))

const { default: WorkflowEditor } = await import('../../src/views/settings/WorkflowEditor.vue')

const STATUSES = [
	{ id: 'a', name: 'Intake', isFinal: false },
	{ id: 'b', name: 'Review', isFinal: false },
	{ id: 'c', name: 'Closed', isFinal: true },
]
const TRANSITIONS = [
	{ id: 't1', fromStatus: 'a', toStatus: 'b' },
	{ id: 't2', fromStatus: 'b', toStatus: 'c' },
]

/**
 * A workflow store double holding what the editor reads and recording what it calls.
 *
 * @param {object} [overrides] Fields to replace.
 * @return {object} The store.
 */
function workflowStore(overrides = {}) {
	return {
		currentTemplate: { id: 'tpl-1', isDraft: true },
		parsedSteps: [
			{ id: 's2', status: 'a', order: 2, title: 'Second' },
			{ id: 's1', status: 'a', order: 1, title: 'First' },
			{ id: 's3', status: 'b', order: 1, title: 'Other' },
		],
		parsedTransitions: TRANSITIONS,
		parsedNodePositions: { a: { x: 10, y: 20 } },
		getTemplate: vi.fn(() => Promise.resolve(null)),
		updateNodePosition: vi.fn(),
		addTransition: vi.fn(),
		removeTransition: vi.fn(),
		updateTransition: vi.fn(),
		removeStatusNode: vi.fn(),
		addStep: vi.fn((statusId) => ({ id: 'new-step', status: statusId })),
		updateStep: vi.fn(),
		removeStep: vi.fn(),
		validateWorkflow: vi.fn(() => []),
		...overrides,
	}
}

/**
 * An object store double answering each collection from `collections`.
 *
 * @param {object} [collections] Schema => list.
 * @param {object} [overrides] Fields to replace.
 * @return {object} The store.
 */
function objectStore(collections = {}, overrides = {}) {
	const lists = { statusType: STATUSES, roleType: [{ id: 'r1' }], documentType: [{ id: 'd1' }], ...collections }
	return {
		fetchCollection: vi.fn((schema) => Promise.resolve(lists[schema] === undefined ? null : lists[schema].map((x) => ({ ...x })))),
		saveObject: vi.fn((schema, data) => Promise.resolve({ id: 'new-status', ...data })),
		deleteObject: vi.fn(() => Promise.resolve(true)),
		...overrides,
	}
}

/**
 * Mount the editor over the two store doubles and let it load.
 *
 * @param {object} wf The workflow store.
 * @param {object} os The object store.
 * @param {object} [props] Extra props.
 * @return {Promise<object>} The wrapper.
 */
async function mountEditor(wf, os, props = {}) {
	const w = mount(
		{
			...WorkflowEditor,
			computed: { ...WorkflowEditor.computed, workflowStore: () => wf, objectStore: () => os },
		},
		{ props: { caseTypeId: 'ct-1', ...props } },
	)
	await flushPromises()
	return w
}

/**
 * The node stub rendered for a status.
 *
 * @param {object} w The wrapper.
 * @param {string} id The status id.
 * @return {object} The component wrapper.
 */
const node = (w, id) => w.findAllComponents({ name: 'WorkflowNode' }).find((n) => n.props('status').id === id)

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('WorkflowEditor loading', () => {
	it('loads the case type parts, fetches the template and places every unplaced node on a grid', async () => {
		const wf = workflowStore()
		const os = objectStore()
		const w = await mountEditor(wf, os, { templateId: 'tpl-1' })

		expect(os.fetchCollection.mock.calls).toEqual([
			['statusType', { caseType: 'ct-1', _limit: 100, _order: { order: 'asc' } }],
			['roleType', { caseType: 'ct-1', _limit: 100 }],
			['documentType', { caseType: 'ct-1', _limit: 100 }],
		])
		expect(wf.getTemplate).toHaveBeenCalledWith('tpl-1')
		expect(JSON.parse(wf.currentTemplate.nodePositions)).toEqual({
			a: { x: 10, y: 20 },
			b: { x: 350, y: 100 },
			c: { x: 600, y: 100 },
		})
		expect(w.findAllComponents({ name: 'WorkflowNode' })).toHaveLength(3)
		expect(node(w, 'a').props('steps').map((s) => s.id)).toEqual(['s1', 's2'])
		expect(node(w, 'a').props('otherStatuses').map((s) => s.id)).toEqual(['b', 'c'])
		expect(node(w, 'b').props('outgoingTransitions')).toEqual([TRANSITIONS[1]])
		expect(node(w, 'b').props('position')).toEqual({ x: 100, y: 100 })
	})

	it('treats unanswered collections as empty and fetches no template without an id', async () => {
		const wf = workflowStore({ parsedTransitions: [] })
		const os = objectStore({ statusType: undefined, roleType: undefined, documentType: undefined })
		const w = await mountEditor(wf, os)

		expect(w.vm.statusNodes).toEqual([])
		expect(w.vm.roleTypes).toEqual([])
		expect(w.vm.documentTypes).toEqual([])
		expect(wf.getTemplate).not.toHaveBeenCalled()
		expect(wf.currentTemplate).not.toHaveProperty('nodePositions')
	})

	it('centres arrows on their nodes and falls back to the origin for an unknown node', async () => {
		const wf = workflowStore({ parsedNodePositions: { a: { x: 10, y: 20 }, b: { x: 300, y: 100 } } })
		const w = await mountEditor(wf, objectStore())
		const arrow = w.findAllComponents({ name: 'WorkflowTransitionArrow' })[0]
		expect(arrow.props('fromPos')).toEqual({ x: 110, y: 60 })
		expect(arrow.props('toPos')).toEqual({ x: 400, y: 140 })
		expect(w.vm.getNodeCenter('missing')).toEqual({ x: 0, y: 0 })
	})
})

describe('WorkflowEditor selection', () => {
	it('selects one node, transition or step at a time', async () => {
		const w = await mountEditor(workflowStore(), objectStore())

		node(w, 'a').vm.$emit('select')
		await flushPromises()
		expect(node(w, 'a').props('selected')).toBe(true)

		const arrows = w.findAllComponents({ name: 'WorkflowTransitionArrow' })
		arrows[1].vm.$emit('click')
		await flushPromises()
		expect([w.vm.selectedNode, w.vm.selectedTransition]).toEqual([null, 't2'])
		expect(arrows[1].props('selected')).toBe(true)
		expect(w.findComponent({ name: 'TransitionConfigPanel' }).props('transition')).toEqual(TRANSITIONS[1])

		arrows[0].vm.$emit('dblclick')
		await flushPromises()
		expect(w.findComponent({ name: 'TransitionConfigPanel' }).props('transition')).toEqual(TRANSITIONS[0])

		node(w, 'b').vm.$emit('stepClick', { id: 's3', title: 'Other' })
		await flushPromises()
		expect([w.vm.selectedNode, w.vm.selectedTransition]).toEqual([null, null])
		expect(w.findComponent({ name: 'TransitionConfigPanel' }).exists()).toBe(false)
		expect(w.findComponent({ name: 'StepConfigPanel' }).props()).toMatchObject({ step: { id: 's3', title: 'Other' }, roleTypes: [{ id: 'r1' }], readOnly: false })
	})

	it('shows no transition panel for a selected transition that no longer exists', async () => {
		const w = await mountEditor(workflowStore(), objectStore())
		w.vm.selectTransition('gone')
		await flushPromises()
		expect(w.vm.selectedTransitionData).toBeNull()
		expect(w.findComponent({ name: 'TransitionConfigPanel' }).exists()).toBe(false)
	})

	it('closes the panels from their close events', async () => {
		const w = await mountEditor(workflowStore(), objectStore())
		w.vm.selectTransition('t1')
		await flushPromises()
		w.findComponent({ name: 'TransitionConfigPanel' }).vm.$emit('close')
		await flushPromises()
		expect(w.vm.selectedTransition).toBeNull()

		w.vm.onStepClick({ id: 's1' })
		await flushPromises()
		w.findComponent({ name: 'StepConfigPanel' }).vm.$emit('close')
		await flushPromises()
		expect(w.findComponent({ name: 'StepConfigPanel' }).exists()).toBe(false)
	})

	it('passes read-only to the step panel for a published template, and not without a template', async () => {
		const published = await mountEditor(workflowStore({ currentTemplate: { id: 'tpl', isDraft: false } }), objectStore())
		expect(published.vm.isPublished).toBe(true)
		const none = await mountEditor(workflowStore({ currentTemplate: null }), objectStore())
		expect(none.vm.isPublished).toBe(false)
	})

	it('clears the validation errors when the banner is dismissed', async () => {
		const w = await mountEditor(workflowStore(), objectStore())
		w.vm.showValidationError('Broken')
		await flushPromises()
		expect(w.findComponent({ name: 'WorkflowValidationBanner' }).props('errors')).toEqual([{ type: 'error', message: 'Broken' }])
		w.findComponent({ name: 'WorkflowValidationBanner' }).vm.$emit('dismiss')
		await flushPromises()
		expect(w.vm.validationErrors).toEqual([])
	})
})

describe('WorkflowEditor canvas', () => {
	it('moves a dragged node through the store, scaled by zoom and kept on the canvas, and is dirty once dropped', async () => {
		const wf = workflowStore()
		const w = await mountEditor(wf, objectStore())
		const canvas = w.find('.workflow-editor__canvas')

		node(w, 'b').vm.$emit('dragStart', { offsetX: 5, offsetY: 7 })
		w.vm.panOffset = { x: 20, y: 10 }
		w.vm.zoom = 2
		await canvas.trigger('mousemove', { clientX: 120, clientY: 50 })
		expect(wf.updateNodePosition).toHaveBeenLastCalledWith('b', 45, 13)
		await canvas.trigger('mousemove', { clientX: 0, clientY: 0 })
		expect(wf.updateNodePosition).toHaveBeenLastCalledWith('b', 0, 0)

		await canvas.trigger('mouseup')
		expect(w.emitted('dirty')).toHaveLength(1)
		expect(w.vm.draggingNode).toBeNull()
		await canvas.trigger('mouseup')
		expect(w.emitted('dirty')).toHaveLength(1)
	})

	it('drags a node from its corner when the event carries no offset', async () => {
		const wf = workflowStore()
		const w = await mountEditor(wf, objectStore())
		node(w, 'a').vm.$emit('dragStart', {})
		await w.find('.workflow-editor__canvas').trigger('mousemove', { clientX: 30, clientY: 40 })
		expect(wf.updateNodePosition).toHaveBeenCalledWith('a', 30, 40)
	})

	it('draws a connection from one node to another, adding the transition', async () => {
		const wf = workflowStore({ parsedNodePositions: { a: { x: 10, y: 20 }, b: { x: 300, y: 100 }, c: { x: 600, y: 100 } } })
		const w = await mountEditor(wf, objectStore())
		const canvas = w.find('.workflow-editor__canvas')

		node(w, 'a').vm.$emit('connectionStart', new Event('mousedown'))
		await flushPromises()
		expect(w.vm.drawingConnection).toEqual({ fromStatus: 'a', startX: 110, startY: 60, currentX: 110, currentY: 60 })
		await canvas.trigger('mousemove', { clientX: 250, clientY: 90 })
		const line = w.find('svg line')
		expect([line.attributes('x1'), line.attributes('y1'), line.attributes('x2'), line.attributes('y2')]).toEqual(['110', '60', '250', '90'])

		node(w, 'c').vm.$emit('connectionEnd')
		await flushPromises()
		expect(wf.addTransition).toHaveBeenCalledWith('a', 'c')
		expect(w.emitted('dirty')).toHaveLength(1)
		expect(w.find('svg line').exists()).toBe(false)
	})

	it('adds nothing for a connection ended on its own node, or with none being drawn', async () => {
		const wf = workflowStore()
		const w = await mountEditor(wf, objectStore())
		node(w, 'a').vm.$emit('connectionStart', new Event('mousedown'))
		node(w, 'a').vm.$emit('connectionEnd')
		node(w, 'b').vm.$emit('connectionEnd')
		await flushPromises()
		expect(wf.addTransition).not.toHaveBeenCalled()
		expect(w.emitted('dirty')).toBeUndefined()
	})

	it('abandons a connection released on the canvas', async () => {
		const wf = workflowStore()
		const w = await mountEditor(wf, objectStore())
		node(w, 'a').vm.$emit('connectionStart', new Event('mousedown'))
		await w.find('.workflow-editor__canvas').trigger('mouseup')
		expect(w.vm.drawingConnection).toBeNull()
		expect(w.emitted('dirty')).toBeUndefined()
	})

	it('pans from the empty canvas, clearing the selection, and stops on release', async () => {
		const w = await mountEditor(workflowStore(), objectStore())
		const canvas = w.find('.workflow-editor__canvas')
		w.vm.selectNode('a')

		await canvas.trigger('mousedown', { clientX: 100, clientY: 100 })
		expect(w.vm.selectedNode).toBeNull()
		await canvas.trigger('mousemove', { clientX: 130, clientY: 80 })
		expect(w.vm.panOffset).toEqual({ x: 30, y: -20 })
		expect(canvas.attributes('style')).toContain('translate(30px, -20px) scale(1)')

		await canvas.trigger('mouseup')
		await canvas.trigger('mousemove', { clientX: 300, clientY: 300 })
		expect(w.vm.panOffset).toEqual({ x: 30, y: -20 })
	})

	it('pans from the transition layer too, but not from a node', async () => {
		const w = await mountEditor(workflowStore(), objectStore())
		await w.find('svg.workflow-editor__svg').trigger('mousedown', { clientX: 1, clientY: 1 })
		expect(w.vm.panning).toBe(true)
		await w.find('.workflow-editor__canvas').trigger('mouseup')

		w.vm.selectNode('a')
		await w.find('.WorkflowNode-stub').trigger('mousedown', { clientX: 1, clientY: 1 })
		expect(w.vm.panning).toBe(false)
		expect(w.vm.selectedNode).toBe('a')
	})

	it('zooms with the wheel in steps of a tenth, between 0.3 and 2', async () => {
		const w = await mountEditor(workflowStore(), objectStore())
		const canvas = w.find('.workflow-editor__canvas')
		await canvas.trigger('wheel', { deltaY: -1 })
		expect(w.vm.zoom).toBeCloseTo(1.1)
		for (let i = 0; i < 20; i++) {
			await canvas.trigger('wheel', { deltaY: -1 })
		}
		expect(w.vm.zoom).toBe(2)
		for (let i = 0; i < 30; i++) {
			await canvas.trigger('wheel', { deltaY: 1 })
		}
		expect(w.vm.zoom).toBe(0.3)
	})
})

describe('WorkflowEditor adding statuses', () => {
	it('creates a status where the palette status is dropped', async () => {
		const wf = workflowStore()
		const os = objectStore()
		const w = await mountEditor(wf, os)
		w.vm.zoom = 2
		w.findComponent({ name: 'WorkflowPalette' }).vm.$emit('dragStart', 'status')
		await w.trigger('drop', { clientX: 200, clientY: 100 })
		await flushPromises()

		expect(os.saveObject).toHaveBeenCalledWith('statusType', { name: 'New status', caseType: 'ct-1', order: 4, isFinal: false })
		expect(wf.updateNodePosition).toHaveBeenCalledWith('new-status', 100, 50)
		expect(w.vm.statusNodes.map((s) => s.id)).toEqual(['a', 'b', 'c', 'new-status'])
		expect(w.emitted('dirty')).toHaveLength(1)
		expect(w.vm.paletteDragType).toBeNull()
	})

	it('creates nothing for a drop that did not start on the palette, or a failed save', async () => {
		const os = objectStore({}, { saveObject: vi.fn(() => Promise.resolve(null)) })
		const w = await mountEditor(workflowStore(), os)
		await w.trigger('drop', { clientX: 1, clientY: 1 })
		await flushPromises()
		expect(os.saveObject).not.toHaveBeenCalled()

		w.findComponent({ name: 'WorkflowPalette' }).vm.$emit('dragStart', 'status')
		await w.trigger('drop', { clientX: 1, clientY: 1 })
		await flushPromises()
		expect(os.saveObject).toHaveBeenCalledTimes(1)
		expect(w.vm.statusNodes).toHaveLength(3)
		expect(w.emitted('dirty')).toBeUndefined()
	})

	it('adds a status from the keyboard at the next grid cell and selects it', async () => {
		const wf = workflowStore()
		const os = objectStore({ statusType: [...STATUSES, { id: 'd' }] })
		const w = await mountEditor(wf, os)
		w.findComponent({ name: 'WorkflowPalette' }).vm.$emit('addStatus')
		await flushPromises()

		expect(os.saveObject).toHaveBeenCalledWith('statusType', expect.objectContaining({ order: 5 }))
		expect(wf.updateNodePosition).toHaveBeenCalledWith('new-status', 100, 300)
		expect(w.vm.selectedNode).toBe('new-status')
		expect(w.emitted('dirty')).toHaveLength(1)
	})

	it('adds nothing from the keyboard when the save fails', async () => {
		const wf = workflowStore()
		const w = await mountEditor(wf, objectStore({}, { saveObject: vi.fn(() => Promise.resolve(null)) }))
		w.findComponent({ name: 'WorkflowPalette' }).vm.$emit('addStatus')
		await flushPromises()
		expect(wf.updateNodePosition).not.toHaveBeenCalled()
		expect(w.vm.selectedNode).toBeNull()
		expect(w.emitted('dirty')).toBeUndefined()
	})
})

describe('WorkflowEditor keyboard connections and the panels', () => {
	it('connects and disconnects from a node\'s keyboard menu', async () => {
		const wf = workflowStore()
		const w = await mountEditor(wf, objectStore())
		node(w, 'a').vm.$emit('keyboardConnect', 'c')
		expect(wf.addTransition).toHaveBeenCalledWith('a', 'c')

		w.vm.selectTransition('t2')
		node(w, 'a').vm.$emit('keyboardDisconnect', 't1')
		expect(wf.removeTransition).toHaveBeenLastCalledWith('t1')
		expect(w.vm.selectedTransition).toBe('t2')
		node(w, 'b').vm.$emit('keyboardDisconnect', 't2')
		expect(w.vm.selectedTransition).toBeNull()
		expect(w.emitted('dirty')).toHaveLength(3)
	})

	it('adds, updates and deletes steps through the store', async () => {
		const wf = workflowStore()
		const w = await mountEditor(wf, objectStore())
		node(w, 'b').vm.$emit('addStep')
		await flushPromises()
		expect(wf.addStep).toHaveBeenCalledWith('b')
		const panel = () => w.findComponent({ name: 'StepConfigPanel' })
		expect(panel().props('step')).toEqual({ id: 'new-step', status: 'b' })

		panel().vm.$emit('update', { id: 'new-step', title: 'Check' })
		await flushPromises()
		expect(wf.updateStep).toHaveBeenCalledWith('new-step', { id: 'new-step', title: 'Check' })
		expect(panel().props('step')).toEqual({ id: 'new-step', title: 'Check' })

		panel().vm.$emit('delete', 'new-step')
		await flushPromises()
		expect(wf.removeStep).toHaveBeenCalledWith('new-step')
		expect(panel().exists()).toBe(false)
		expect(w.emitted('dirty')).toHaveLength(3)
	})

	it('updates and deletes transitions through the store', async () => {
		const wf = workflowStore()
		const w = await mountEditor(wf, objectStore())
		w.vm.selectTransition('t1')
		await flushPromises()
		const panel = () => w.findComponent({ name: 'TransitionConfigPanel' })
		expect(panel().props()).toMatchObject({ roleTypes: [{ id: 'r1' }], documentTypes: [{ id: 'd1' }] })

		panel().vm.$emit('update', { id: 't1', label: 'Go' })
		expect(wf.updateTransition).toHaveBeenCalledWith('t1', { id: 't1', label: 'Go' })
		panel().vm.$emit('delete', 't1')
		await flushPromises()
		expect(wf.removeTransition).toHaveBeenCalledWith('t1')
		expect(panel().exists()).toBe(false)
		expect(w.emitted('dirty')).toHaveLength(2)
	})

	it('deletes a status from its node after confirmation', async () => {
		vi.stubGlobal('confirm', vi.fn(() => true))
		const wf = workflowStore()
		const os = objectStore()
		const w = await mountEditor(wf, os)
		node(w, 'b').vm.$emit('deleteStatus')
		await flushPromises()
		expect(os.deleteObject).toHaveBeenCalledWith('statusType', 'b')
		expect(wf.removeStatusNode).toHaveBeenCalledWith('b')
		expect(w.findAllComponents({ name: 'WorkflowNode' })).toHaveLength(2)
	})

	it('reports whether the graph is valid from the store\'s validation', async () => {
		const wf = workflowStore()
		const w = await mountEditor(wf, objectStore())
		expect(w.vm.validate()).toBe(true)
		expect(wf.validateWorkflow).toHaveBeenCalledWith(w.vm.statusNodes)
		wf.validateWorkflow.mockReturnValue([{ type: 'NO_FINAL', message: 'x' }])
		expect(w.vm.validate()).toBe(false)
		expect(w.vm.validationErrors).toEqual([{ type: 'NO_FINAL', message: 'x' }])
	})
})
