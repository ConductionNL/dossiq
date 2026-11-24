// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case type flow page's header row and its Canvas | List switch
 * (flow-page-list-beside-canvas task 1): the case type's title, the version
 * and state pills, Check, More and Save, and the toolbar buttons staying as
 * they were on the case type page that embeds the same tab without it.
 *
 * @spec openspec/changes/flow-page-list-beside-canvas/specs/visual-workflow-editor/spec.md#requirement-a-case-types-flow-reads-as-a-list-beside-the-canvas
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h, reactive } from 'vue'

vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: ['type', 'variant', 'disabled'],
		emits: ['click'],
		render() {
			return h('button', { disabled: this.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.())
		},
	},
	NcLoadingIcon: { name: 'NcLoadingIcon', render: () => null },
}))

const validate = vi.fn(() => true)
vi.mock('../../src/views/settings/WorkflowEditor.vue', () => ({
	default: {
		name: 'WorkflowEditor',
		props: ['caseTypeId', 'templateId', 'view'],
		methods: { validate: () => validate() },
		render() {
			return h('div', { class: 'workflow-editor-stub', 'data-view': this.view })
		},
	},
}))

const workflowStore = reactive({
	versions: [],
	currentTemplate: null,
	activeVersion: null,
	draftVersion: null,
	validationErrors: [],
	listVersions: vi.fn(),
	getTemplate: vi.fn(),
	saveTemplate: vi.fn(),
	publishVersion: vi.fn(),
	createDraftFromVersion: vi.fn(),
	exportWorkflow: vi.fn(),
	importWorkflow: vi.fn(),
})
const objectStore = { fetchObject: vi.fn(), fetchCollection: vi.fn() }
vi.mock('../../src/store/modules/workflow.js', () => ({ useWorkflowStore: () => workflowStore }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => objectStore }))

const { default: WorkflowTab } = await import('../../src/views/settings/tabs/WorkflowTab.vue')

/**
 * Mount the tab on one draft version and let it load.
 *
 * @param {object} props Its props.
 * @return {Promise<object>} The wrapper.
 */
async function mountTab(props) {
	const w = mount(WorkflowTab, { props: { caseTypeId: 'ct-1', ...props } })
	await flushPromises()
	return w
}

/**
 * @param {object} w The wrapper.
 * @param {string} which version or state.
 * @return {string} The label the header's badge carries.
 */
function badge(w, which) {
	return w.find(`[data-testid="workflow-flow-${which}"]`).attributes('label')
}

beforeEach(() => {
	vi.clearAllMocks()
	validate.mockImplementation(() => true)
	workflowStore.listVersions.mockImplementation(async () => {
		workflowStore.versions = [{ id: 'v2', version: 2, isDraft: true }]
		workflowStore.draftVersion = { id: 'v2' }
	})
	workflowStore.getTemplate.mockImplementation(async () => {
		workflowStore.currentTemplate = { id: 'v2', version: 2, isDraft: true, title: 'Workflow' }
	})
	objectStore.fetchObject.mockResolvedValue({ id: 'ct-1', title: 'Environmental permit regular' })
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

describe('the flow page header row', () => {
	it('names the case type, the version and its state, with Check, More and Save', async () => {
		const w = await mountTab({ boardHeader: true })
		expect(objectStore.fetchObject).toHaveBeenCalledWith('caseType', 'ct-1')
		expect(w.find('[data-testid="workflow-flow-title"]').text()).toBe('Environmental permit regular')
		expect(badge(w, 'version')).toBe('Version 2')
		expect(badge(w, 'state')).toBe('Draft')
		expect(w.find('[data-testid="workflow-flow-save"]').attributes('disabled')).toBeDefined()
		expect(w.find('.workflow-tab__actions').exists()).toBe(false)
	})

	it('falls back to the generic word when the case type cannot be read', async () => {
		objectStore.fetchObject.mockRejectedValue(new Error('gone'))
		const w = await mountTab({ boardHeader: true })
		expect(w.find('[data-testid="workflow-flow-title"]').text()).toBe('Flow')
	})

	it('says a clean flow is clean on Check and leaves the errors to the editor otherwise', async () => {
		const w = await mountTab({ boardHeader: true })
		await w.find('[data-testid="workflow-flow-check"]').trigger('click')
		expect(validate).toHaveBeenCalled()
		expect(w.find('[data-testid="workflow-flow-check-notice"]').text()).toBe('No problems found in this flow.')
		validate.mockImplementation(() => false)
		await w.find('[data-testid="workflow-flow-check"]').trigger('click')
		expect(w.find('[data-testid="workflow-flow-check-notice"]').exists()).toBe(false)
	})

	it('opens More with the draft actions and closes it on a choice', async () => {
		const w = await mountTab({ boardHeader: true })
		await w.find('[data-testid="workflow-flow-more"]').trigger('click')
		const items = w.findAll('[data-testid="workflow-flow-more-menu"] button').map((b) => b.text())
		expect(items).toEqual(['Publish', 'Download', 'Import'])
		const pick = vi.spyOn(w.vm.$refs.importInput, 'click').mockImplementation(() => {})
		await w.findAll('[data-testid="workflow-flow-more-menu"] button')[2].trigger('click')
		expect(pick).toHaveBeenCalled()
		expect(w.find('[data-testid="workflow-flow-more-menu"]').exists()).toBe(false)
	})

	it('saves a changed draft from the header', async () => {
		const w = await mountTab({ boardHeader: true })
		w.vm.dirty = true
		await flushPromises()
		await w.find('[data-testid="workflow-flow-save"]').trigger('click')
		expect(workflowStore.saveTemplate).toHaveBeenCalled()
	})

	it('reads Active or Published on a version that is not a draft', async () => {
		workflowStore.getTemplate.mockImplementation(async () => {
			workflowStore.currentTemplate = { id: 'v1', version: 1, isDraft: false, isActive: true }
		})
		const w = await mountTab({ boardHeader: true })
		expect(badge(w, 'state')).toBe('Active')
		workflowStore.currentTemplate = { id: 'v0', version: 0, isDraft: false, isActive: false }
		await flushPromises()
		expect(badge(w, 'state')).toBe('Published')
	})

	it('keeps the toolbar buttons where the case type page embeds the tab without the header', async () => {
		const w = await mountTab({})
		expect(objectStore.fetchObject).not.toHaveBeenCalled()
		expect(w.find('[data-testid="workflow-flow-header"]').exists()).toBe(false)
		expect(w.find('.workflow-tab__actions').exists()).toBe(true)
	})
})

describe('the Canvas | List switch', () => {
	it('shows the board line and hands the chosen view to the editor', async () => {
		const w = await mountTab({ boardHeader: true })
		expect(w.find('[data-testid="workflow-view-switch"]').text()).toContain('what you change here is there too')
		expect(w.find('.workflow-editor-stub').attributes('data-view')).toBe('canvas')
		expect(w.find('[data-testid="workflow-view-canvas"]').attributes('aria-pressed')).toBe('true')
		await w.find('[data-testid="workflow-view-list"]').trigger('click')
		expect(w.find('.workflow-editor-stub').attributes('data-view')).toBe('list')
		expect(w.find('[data-testid="workflow-view-list"]').attributes('aria-pressed')).toBe('true')
	})
})
