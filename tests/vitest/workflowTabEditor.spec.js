// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction / Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case type's workflow tab around nc-vue's CnStateMachineEditor: the
 * statuses it loads become the editor's states, every edit the editor reports
 * lands in the workflow store, an invalid graph blocks publishing, and the
 * manifest config keeps every text the editor showed in Dutch.
 *
 * The editor is the stub in tests/vitest/stubs/conduction-nextcloud-vue.js,
 * whose validate() runs the library's real validator. The stores are plain
 * mocks put in place of the `workflowStore`/`objectStore` computeds.
 *
 * @spec openspec/specs/visual-workflow-editor/spec.md#requirement-drag-and-drop-workflow-canvas
 * @spec openspec/specs/visual-workflow-editor/spec.md#requirement-workflow-editor-validation
 */

import { CnStateMachineEditor } from '@conduction/nextcloud-vue'
import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import path from 'node:path'
import { describe, expect, it, vi } from 'vitest'
import WorkflowTab from '../../src/views/settings/tabs/WorkflowTab.vue'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const nl = JSON.parse(readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8')).translations

/**
 * Find the workflow widget's definition in the manifest.
 *
 * @param {*} node A manifest node.
 * @return {object|null} The widget.
 */
function findWidget(node) {
	if (Array.isArray(node)) {
		for (const item of node) {
			const found = findWidget(item)
			if (found) return found
		}
		return null
	}
	if (node && typeof node === 'object') {
		if (node.id === 'case-type-workflow-editor' && node.type === 'custom') return node
		for (const value of Object.values(node)) {
			const found = findWidget(value)
			if (found) return found
		}
	}
	return null
}

const WIDGET = findWidget(manifest)

const STATUSES = [
	{ id: 's1', name: 'Received', isFinal: false },
	{ id: 's2', name: 'Handled', isFinal: true },
]

/**
 * @param {object} overrides Store shape over the defaults.
 * @return {object} A mock workflow store.
 */
function workflowStoreMock(overrides = {}) {
	return {
		currentTemplate: { id: 'tpl-1', isDraft: true, nodePositions: '{}' },
		versions: [{ id: 'tpl-1', version: 1, isDraft: true }],
		draftVersion: { id: 'tpl-1', version: 1, isDraft: true },
		activeVersion: null,
		parsedSteps: [{ id: 'p1', status: 's1', title: 'Check', order: 1, config: { sla: { value: 2, unit: 'hours' } } }],
		parsedTransitions: [{ id: 't1', fromStatus: 's1', toStatus: 's2', label: 'Finish' }],
		parsedNodePositions: {},
		validationErrors: [],
		listVersions: vi.fn(() => Promise.resolve()),
		getTemplate: vi.fn(() => Promise.resolve()),
		publishVersion: vi.fn(() => Promise.resolve({ id: 'tpl-1' })),
		saveTemplate: vi.fn(() => Promise.resolve()),
		updateNodePosition: vi.fn(),
		addTransition: vi.fn(),
		updateTransition: vi.fn(),
		removeTransition: vi.fn(),
		addStep: vi.fn(() => ({ id: 'p9' })),
		updateStep: vi.fn(),
		removeStep: vi.fn(),
		removeStatusNode: vi.fn(),
		...overrides,
	}
}

/**
 * @param {object} overrides Store shape over the defaults.
 * @return {object} A mock object store.
 */
function objectStoreMock(overrides = {}) {
	return {
		fetchCollection: vi.fn((schema) => Promise.resolve(schema === 'statusType' ? STATUSES.map((s) => ({ ...s })) : [{ id: 'r1', name: 'Handler' }])),
		saveObject: vi.fn((schema, data) => Promise.resolve({ id: 'new-status', ...data })),
		deleteObject: vi.fn(() => Promise.resolve(true)),
		...overrides,
	}
}

/**
 * Mount the tab with the given stores and the manifest's widget content.
 *
 * @param {object} workflowStore The workflow store mock.
 * @param {object} objectStore The object store mock.
 * @return {Promise<object>} The wrapper, loaded.
 */
async function mountTab(workflowStore = workflowStoreMock(), objectStore = objectStoreMock()) {
	const wrapper = mount(
		{
			...WorkflowTab,
			computed: {
				...WorkflowTab.computed,
				workflowStore: () => workflowStore,
				objectStore: () => objectStore,
			},
		},
		{ props: { objectId: 'ct-1', content: WIDGET.content } },
	)
	await flushPromises()
	return wrapper
}

describe('WorkflowTab: the case type workflow on CnStateMachineEditor', () => {
	it('hands the statuses, the template and the manifest config to the editor', async () => {
		const wrapper = await mountTab()
		const editor = wrapper.findComponent(CnStateMachineEditor)
		expect(editor.props('states').map((s) => s.id)).toEqual(['s1', 's2'])
		expect(editor.props('transitions')[0].id).toBe('t1')
		expect(editor.props('steps')[0].id).toBe('p1')
		expect(editor.props('config')).toEqual(WIDGET.content.editor)
		expect(editor.props('optionSets').roles).toEqual([{ value: 'r1', label: 'Handler' }])
		expect(editor.props('locked')).toBe(false)
		expect(wrapper.findAll('.state-stub').map((node) => node.text())).toEqual(['Received', 'Handled'])
	})

	it('locks the published version\'s step settings', async () => {
		const wrapper = await mountTab(workflowStoreMock({ currentTemplate: { id: 'tpl-1', isDraft: false } }))
		expect(wrapper.findComponent(CnStateMachineEditor).props('locked')).toBe(true)
	})

	it('creates a status where the editor asks, places it and hands its id back', async () => {
		const workflowStore = workflowStoreMock()
		const objectStore = objectStoreMock()
		const wrapper = await mountTab(workflowStore, objectStore)
		const done = vi.fn()
		wrapper.findComponent(CnStateMachineEditor).vm.$emit('state-add', { position: { x: 40, y: 50 }, done })
		await flushPromises()
		expect(objectStore.saveObject).toHaveBeenCalledWith('statusType', { name: 'New status', caseType: 'ct-1', order: 3, isFinal: false })
		expect(workflowStore.updateNodePosition).toHaveBeenCalledWith('new-status', 40, 50)
		expect(done).toHaveBeenCalledWith('new-status')
		expect(wrapper.findAll('.state-stub')).toHaveLength(3)
	})

	it('deletes a confirmed status with its steps and transitions, or reports that it could not', async () => {
		const workflowStore = workflowStoreMock()
		const objectStore = objectStoreMock({ deleteObject: vi.fn().mockResolvedValueOnce(false).mockResolvedValueOnce(true) })
		const wrapper = await mountTab(workflowStore, objectStore)
		const editor = wrapper.findComponent(CnStateMachineEditor)

		const failed = vi.fn()
		editor.vm.$emit('state-remove', { id: 's1', done: failed })
		await flushPromises()
		expect(failed).toHaveBeenCalledWith(false)
		expect(workflowStore.removeStatusNode).not.toHaveBeenCalled()

		const removed = vi.fn()
		editor.vm.$emit('state-remove', { id: 's1', done: removed })
		await flushPromises()
		expect(removed).toHaveBeenCalledWith(true)
		expect(workflowStore.removeStatusNode).toHaveBeenCalledWith('s1')
		expect(editor.props('states').map((s) => s.id)).toEqual(['s2'])
	})

	it('stores a step as edited, so a setting the editor dropped is gone', async () => {
		const workflowStore = workflowStoreMock()
		const wrapper = await mountTab(workflowStore)
		wrapper.findComponent(CnStateMachineEditor).vm.$emit('step-update', { id: 'p1', status: 's1', title: 'Check', order: 1 })
		expect(workflowStore.updateStep).toHaveBeenCalledWith('p1', { id: 'p1', status: 's1', title: 'Check', order: 1, config: undefined })
	})

	it('writes a new step order, adds steps and transitions, and moves statuses', async () => {
		const workflowStore = workflowStoreMock()
		const wrapper = await mountTab(workflowStore)
		const editor = wrapper.findComponent(CnStateMachineEditor)
		editor.vm.$emit('step-reorder', { state: 's1', ids: ['p2', 'p1'] })
		expect(workflowStore.updateStep).toHaveBeenCalledWith('p2', { order: 1 })
		expect(workflowStore.updateStep).toHaveBeenCalledWith('p1', { order: 2 })
		const done = vi.fn()
		editor.vm.$emit('step-add', { state: 's1', done })
		expect(done).toHaveBeenCalledWith('p9')
		editor.vm.$emit('transition-add', { from: 's2', to: 's1' })
		expect(workflowStore.addTransition).toHaveBeenCalledWith('s2', 's1')
		editor.vm.$emit('state-move', { id: 's1', x: -4, y: 30 })
		expect(workflowStore.updateNodePosition).toHaveBeenCalledWith('s1', 0, 30)
	})

	it('blocks publishing a workflow without a final status, and says why', async () => {
		const workflowStore = workflowStoreMock()
		const objectStore = objectStoreMock({
			fetchCollection: vi.fn((schema) => Promise.resolve(schema === 'statusType'
				? [{ id: 's1', name: 'Received', isFinal: false }, { id: 's2', name: 'In handling', isFinal: false }]
				: [])),
		})
		const wrapper = await mountTab(workflowStore, objectStore)
		await wrapper.vm.publish()
		await flushPromises()
		expect(workflowStore.publishVersion).not.toHaveBeenCalled()
		expect(wrapper.text()).toContain('Workflow has no final status defined')
	})

	it('publishes a valid workflow with the statuses it validated', async () => {
		const workflowStore = workflowStoreMock()
		const wrapper = await mountTab(workflowStore)
		await wrapper.vm.publish()
		expect(workflowStore.publishVersion).toHaveBeenCalledWith('tpl-1', STATUSES)
	})
})

describe('the manifest config of the case type workflow editor', () => {
	/**
	 * Every text in the editor config, for the translation check.
	 *
	 * @param {*} node A config node.
	 * @param {Set<string>} out The texts found.
	 * @return {Set<string>} `out`.
	 */
	function texts(node, out = new Set()) {
		if (Array.isArray(node)) {
			node.forEach((item) => texts(item, out))
		} else if (node && typeof node === 'object') {
			for (const [key, value] of Object.entries(node)) {
				if (['labels', 'messages'].includes(key)) Object.values(value).forEach((text) => out.add(text))
				else if (key === 'paletteHelp') value.forEach((text) => out.add(text))
				else if (['label', 'toggleLabel', 'lockedNotice', 'itemLabel', 'addLabel', 'removeLabel', 'placeholder', 'hint', 'typeLabel', 'term', 'text'].includes(key) && typeof value === 'string' && value !== '') out.add(value)
				else texts(value, out)
			}
		}
		return out
	}

	it('describes a step and a transition the way the old panels edited them', () => {
		const editor = WIDGET.content.editor
		expect(editor.stepFields.map((field) => field.key ?? field.label)).toEqual(['title', 'description', 'isRequired', 'assigneeRole', 'checklist', 'Advanced', 'automaticActions'])
		expect(editor.transitionFields.map((field) => field.key)).toEqual(['label', 'explanation', 'allowedRoles', 'guards', 'automaticActions'])
		expect(editor.transitionFields[3].types.map((type) => type.value)).toEqual(['checklist', 'requiredField', 'requiredDocument', 'roleGuard'])
	})

	it('keeps every text in Dutch', () => {
		const missing = [...texts(WIDGET.content.editor)].filter((text) => typeof nl[text] !== 'string' || nl[text] === '')
		expect(missing).toEqual([])
	})
})
