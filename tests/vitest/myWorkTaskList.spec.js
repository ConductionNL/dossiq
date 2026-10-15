// @vitest-environment jsdom
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The My tasks tile on the simple dashboard, after the DqDashboard board.
 *
 * The simple profile places the library's `tasks` widget with
 * `variant: "list"` as `simple-my-tasks`. The full profile keeps the app's
 * own MyWorkWidget table (with the substitution toggle). What is asserted:
 *
 * - THE FULL PROFILE IS UNCHANGED: no widget in src/manifest.json declares
 *   the variant, and its entry still draws the table.
 * - The simple profile's tile is the library widget, and the app tile has
 *   no list branch left.
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
	it('is the library tasks widget in its list variant, not the app tile', () => {
		// The board's checkbox list is nextcloud-vue's `tasks` widget with
		// `variant: list` (2.81.0). The completion, the case line and the due
		// date are tested there; this asserts only that the simple profile
		// places it and that the app tile no longer carries a list.
		expect(simpleTile.type).toBe('tasks')
		expect(simpleTile.content).toMatchObject({
			scope: 'assigned',
			variant: 'list',
			rowRoute: 'TaskDetail',
			viewAllRoute: { name: 'Tasks' },
			viewAllLabel: 'View all',
		})
		const source = fs.readFileSync(
			path.join(ROOT, 'src/views/widgets/MyWorkWidget.vue'),
			'utf8',
		)
		expect(source).not.toContain('dossiq-task-list')
		expect(source).not.toContain("'list'")
	})
})
