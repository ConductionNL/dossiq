// @vitest-environment jsdom
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The My tasks tile's opt-in checkbox list, after the DqDashboard board.
 *
 * The simple profile's dashboard declares `content.variant: "list"` on
 * `simple-my-tasks`; nothing else does. What is asserted here:
 *
 * - THE FULL PROFILE IS UNCHANGED. No widget in src/manifest.json declares
 *   the variant, and the full profile's own entry still draws the table.
 * - The list draws a checkbox, the task, its case and the due date per row.
 * - Ticking completes the task through `useEngineTaskStore.invoke(uuid,
 *   'complete')`, the call the task page makes, and a refusal unticks the box
 *   and shows the engine's own message.
 *
 * @spec openspec/specs/dashboard/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { pageWidgets } from './helpers/pageViews.js'

const ROOT = path.resolve(__dirname, '../..')

let rows = []
let calls = []
let invoked = []
/** What the stubbed `invoke` answers: a task, or null for a refusal. */
let invokeAnswer = null

const { asTaskRow } = await import('../../src/store/modules/engineTask.js')

const storeStub = {
	error: null,
	async list(params) {
		calls.push(params)
		return rows.map(asTaskRow)
	},
	async invoke(uuid, verb, body) {
		invoked.push({ uuid, verb, body })
		return invokeAnswer
	},
}

vi.mock('../../src/store/modules/engineTask.js', async (importOriginal) => ({
	...(await importOriginal()),
	useEngineTaskStore: () => storeStub,
}))

vi.mock('../../src/services/substitutionApi.js', () => ({
	fetchSubstitutedWork: async () => ({ cases: [], tasks: [] }),
}))

const showError = vi.fn()
const showSuccess = vi.fn()
vi.mock('@nextcloud/dialogs', () => ({ showError, showSuccess }))

const { default: MyWorkWidget } =
	await import('../../src/views/widgets/MyWorkWidget.vue')

const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'),
)
const simpleLayout = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src/menu-layout.simple.json'), 'utf8'),
)

/** The full profile's own tile: the My work page's `my-work` widget. */
const fullTile = pageWidgets(manifest.pages.find((p) => p.id === 'MyWorkHome')).find(
	(w) => w.id === 'my-work',
)

/**
 * Every widget entry in a JSON tree, wherever it sits.
 *
 * @param {*} node A JSON node.
 * @param {Array<object>} found The accumulator.
 * @return {Array<object>} Every object with an `id` and a `type`.
 */
function widgetsIn(node, found = []) {
	if (Array.isArray(node)) {
		node.forEach((child) => widgetsIn(child, found))
	} else if (node && typeof node === 'object') {
		if (typeof node.id === 'string' && typeof node.type === 'string') {
			found.push(node)
		}
		Object.values(node).forEach((child) => widgetsIn(child, found))
	}
	return found
}

/** The simple dashboard's tile, as the layout declares it. */
const simpleTile = widgetsIn(simpleLayout).find((w) => w.id === 'simple-my-tasks')

/**
 * An ISO date a number of days from today, at noon local time.
 *
 * @param {number} days Days from today.
 * @return {string} The date.
 */
function inDays(days) {
	const date = new Date()
	date.setHours(12, 0, 0, 0)
	date.setDate(date.getDate() + days)
	return date.toISOString()
}

/**
 * One engine row, in the engine's own vocabulary.
 *
 * @param {object} over Fields to override.
 * @return {object} The row.
 */
function engineRow(over = {}) {
	return {
		id: 153,
		uuid: '232e2433-26a5-45f9-a71f-d9a3f2cdfddf',
		title: 'Documenten beoordelen op openbaarheid',
		state: 'active',
		isTerminal: false,
		dueAt: inDays(0),
		objectUuid: '86adb041-34ff-4829-a064-a202fb1cec0d',
		subject: { title: 'Verlichting fietspad Lindelaan' },
		daysUntilDue: 0,
		daysOverdue: null,
		...over,
	}
}

const RouterLinkStub = defineComponent({
	name: 'RouterLink',
	props: { to: { type: [String, Object], default: '' } },
	render() {
		return h('a', {}, this.$slots.default?.())
	},
})

let pushed = []

/**
 * Mount the tile with a manifest entry over a list of engine rows.
 *
 * @param {object} widget The widget entry.
 * @param {Array<object>} answer The rows the engine answers with.
 * @return {Promise<object>} The wrapper, after its load settles.
 */
async function mountTile(widget, answer) {
	rows = answer
	const wrapper = mount(MyWorkWidget, {
		props: { widget, item: { widgetId: widget.id } },
		global: {
			stubs: { RouterLink: RouterLinkStub },
			mocks: {
				$router: {
					push: (to) => pushed.push(to),
					resolve: (to) => ({
						href: `/apps/dossiq/tasks/${to.params.id}`,
					}),
				},
			},
		},
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	rows = []
	calls = []
	invoked = []
	pushed = []
	invokeAnswer = null
	storeStub.error = null
	showError.mockClear()
	showSuccess.mockClear()
})

describe('the full profile is unchanged', () => {
	it('declares the list variant on no widget of the full manifest', () => {
		const declaring = widgetsIn(manifest).filter(
			(w) => w.content && 'variant' in w.content && w.type === 'custom',
		)
		expect(declaring.map((w) => w.id)).toEqual([])
		expect(fullTile.content).not.toHaveProperty('variant')
	})

	it('still draws the table for the full profile entry', async () => {
		const wrapper = await mountTile(fullTile, [engineRow()])
		expect(wrapper.find('[data-testid="my-work-task-list"]').exists()).toBe(
			false,
		)
		expect(wrapper.find('table').exists()).toBe(true)
		expect(wrapper.find('input[type="checkbox"]').exists()).toBe(false)
		expect(wrapper.text()).toContain('Due today')
	})
})

describe('the simple dashboard list', () => {
	it('is declared by the simple profile only, on the same tile', () => {
		expect(simpleTile.content.variant).toBe('list')
	})

	it('draws a checkbox, the task, its case and the due date per row', async () => {
		const wrapper = await mountTile(simpleTile, [
			engineRow(),
			engineRow({
				id: 154,
				uuid: 'b0b0b0b0-0000-4000-8000-000000000002',
				title: 'Inwoner bellen over ontbrekend ID-bewijs',
				subject: { title: 'Parkeervergunningen binnenstad' },
				dueAt: inDays(1),
				daysUntilDue: 1,
			}),
			engineRow({
				id: 155,
				uuid: 'b0b0b0b0-0000-4000-8000-000000000003',
				title: 'Hoorzitting inplannen',
				subject: null,
				dueAt: inDays(-3),
				daysUntilDue: null,
				daysOverdue: 3,
			}),
		])

		expect(wrapper.find('table').exists()).toBe(false)
		const found = wrapper.findAll('[data-testid="my-work-task-row"]')
		expect(found).toHaveLength(3)

		expect(found[0].find('input[type="checkbox"]').exists()).toBe(true)
		expect(found[0].find('.dossiq-task-list__title').text()).toBe(
			'Documenten beoordelen op openbaarheid',
		)
		expect(found[0].find('.dossiq-task-list__title').attributes('href')).toBe(
			'/apps/dossiq/tasks/232e2433-26a5-45f9-a71f-d9a3f2cdfddf',
		)
		expect(found[0].find('.dossiq-task-list__case').text()).toBe(
			'Verlichting fietspad Lindelaan',
		)
		expect(found[0].find('.dossiq-task-list__due').text()).toBe('Today')
		expect(found[0].find('.dossiq-task-list__due--urgent').exists()).toBe(true)

		expect(found[1].find('.dossiq-task-list__due').text()).toBe('Tomorrow')
		expect(found[1].find('.dossiq-task-list__due--urgent').exists()).toBe(false)

		// No case on the row: the second line stays empty, never a uuid.
		expect(found[2].find('.dossiq-task-list__case').text()).toBe('')
		expect(found[2].find('.dossiq-task-list__due').text()).toBe('3 days overdue')
		expect(found[2].find('.dossiq-task-list__due--urgent').exists()).toBe(true)
	})

	it('shows a day and a month for a deadline further out', async () => {
		const wrapper = await mountTile(simpleTile, [
			engineRow({ dueAt: inDays(5), daysUntilDue: 5 }),
		])
		const label = wrapper.find('.dossiq-task-list__due').text()
		expect(label).not.toBe('')
		expect(label).not.toContain('days')
		expect(label).toContain(String(new Date(inDays(5)).getDate()))
	})

	it('opens the task from its title, by uuid', async () => {
		const wrapper = await mountTile(simpleTile, [engineRow()])
		await wrapper.find('.dossiq-task-list__title').trigger('click')
		expect(pushed).toEqual([
			{
				name: 'TaskDetail',
				params: { id: '232e2433-26a5-45f9-a71f-d9a3f2cdfddf' },
			},
		])
	})

	it('completes a ticked task through the task page path and reads the list anew', async () => {
		const wrapper = await mountTile(simpleTile, [engineRow()])
		invokeAnswer = {
			uuid: '232e2433-26a5-45f9-a71f-d9a3f2cdfddf',
			state: 'completed',
		}
		rows = []

		const box = wrapper.find('[data-testid="my-work-task-check"]')
		box.element.checked = true
		await box.trigger('change')
		await flushPromises()

		expect(invoked).toEqual([
			{
				uuid: '232e2433-26a5-45f9-a71f-d9a3f2cdfddf',
				verb: 'complete',
				body: undefined,
			},
		])
		expect(showSuccess).toHaveBeenCalledTimes(1)
		expect(calls).toHaveLength(2)
		expect(wrapper.findAll('[data-testid="my-work-task-row"]')).toHaveLength(0)
	})

	it('unticks the box and shows the engine refusal when completion is refused', async () => {
		const wrapper = await mountTile(simpleTile, [engineRow()])
		invokeAnswer = null
		storeStub.error = 'Fill in Besluit before completing this task'

		const box = wrapper.find('[data-testid="my-work-task-check"]')
		box.element.checked = true
		await box.trigger('change')
		await flushPromises()

		expect(invoked).toHaveLength(1)
		expect(showError).toHaveBeenCalledWith(
			'Fill in Besluit before completing this task',
		)
		expect(showSuccess).not.toHaveBeenCalled()
		expect(box.element.checked).toBe(false)
		expect(wrapper.findAll('[data-testid="my-work-task-row"]')).toHaveLength(1)
	})
})
