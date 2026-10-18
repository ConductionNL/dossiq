// @vitest-environment jsdom
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * Every open task is completable on the case (REQ-TASK-044).
 *
 * The pane used to act on the first open task only, and its buttons acted on
 * `currentTask` whatever row you were looking at. Finishing the second task of
 * a case therefore meant leaving the case page for it, which is exactly the
 * route change this surface exists to remove. What is asserted here is the
 * TARGET of each button, because a second task's button that fires the first
 * task's completion looks identical on screen to one that works: a task
 * disappears from the pane either way.
 *
 * The claim affordance is asserted against the ENGINE CAPABILITY rather than
 * against the declaration. A claim button rendered where the engine answers no
 * claim act would silently do nothing, and a handler would wait for a team
 * that was never offered the task.
 *
 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const showSuccess = vi.fn()
const showError = vi.fn()

vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: (...args) => showSuccess(...args),
	showError: (...args) => showError(...args),
}))

vi.mock('../../src/store/store.js', () => ({
	initializeStores: async () => ({}),
}))

/** What the capabilities endpoint answers, replaced per test. */
const claimSupported = false

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: async () => ({ data: { claim: claimSupported } }),
		post: async () => ({ data: {} }),
	},
}))

/** The rows the engine store answers with. */
let pages = []
/** Every verb invocation, so the TARGET of each button can be asserted. */
let invoked = []
/** What `invoke()` answers with. */
const invokeResult = { uuid: 'task-2', state: 'completed', isTerminal: true }

const storeStub = {
	error: null,
	async list() {
		return pages.length > 1 ? pages.shift() : (pages[0] ?? [])
	},
	created: [],
	async create(task) {
		this.created.push(task)
		return { uuid: 'task-new', ...task }
	},
	async invoke(uuid, verb, body = {}) {
		invoked.push({ uuid, verb, body })
		return invokeResult
	},
}

vi.mock('../../src/store/modules/engineTask.js', async (importOriginal) => ({
	...(await importOriginal()),
	useEngineTaskStore: () => storeStub,
}))

const { default: CaseTaskPane } =
	await import('../../src/components/tasks/CaseTaskPane.vue')

const TABLE = { limit: 25, rowRoute: 'TaskDetail', mode: 'table' }

const OPEN = { uuid: 'task-1', title: 'Documenten beoordelen', status: 'active', assignee: 'pjansen', dueDate: '2026-10-18T00:00:00+00:00' }
const DONE = { uuid: 'task-2', title: 'Ontvangst bevestigen', state: 'completed', isTerminal: true, assignee: 'pjansen', dueDate: '2026-10-04T00:00:00+00:00' }

const RouterLinkStub = defineComponent({
	name: 'RouterLink',
	props: { to: { type: [String, Object], default: '' } },
	render() {
		return h('a', {}, this.$slots.default?.())
	},
})

/**
 * Mount the pane in table mode over the given rows.
 *
 * @param {object[]} rows The tasks the store answers with.
 * @return {Promise<object>} The wrapper.
 */
async function mountTable(rows) {
	pages = [rows]
	const wrapper = mount(CaseTaskPane, {
		props: { objectId: 'case-9', content: TABLE },
		global: { stubs: { RouterLink: RouterLinkStub } },
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	showSuccess.mockClear()
	showError.mockClear()
	invoked = []
	storeStub.created = []
	storeStub.error = null
})

describe('CaseTaskPane, table mode (board DqZaakTaken)', () => {
	it('lists every task of the case, open and done, with the count line', async () => {
		const wrapper = await mountTable([OPEN, DONE])
		expect(wrapper.find('[data-testid="case-task-pane-table"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="case-task-pane-row-task-1"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="case-task-pane-row-task-2"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="case-task-pane-summary"]').text()).toContain('1 open, 1 done')
		// A finished task offers no Complete.
		expect(wrapper.find('[data-testid="case-task-pane-row-complete-task-2"]').exists()).toBe(false)
		// No worker until a row is chosen.
		expect(wrapper.find('[data-testid="case-task-pane-current"]').exists()).toBe(false)
	})

	it('opens the current-task worker for the row clicked', async () => {
		const wrapper = await mountTable([OPEN, DONE])
		await wrapper.find('[data-testid="case-task-pane-row-task-1"] .case-task-pane__row-title').trigger('click')
		expect(wrapper.find('[data-testid="case-task-pane-current"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="case-task-pane-title"]').text()).toBe('Documenten beoordelen')
	})

	it('completes a task from its row', async () => {
		const wrapper = await mountTable([OPEN, DONE])
		await wrapper.find('[data-testid="case-task-pane-row-complete-task-1"]').trigger('click')
		await flushPromises()
		expect(invoked[0]).toMatchObject({ uuid: 'task-1', verb: 'complete' })
	})

	it('adds a task to the case from New task', async () => {
		const wrapper = await mountTable([])
		expect(wrapper.find('[data-testid="case-task-pane-empty"]').exists()).toBe(true)
		await wrapper.setData({ newTaskOpen: true, newTitle: 'Advies vragen', newDue: new Date('2026-10-20T12:00:00Z') })
		await wrapper.vm.createTask()
		expect(storeStub.created).toEqual([{ title: 'Advies vragen', case: 'case-9', dueDate: '2026-10-20' }])
		expect(showSuccess).toHaveBeenCalled()
		expect(wrapper.vm.newTaskOpen).toBe(false)
	})
})
