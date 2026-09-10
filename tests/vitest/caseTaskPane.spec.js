// @vitest-environment jsdom
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The task pane on the case page (task-on-the-case, REQ-TASK-014).
 *
 * What the pane promises is a sequence, not a render: the open task is there
 * with its buttons, finishing it confirms by name and the NEXT task takes its
 * place, activating it changes nothing but the buttons, a refusal leaves the
 * task where it was, and a case with nothing open says so. Each of those is
 * only observable here — the e2e spec proves them against a real
 * OpenRegister, but it cannot reach the refusal path (its user is admin) and
 * it cannot tell "kept the task" apart from "reloaded the same task".
 *
 * The spec file lives here rather than beside the component: vitest.config
 * collects `tests/vitest/**` only, so a spec under `src/` would never run.
 *
 * @spec openspec/changes/task-on-the-case/specs/task-management/spec.md
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

/** The rows the engine store answers with, replaced per test. */
let pages = []
/** Every `list()` call, so the query can be asserted. */
let calls = []

/**
 * The engine store stub.
 *
 * `error` is part of the surface deliberately: the real store SURFACES a
 * failed read rather than throwing, because an empty list with no trace of
 * why is indistinguishable from a genuinely empty one, and the pane reports
 * it. A stub without it would let that path go untested.
 */
/** What `invoke()` answers with, and every verb it was asked for. */
let invokeResult = { uuid: 'task-1', state: 'completed', isTerminal: true }
let invoked = []

const storeStub = {
	error: null,
	async list(params) {
		calls.push({ type: 'flow-tasks', params })
		return pages.length > 1 ? pages.shift() : (pages[0] ?? [])
	},
	async invoke(uuid, verb) {
		invoked.push({ uuid, verb })
		return invokeResult
	},
}

vi.mock('../../src/store/modules/engineTask.js', async (importOriginal) => ({
	...(await importOriginal()),
	useEngineTaskStore: () => storeStub,
}))

const { default: CaseTaskPane } =
	await import('../../src/components/tasks/CaseTaskPane.vue')

/** The widget content blob, as `src/manifest.json` declares it. */
const CONTENT = {
	register: 'dossiq',
	schema: 'caseTask',
	filter: { case: '@objectId' },
	sort: { field: 'dueDate', dir: 'asc' },
	limit: 25,
	rowRoute: 'TaskDetail',
	viewAllRoute: 'Tasks',
	viewAllQuery: { case: '@objectId' },
}

const FIRST = {
	id: 'task-1',
	title: 'Check the application',
	status: 'active',
	assignee: 'hbakker',
	dueDate: '2026-09-10T00:00:00+00:00',
}
const SECOND = {
	id: 'task-2',
	title: 'Send the decision',
	status: 'available',
	assignee: 'jdejong',
	dueDate: '2026-09-20T00:00:00+00:00',
}

/** A router-link that renders its target so the route can be read off it. */
const RouterLinkStub = defineComponent({
	name: 'RouterLink',
	props: { to: { type: [String, Object], default: '' } },
	render() {
		return h(
			'a',
			{ 'data-to': JSON.stringify(this.to) },
			this.$slots.default?.(),
		)
	},
})

/**
 * Mount the pane over a scripted sequence of fetch results.
 *
 * @param {Array<object[]>} responses One row list per `fetchCollection` call;
 *   the last one is reused for any further call.
 * @return {Promise<object>} The mounted wrapper, after its load settles.
 */
async function mountPane(responses) {
	pages = responses.map((rows) => rows)
	const wrapper = mount(CaseTaskPane, {
		props: { objectId: 'case-9', content: CONTENT },
		global: {
			stubs: { RouterLink: RouterLinkStub },
		},
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	showSuccess.mockClear()
	showError.mockClear()
	calls = []
	invoked = []
	invokeResult = { uuid: 'task-1', state: 'completed', isTerminal: true }
	storeStub.error = null
})

describe('CaseTaskPane', () => {
	it('shows the first open task with its lifecycle buttons, and lists the rest', async () => {
		const wrapper = await mountPane([[FIRST, SECOND]])

		// The query is the half a screenshot cannot show: filtered on the case
		// and on the server-side open flag.
		expect(calls[0].type).toBe('flow-tasks')
		expect(calls[0].params).toMatchObject({
			// The case IS the object; the engine keeps no typed case reference.
			objectUuid: 'case-9',
			// The case's work, not the reader's.
			scope: 'all',
		})

		expect(wrapper.find('[data-testid="case-task-pane-title"]').text()).toBe(
			'Check the application',
		)
		expect(wrapper.find('[data-testid="case-task-pane-assignee"]').text()).toBe(
			'hbakker',
		)

		// The verbs are the ENGINE's, not an object's transitions.
		// CnLifecycleActions asks OpenRegister for
		// /api/objects/{uuid}/available-actions, and an engine task is not an
		// object: that endpoint answers 500. Measured in the browser.
		expect(
			wrapper.find('[data-testid="case-task-pane-verb-complete"]').exists(),
		).toBe(true)
		expect(
			wrapper.find('[data-testid="case-task-pane-verb-cancel"]').exists(),
		).toBe(true)

		// The second task is listed under the pane and links to its own page.
		const remaining = wrapper.find('[data-testid="case-task-pane-remaining"]')
		expect(remaining.text()).toContain('Send the decision')
		expect(remaining.text()).not.toContain('Check the application')
		expect(JSON.parse(remaining.find('a').attributes('data-to'))).toEqual({
			name: 'TaskDetail',
			params: { id: 'task-2' },
		})

		// View all keeps the case scope.
		expect(
			JSON.parse(
				wrapper
					.find('[data-testid="case-task-pane-view-all"]')
					.attributes('data-to'),
			),
		).toEqual({ name: 'Tasks', query: { case: 'case-9' } })
	})

	it('confirms a completed task by name and puts the next one in the pane', async () => {
		const wrapper = await mountPane([[FIRST, SECOND], [SECOND]])

		await wrapper
			.find('[data-testid="case-task-pane-verb-complete"]')
			.trigger('click')
		await flushPromises()

		// The toast names the task that was finished, not the one that took
		// its place: a toast reading the new title would confirm the wrong
		// thing on every completion.
		expect(showSuccess).toHaveBeenCalledTimes(1)
		expect(showSuccess.mock.calls[0][0]).toContain('Check the application')

		expect(wrapper.find('[data-testid="case-task-pane-title"]').text()).toBe(
			'Send the decision',
		)
		expect(invoked).toEqual([{ uuid: 'task-1', verb: 'complete' }])
		expect(
			wrapper.find('[data-testid="case-task-pane-remaining"]').exists(),
		).toBe(false)
	})

	it('keeps the task on a non-final transition and shows no toast', async () => {
		const activated = { ...SECOND, status: 'active' }
		const wrapper = await mountPane([[SECOND], [activated]])

		// The engine answers with a NON-terminal task: picking one up is not
		// finishing it.
		invokeResult = { ...activated, isTerminal: false }
		await wrapper
			.find('[data-testid="case-task-pane-verb-complete"]')
			.trigger('click')
		await flushPromises()

		// Picking a task up is not finishing it: same task, no confirmation,
		// but a refetch so the newly allowed buttons render.
		expect(showSuccess).not.toHaveBeenCalled()
		expect(calls).toHaveLength(2)
		expect(invoked).toEqual([{ uuid: 'task-2', verb: 'complete' }])
	})

	it('reports a refused transition and keeps the task in the pane', async () => {
		const wrapper = await mountPane([[FIRST, SECOND]])

		// The engine refuses by returning null and putting its own message on
		// the store. That message names the verb and the reason, and a
		// generic failure would throw away the only part a handler can act on.
		invokeResult = null
		storeStub.error = 'Only the assignee may complete this task'
		await wrapper
			.find('[data-testid="case-task-pane-verb-complete"]')
			.trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalledTimes(1)
		expect(showError.mock.calls[0][0]).toBe(
			'Only the assignee may complete this task',
		)
		// No refetch, no advance: the task is exactly where it was.
		expect(calls).toHaveLength(1)
		expect(wrapper.find('[data-testid="case-task-pane-title"]').text()).toBe(
			'Check the application',
		)
	})

	it('says so when the case has no open task left', async () => {
		const wrapper = await mountPane([[]])

		expect(wrapper.find('[data-testid="case-task-pane-empty"]').text()).toBe(
			'No open tasks on this case',
		)
		expect(wrapper.find('[data-testid="case-task-pane-actions"]').exists()).toBe(
			false,
		)
		expect(
			wrapper.find('[data-testid="case-task-pane-remaining"]').exists(),
		).toBe(false)
	})
})
