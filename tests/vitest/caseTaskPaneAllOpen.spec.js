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
let claimSupported = false

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
let invokeResult = { uuid: 'task-2', state: 'completed', isTerminal: true }

const storeStub = {
	error: null,
	async list() {
		return pages.length > 1 ? pages.shift() : (pages[0] ?? [])
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

const { default: CaseTaskPane } = await import(
	'../../src/components/tasks/CaseTaskPane.vue'
)

const CONTENT = { limit: 25, rowRoute: 'TaskDetail' }

const FIRST = {
	uuid: 'task-1',
	title: 'Toets ontvankelijkheid',
	status: 'active',
	assignee: 'hbakker',
	dueDate: '2026-09-10T00:00:00+00:00',
}
const SECOND = {
	uuid: 'task-2',
	title: 'Hoor de belanghebbende',
	status: 'available',
	assignee: 'hbakker',
	dueDate: '2026-09-20T00:00:00+00:00',
	form: {
		kind: 'fields',
		state: 'ready',
		fields: [{ field: 'verslag', required: true, renderable: true }],
	},
}
const THIRD = {
	uuid: 'task-3',
	title: 'Vraag advies',
	status: 'available',
	assignee: '',
	candidateGroups: ['Juridische Zaken'],
	dueDate: '2026-09-25T00:00:00+00:00',
}

const RouterLinkStub = defineComponent({
	name: 'RouterLink',
	props: { to: { type: [String, Object], default: '' } },
	render() {
		return h('a', {}, this.$slots.default?.())
	},
})

/**
 * Mount the pane over one page of rows.
 *
 * @param {Array<object[]>} responses One row list per list() call.
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountPane(responses) {
	pages = responses.map((rows) => rows)
	const wrapper = mount(CaseTaskPane, {
		props: { objectId: 'case-9', content: CONTENT },
		global: { stubs: { RouterLink: RouterLinkStub } },
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	showSuccess.mockClear()
	showError.mockClear()
	invoked = []
	claimSupported = false
	invokeResult = { uuid: 'task-2', state: 'completed', isTerminal: true }
	storeStub.error = null
})

describe('CaseTaskPane, every open task', () => {
	it('completes the second open task without leaving the case', async () => {
		const wrapper = await mountPane([[FIRST, SECOND, THIRD], [FIRST, THIRD]])

		// The second task carries a form, and its required field is answered
		// here rather than left empty: an empty one is refused before the
		// round trip, which is its own test below.
		await wrapper
			.find('[data-testid="case-task-pane-form-task-2-verslag"]')
			.setValue('Gehoord op 3 maart')
		await wrapper
			.find('[data-testid="case-task-pane-row-complete-task-2"]')
			.trigger('click')
		await flushPromises()

		// THE TARGET, not the outcome: a button firing task-1's completion
		// would empty the pane the same way.
		expect(invoked).toHaveLength(1)
		expect(invoked[0].uuid).toBe('task-2')
		expect(invoked[0].verb).toBe('complete')
		expect(showSuccess.mock.calls[0][0]).toContain('Hoor de belanghebbende')
	})

	it('shows the second task its own form and sends what was typed', async () => {
		const wrapper = await mountPane([[FIRST, SECOND]])

		const field = wrapper.find('[data-testid="case-task-pane-form-task-2-verslag"]')
		expect(field.exists()).toBe(true)

		await field.setValue('Gehoord op 3 maart')
		await wrapper
			.find('[data-testid="case-task-pane-row-complete-task-2"]')
			.trigger('click')
		await flushPromises()

		expect(invoked[0].body).toEqual({ data: { verslag: 'Gehoord op 3 maart' } })
	})

	it('refuses a completion with a required field empty, naming the field', async () => {
		const wrapper = await mountPane([[FIRST, SECOND]])

		await wrapper
			.find('[data-testid="case-task-pane-row-complete-task-2"]')
			.trigger('click')
		await flushPromises()

		// Refused before the round trip, with the field named. The server
		// refuses it again for every other client; this is what stops the
		// handler finding out after the task is gone from the pane.
		expect(invoked).toHaveLength(0)
		expect(showError).toHaveBeenCalledTimes(1)
		expect(showError.mock.calls[0][0]).toContain('verslag')
	})

	it('keeps one answer set per task, so one task cannot carry another one answers', async () => {
		const wrapper = await mountPane([[SECOND, { ...THIRD, form: SECOND.form }]])

		await wrapper
			.find('[data-testid="case-task-pane-form-verslag"]')
			.setValue('Verslag van de tweede')
		await wrapper
			.find('[data-testid="case-task-pane-row-complete-task-3"]')
			.trigger('click')
		await flushPromises()

		// task-3's completion was refused on its OWN empty field, not sent
		// with task-2's answer.
		expect(invoked).toHaveLength(0)
		expect(showError.mock.calls[0][0]).toContain('verslag')
	})

	it('offers no claim affordance when the engine answers no claim act', async () => {
		claimSupported = false
		const wrapper = await mountPane([[THIRD]])

		expect(wrapper.find('[data-testid="case-task-pane-verb-claim"]').exists()).toBe(false)
		// ...and says so, rather than leaving a task that reaches nobody
		// looking like a task somebody will pick up.
		expect(wrapper.find('[data-testid="case-task-pane-candidates"]').text()).toContain(
			'Juridische Zaken',
		)
	})

	it('offers the claim affordance when the engine answers one', async () => {
		claimSupported = true
		invokeResult = { uuid: 'task-3', assignee: 'hbakker', isTerminal: false }
		const wrapper = await mountPane([[THIRD]])

		const claim = wrapper.find('[data-testid="case-task-pane-verb-claim"]')
		expect(claim.exists()).toBe(true)

		await claim.trigger('click')
		await flushPromises()

		expect(invoked[0]).toMatchObject({ uuid: 'task-3', verb: 'claim' })
	})

	it('shows the task reference and says when there is no number yet', async () => {
		const wrapper = await mountPane([[FIRST]])

		// A uuid is not a number, and presenting it as one is how a handler
		// quotes an identifier the engine has never heard of.
		expect(wrapper.find('[data-testid="case-task-pane-reference"]').text()).toContain(
			'task-1',
		)
		expect(wrapper.find('[data-testid="case-task-pane-lock"]').text()).not.toBe('')
	})
})
