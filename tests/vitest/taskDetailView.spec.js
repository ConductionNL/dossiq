// @vitest-environment jsdom
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The task page over the engine (remove-casetask 2.1).
 *
 * What the page promises is not a render, it is a set of behaviours that the
 * detail page it replaces got wrong and nothing caught:
 *
 *   - a field the task does not carry is ABSENT, not an em dash. The Data
 *     widget rendered every declared property, so an engine uuid on the
 *     object store produced a full grid of em dashes and read as a broken
 *     record rather than as a read from the wrong store;
 *   - the lifecycle buttons invoke the ENGINE's verbs and are offered from
 *     the row, so a task nobody holds offers `claim` and a finished task
 *     offers nothing;
 *   - a refused verb keeps the task where it was and reports the engine's
 *     own message, which names the verb and the reason;
 *   - a task the engine will not answer for says so, rather than rendering
 *     an empty record.
 *
 * None of that is reachable from the manifest assertions in
 * manifestCaseTaskPane.spec.js, and the e2e cannot reach the refusal path
 * because its user is admin.
 *
 * @spec openspec/specs/task-management/spec.md
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

/** What `fetch()` answers with, replaced per test. */
let fetched = null
/** What `invoke()` answers with, replaced per test. */
let invokeResult = null
/** Every verb the page asked for. */
let invoked = []
/** Every `checkItem()` call. */
let checked = []

const storeStub = {
	error: null,
	async fetch() {
		return fetched
	},
	async invoke(uuid, verb) {
		invoked.push({ uuid, verb })
		return invokeResult
	},
	async checkItem(uuid, itemId, value) {
		checked.push({ uuid, itemId, value })
		return invokeResult
	},
	async readLeaf() {
		return { results: [], error: null }
	},
}

vi.mock('../../src/store/modules/engineTask.js', async (importOriginal) => ({
	...(await importOriginal()),
	useEngineTaskStore: () => storeStub,
}))

const { default: TaskDetailView } =
	await import('../../src/views/tasks/TaskDetailView.vue')

/** A task the engine would answer with, in the engine's own vocabulary. */
const TASK = {
	uuid: 'task-1',
	title: 'Check the application',
	description: 'Read the drawings and call the applicant.',
	state: 'active',
	isTerminal: false,
	assignee: 'hbakker',
	candidateGroups: ['vergunningen', 'toezicht'],
	dueAt: '2026-09-20T00:00:00+00:00',
	priority: 'high',
	objectUuid: 'case-9',
}

/**
 * Mount the page over one task.
 *
 * The children that fetch for themselves are stubbed: each has its own spec,
 * and leaving them live would make this file assert three more endpoints.
 *
 * @param {object|null} task The task the store answers with.
 * @return {Promise<object>} The mounted wrapper, after its load settles.
 */
async function mountPage(task) {
	fetched = task
	const wrapper = mount(TaskDetailView, {
		props: { id: 'task-1' },
		global: {
			stubs: {
				RouterLink: true,
				// The page names each badge with its own data-testid, which
				// falls through to this span with the variant beside it.
				CnStatusBadge: defineComponent({
					name: 'CnStatusBadge',
					props: {
						label: { type: String, default: '' },
						variant: { type: String, default: 'default' },
					},
					render() {
						return h(
							'span',
							{ 'data-variant': this.variant },
							this.label,
						)
					},
				}),
				TaskWaitingCaseSection: true,
				TaskNotesLeaf: true,
				TaskEventsLeaf: true,
				TaskAuditLeaf: true,
			},
		},
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	showSuccess.mockClear()
	showError.mockClear()
	invoked = []
	checked = []
	invokeResult = null
	storeStub.error = null
})

describe('TaskDetailView', () => {
	it('renders the task the engine answered with, its state in the words of the reader', async () => {
		const wrapper = await mountPage(TASK)

		expect(wrapper.find('[data-testid="task-detail-title"]').text()).toBe(
			'Check the application',
		)
		expect(wrapper.find('[data-testid="task-detail-state"]').text()).toBe(
			'In execution',
		)
		// DqTaak: the active state in the work-in-progress purple, and a
		// "Hoog" pill beside it for a high-priority task.
		expect(
			wrapper
				.find('[data-testid="task-detail-state"]')
				.attributes('data-variant'),
		).toBe('purple')
		const priority = wrapper.find('[data-testid="task-detail-priority"]')
		expect(priority.text()).toBe('High')
		expect(priority.attributes('data-variant')).toBe('warning')
		expect(wrapper.find('[data-testid="cn-fact-assignee"] dd').text()).toBe(
			'hbakker',
		)
		// `dueAt`, the engine's spelling. The detail page read `dueDate` and
		// found nothing on every engine row.
		expect(wrapper.find('[data-testid="cn-fact-due"]').exists()).toBe(true)
	})

	it('draws no priority pill for a task of normal priority, and a finished task in green', async () => {
		const wrapper = await mountPage({
			...TASK,
			priority: 'normal',
			state: 'completed',
			isTerminal: true,
		})

		expect(wrapper.find('[data-testid="task-detail-priority"]').exists()).toBe(
			false,
		)
		expect(
			wrapper
				.find('[data-testid="task-detail-state"]')
				.attributes('data-variant'),
		).toBe('success')
	})

	it('draws the case card for a task on a case, naming case type and status by reference, never by id', async () => {
		const wrapper = await mountPage(TASK)
		expect(wrapper.find('[data-testid="task-case-card"]').exists()).toBe(true)
		const content = wrapper
			.findAllComponents({ name: 'CnFactsWidget' })
			.at(1)
			.props('content')
		expect(content.source).toEqual({
			register: 'dossiq',
			schema: 'case',
			id: '@object.objectUuid',
		})
		const meta = content.fields.find((f) => f.key === 'case-meta')
		expect(meta.parts[1].resolve).toMatchObject({ schema: 'caseType' })
		const status = content.fields.find((f) => f.key === 'case-status')
		expect(status.resolve).toMatchObject({
			schema: 'statusType',
			labelField: 'name',
		})
	})

	it('names the priority in words, the handler as Handler, and the way back as To the case', async () => {
		// DqTaak: "Prioriteit Hoog", "Behandelaar", "Naar de zaak". The engine
		// value "high" stays the value; only the label is worded.
		const wrapper = await mountPage(TASK)
		const facts = wrapper.findAllComponents({ name: 'CnFactsWidget' })
		const task = facts.at(0).props('content').fields
		expect(task.find((f) => f.key === 'priority').map).toEqual({
			low: 'Low',
			normal: 'Normal',
			high: 'High',
			urgent: 'Urgent',
		})
		expect(task.find((f) => f.key === 'assignee').label).toBe('Handler')
		expect(task.find((f) => f.key === 'due').label).toBe('Due by')
		expect(facts.at(1).props('content').link.label).toBe('To the case')
	})

	it('draws no case card for a task with no case', async () => {
		const wrapper = await mountPage({
			uuid: 'task-1',
			title: 'Ad hoc',
			state: 'available',
			isTerminal: false,
		})
		expect(wrapper.find('[data-testid="task-case-card"]').exists()).toBe(false)
	})

	it('names every team the task is offered to, in one row', async () => {
		// `candidateGroups` is a LIST on the engine where `caseTask` had one
		// `assignedGroup`, so a task offered to two teams has to say both.
		const wrapper = await mountPage(TASK)

		expect(wrapper.find('[data-testid="cn-fact-team"] dd').text()).toBe(
			'vergunningen, toezicht',
		)
	})

	it('omits a fact the task does not carry, rather than showing an em dash', async () => {
		// THE defect. The Data widget rendered every declared property, so a
		// task read from the wrong store showed a full grid of em dashes and
		// looked like broken data.
		const wrapper = await mountPage({
			uuid: 'task-1',
			title: 'Bare task',
			state: 'available',
			isTerminal: false,
		})

		expect(wrapper.find('[data-testid="cn-fact-due"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="cn-fact-team"]').exists()).toBe(false)
		expect(
			wrapper.find('[data-testid="task-detail-body"]').text(),
		).not.toContain('—')
	})

	it('offers hand back on a held task and pick up on an unheld one', async () => {
		const held = await mountPage(TASK)
		expect(held.find('[data-testid="task-detail-verb-unclaim"]').exists()).toBe(
			true,
		)
		expect(held.find('[data-testid="task-detail-verb-claim"]').exists()).toBe(
			false,
		)

		const free = await mountPage({ ...TASK, assignee: '' })
		expect(free.find('[data-testid="task-detail-verb-claim"]').exists()).toBe(
			true,
		)
		expect(free.find('[data-testid="task-detail-verb-unclaim"]').exists()).toBe(
			false,
		)
	})

	it('offers no verb at all on a finished task', async () => {
		// Every verb on a terminal task would be refused, and a row of
		// buttons that all fail is worse than no row.
		const wrapper = await mountPage({
			...TASK,
			state: 'completed',
			isTerminal: true,
		})

		expect(wrapper.find('[data-testid="task-detail-actions"]').exists()).toBe(
			false,
		)
	})

	it('invokes the engine verb and confirms a finished task by name', async () => {
		invokeResult = { ...TASK, state: 'completed', isTerminal: true }
		const wrapper = await mountPage(TASK)

		await wrapper
			.find('[data-testid="task-detail-verb-complete"]')
			.trigger('click')
		await flushPromises()

		// The ENGINE's verb on the ENGINE's uuid, not an object transition.
		expect(invoked).toEqual([{ uuid: 'task-1', verb: 'complete' }])
		expect(showSuccess).toHaveBeenCalledTimes(1)
		expect(showSuccess.mock.calls[0][0]).toContain('Check the application')
		// The reply replaces the row, so the buttons go with the state.
		expect(wrapper.find('[data-testid="task-detail-actions"]').exists()).toBe(
			false,
		)
	})

	it('keeps the task and reports the engine message when a verb is refused', async () => {
		invokeResult = null
		storeStub.error = 'You are not the assignee of this task.'
		const wrapper = await mountPage(TASK)

		await wrapper
			.find('[data-testid="task-detail-verb-complete"]')
			.trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalledWith(
			'You are not the assignee of this task.',
		)
		expect(showSuccess).not.toHaveBeenCalled()
		// The task is still on screen, still active, still actionable.
		expect(wrapper.find('[data-testid="task-detail-state"]').text()).toBe(
			'In execution',
		)
		expect(
			wrapper.find('[data-testid="task-detail-verb-complete"]').exists(),
		).toBe(true)
	})

	it('says a task it cannot read is not there, rather than rendering nothing', async () => {
		// The engine answers 404 both for a task that is absent and for one
		// the caller may not read, on purpose. An empty page for either is
		// indistinguishable from a page that failed to load.
		const wrapper = await mountPage(null)

		expect(wrapper.find('[data-testid="task-detail-missing"]').exists()).toBe(
			true,
		)
		expect(wrapper.find('[data-testid="task-detail-body"]').exists()).toBe(false)
	})

	it('ticks a checklist item by its id, and drops an item that has none', async () => {
		invokeResult = {
			...TASK,
			checklist: [{ id: 'a', label: 'Drawings', checked: true }],
		}
		const wrapper = await mountPage({
			...TASK,
			checklist: [
				{ id: 'a', label: 'Drawings', checked: false },
				// No id: the PATCH route addresses an item by id, so a control
				// for this one would silently do nothing.
				{ label: 'Orphan', checked: false },
			],
		})

		const boxes = wrapper.findAll('[data-testid^="task-detail-check-"]')
		expect(boxes).toHaveLength(1)

		// NcCheckboxRadioSwitch puts a fallthrough attribute on its INPUT,
		// not on its wrapper span, so the testid addresses the control
		// directly. Verified against the rendered markup.
		await wrapper.find('[data-testid="task-detail-check-a"]').setValue(true)
		await flushPromises()

		expect(checked).toEqual([{ uuid: 'task-1', itemId: 'a', value: true }])
	})
})
