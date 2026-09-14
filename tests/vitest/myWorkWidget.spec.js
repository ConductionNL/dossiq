// @vitest-environment jsdom
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The My Work landing page's My work tile, over OpenRegister's task engine.
 *
 * dashboard-my-work-split (2026-09-13) moved this tile off the Dashboard page
 * onto the My Work landing page (`MyWorkHome`, route `/`).
 *
 * remove-casetask 2.3. The tile was a `type: "object-table"` widget reading
 * `register: dossiq, schema: caseTask`, and that read kept answering 200
 * after the engine took the writes over. A table of stale rows is the one
 * failure a screenshot cannot show and a status code cannot report, so what
 * is asserted here is the QUERY the tile issues and the NAMES it reads back,
 * not that something rendered.
 *
 * The engine's row is not `caseTask`'s row. Its identity is `uuid`, its
 * deadline is `dueAt`, its case is `objectUuid`, and `asTaskRow` in the
 * store is the ONE place those become `id`, `dueDate` and `case`. The stub
 * below applies it, exactly as `list()` does, so a tile that re-shaped rows
 * for itself would not be able to pass here.
 *
 * The rows used are the shape the live API returned on 2026-09-10, numeric
 * `id` and all, because a hand-rolled row agreeing with the reader is how
 * the last one of these went wrong.
 *
 * @spec openspec/specs/dashboard/spec.md
 * @spec openspec/specs/signalering-widgets/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const ROOT = path.resolve(__dirname, '../..')

/** Rows the stubbed engine store answers with, replaced per test. */
let rows = []
/** Every `list()` call, so the query can be asserted. */
let calls = []

const { asTaskRow } = await import('../../src/store/modules/engineTask.js')

/**
 * The store, stubbed at the HTTP boundary and not above it.
 *
 * 🔴 `list()` PUTS EVERY ROW THROUGH `asTaskRow`, AND SO DOES THIS. A stub
 * that answered raw engine rows would let the tile read `uuid`, `state` and
 * `dueAt` directly and still pass, which is the opposite of what this file
 * is for: the whole claim is that the mapping happens ONCE, in the store,
 * and the tile reads the register's names. Handing back unmapped rows would
 * make a tile that re-shaped them itself look correct.
 */
const storeStub = {
	error: null,
	async list(params) {
		calls.push(params)
		return rows.map(asTaskRow)
	},
}

vi.mock('../../src/store/modules/engineTask.js', async (importOriginal) => ({
	...(await importOriginal()),
	useEngineTaskStore: () => storeStub,
}))

const { default: MyWorkWidget } =
	await import('../../src/views/widgets/MyWorkWidget.vue')

const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'),
)
const registrySource = fs.readFileSync(path.join(ROOT, 'src/registry.js'), 'utf8')

/** The My Work landing page as the manifest declares it. */
const myWorkPage = manifest.pages.find((p) => p.id === 'MyWorkHome')
/** The tile's manifest entry, which is also the component's config. */
const myWork = myWorkPage.config.widgets.find((w) => w.id === 'my-work')

/**
 * One engine row, in the engine's own vocabulary.
 *
 * Every key here is one `/api/flow-tasks` really answers. `id` is the numeric
 * database row id and is present on purpose: reading it instead of `uuid` is
 * the defect this file exists to keep out, and a fixture without it cannot
 * catch that.
 *
 * @param {object} over Fields to override.
 * @return {object} The row.
 */
function engineRow(over = {}) {
	return {
		id: 153,
		uuid: '232e2433-26a5-45f9-a71f-d9a3f2cdfddf',
		title: 'Toets aan subsidieplafond',
		state: 'active',
		isTerminal: false,
		assignee: 'admin',
		dueAt: '2026-09-01T00:00:00+00:00',
		objectUuid: '86adb041-34ff-4829-a064-a202fb1cec0d',
		subject: null,
		overdue: true,
		daysUntilDue: null,
		daysOverdue: 9,
		...over,
	}
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

/** Every `$router.push` the tile made. */
let pushed = []

/**
 * Mount the tile over a list of engine rows.
 *
 * The widget is mounted with the manifest's OWN widget entry, not with a
 * hand-written config: the point of keeping `limit`, `rowRoute` and the rest
 * in the manifest is that the component reads them, and a fixture config
 * would let the two drift without a failure.
 *
 * @param {Array<object>} answer The rows the engine answers with.
 * @return {Promise<object>} The mounted wrapper, after its load settles.
 */
async function mountTile(answer) {
	rows = answer
	const wrapper = mount(MyWorkWidget, {
		props: { widget: myWork, item: { widgetId: 'my-work' } },
		global: {
			stubs: { RouterLink: RouterLinkStub },
			mocks: { $router: { push: (to) => pushed.push(to) } },
		},
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	calls = []
	pushed = []
	rows = []
})

describe('the engine row, as the store hands it to the tile', () => {
	/**
	 * These are properties of `asTaskRow`, asserted here on THIS file's
	 * fixture rather than trusted. `engineTaskStore.spec.js` owns the
	 * mapping; what is checked here is that the fixture really is the
	 * shape the mapping has to cope with, so the tile tests below are not
	 * running against a row that already agrees with the reader.
	 */
	it('is the raw shape the API answers, numeric id and all', () => {
		const raw = engineRow()
		// Absence, not an undefined value: `toBe(undefined)` passes on a key
		// that is present and set to undefined, which is a different claim.
		expect(raw).not.toHaveProperty('dueDate')
		expect(raw).not.toHaveProperty('status')
		expect(raw).not.toHaveProperty('case')
		expect(raw.id).toBe(153)
	})

	it('gains the register names, with uuid winning the identity', () => {
		const row = asTaskRow(engineRow())
		expect(row.id).toBe('232e2433-26a5-45f9-a71f-d9a3f2cdfddf')
		expect(row.status).toBe('active')
		expect(row.dueDate).toBe('2026-09-01T00:00:00+00:00')
		expect(row.case).toBe('86adb041-34ff-4829-a064-a202fb1cec0d')
	})
})

describe('the My work tile', () => {
	it('asks the engine for your open tasks, soonest due first', async () => {
		await mountTile([engineRow()])
		expect(calls).toHaveLength(1)
		expect(calls[0]).toEqual({
			// `assigned` is what makes it YOUR work. The engine compares
			// against the session, so no `@me` token is sent and the tile
			// cannot be widened into somebody else's queue by a query.
			scope: 'assigned',
			// Server-side, so a closed task is never fetched and then hidden.
			isTerminal: false,
			sort: 'dueAt',
			// From the manifest, which is where a generic widget reads it.
			limit: 10,
		})
	})

	it('takes its limit from the manifest, not from a literal', async () => {
		// The manifest says 10 and the component's own fallback is also 10,
		// so asking for 10 proves nothing about where the number came from.
		// Mount over a DIFFERENT limit: only a component that really reads
		// `content.limit` can ask for 3.
		rows = []
		mount(MyWorkWidget, {
			props: {
				widget: { ...myWork, content: { ...myWork.content, limit: 3 } },
				item: { widgetId: 'my-work' },
			},
			global: {
				stubs: { RouterLink: RouterLinkStub },
				mocks: { $router: { push: () => {} } },
			},
		})
		await flushPromises()

		expect(calls[0].limit).toBe(3)
		// And the manifest still declares one, so the swap back to a generic
		// widget has a number to read.
		expect(myWork.content.limit).toBe(10)
	})

	it('shows a row per task, titled and with its distance to the deadline', async () => {
		const wrapper = await mountTile([
			engineRow(),
			engineRow({
				uuid: 'b2',
				title: 'Zienswijze beantwoorden',
				overdue: false,
				daysUntilDue: 4,
				daysOverdue: null,
			}),
		])
		const text = wrapper.text()
		expect(text).toContain('Toets aan subsidieplafond')
		expect(text).toContain('9 days overdue')
		expect(text).toContain('Zienswijze beantwoorden')
		expect(text).toContain('4 days remaining')
	})

	it('says so when a task has no deadline at all', async () => {
		const wrapper = await mountTile([
			engineRow({
				dueAt: null,
				overdue: false,
				daysUntilDue: null,
				daysOverdue: null,
			}),
		])
		expect(wrapper.text()).toContain('No deadline')
	})

	it('reads a row past its deadline in the error colour, and only that row', async () => {
		const wrapper = await mountTile([
			engineRow(),
			engineRow({
				uuid: 'b2',
				title: 'Later',
				overdue: false,
				daysUntilDue: 4,
				daysOverdue: null,
			}),
		])
		const danger = wrapper.findAll('.cn-row--danger')
		expect(danger).toHaveLength(1)
		expect(danger[0].text()).toContain('Toets aan subsidieplafond')
	})

	it('opens the clicked task by its uuid, which is what the route accepts', async () => {
		const wrapper = await mountTile([engineRow()])
		await wrapper.findAll('tbody tr')[0].trigger('click')
		expect(pushed).toEqual([
			{
				name: 'TaskDetail',
				params: { id: '232e2433-26a5-45f9-a71f-d9a3f2cdfddf' },
			},
		])
	})

	it('says you have nothing open rather than showing an empty box', async () => {
		const wrapper = await mountTile([])
		expect(wrapper.text()).toContain('You have no open tasks')
	})

	it('says the read failed rather than saying your queue is clear', async () => {
		// The store surfaces a failed read on `error` and answers an empty
		// list, so a tile that only knows how to say "no open tasks" tells
		// somebody with eighteen open tasks that they have none.
		storeStub.error = 'Request failed with status code 500'
		const wrapper = await mountTile([])
		expect(wrapper.text()).toContain('Could not load your tasks')
		expect(wrapper.text()).toContain('500')
		expect(wrapper.text()).not.toContain('You have no open tasks')
		storeStub.error = null
	})

	it('drops a terminal row the engine let through', async () => {
		// The filter is applied server-side AND on the rows. A store that
		// fails answers an empty list instead of throwing, and a filter the
		// server silently ignored would otherwise put a finished task on a
		// tile whose whole claim is that it lists open work.
		const wrapper = await mountTile([
			engineRow({ state: 'completed', isTerminal: true }),
		])
		expect(wrapper.text()).not.toContain('Toets aan subsidieplafond')
	})

	it('links View all at the whole route object the manifest names', async () => {
		const wrapper = await mountTile([engineRow()])
		const link = wrapper.find('.cn-data-table__view-all')
		expect(link.exists()).toBe(true)
		expect(JSON.parse(link.attributes('data-to'))).toEqual(
			myWork.content.viewAllRoute,
		)
		expect(link.text()).toBe('View all')
	})

	it('carries no query, because the Tasks index cannot read one', async () => {
		// It used to carry `assignee: @me` plus `isTerminalStatus: false`.
		// remove-casetask 2.2 put the index on `entitySource: "tasks"`, and
		// `useNamedSource.loadActive()` merges the page's `sourceConfig` with
		// the active quick-filter tab and never reads `$route.query`;
		// CnIndexPage's query watcher is guarded on `isSelfFetchMode`, which
		// a named source is not. The query was therefore ignored, and a
		// widget claiming a filter nothing applies is the same
		// looks-fine-does-nothing failure this whole change is about.
		expect(myWork.content.viewAllRoute).not.toHaveProperty('query')

		const wrapper = await mountTile([engineRow()])
		const to = JSON.parse(
			wrapper.find('.cn-data-table__view-all').attributes('data-to'),
		)
		expect(to).not.toHaveProperty('query')
		expect(to.name).toBe('Tasks')
	})
})

describe('the manifest-to-registry chain the tile hangs on', () => {
	it('declares the widget custom, because no built-in can address the engine', () => {
		expect(myWork.type).toBe('custom')
		expect(Object.hasOwn(myWork.content, 'source')).toBe(false)
	})

	it('maps the page slot beside config, where CnPageRenderer reads it', () => {
		// Under `config` it is accepted by the schema and never read, and the
		// widget renders the "Widget not available" placeholder in silence.
		expect(myWorkPage.slots['widget-my-work']).toBe('MyWorkWidget')
		expect(Object.hasOwn(myWorkPage.config, 'slots')).toBe(false)
	})

	it('answers that slot name with a registry entry', () => {
		expect(registrySource).toMatch(/^\tMyWorkWidget: \{$/m)
		expect(registrySource).toContain('component: MyWorkWidget,')
	})

	it('carries a _note and a reason-bearing ratchet exclusion', () => {
		// Gate 29 (custom-widget-ratchet) fails a kind:"widget" entry with no
		// `_note`, and fails the app when the count grows without a reason.
		const entry = registrySource
			.slice(registrySource.indexOf('\tMyWorkWidget: {'))
			.split('\n\t},')[0]
		expect(entry).toContain("kind: 'widget'")
		expect(entry).toContain('_note:')
		expect(entry).toMatch(/@custom-widget-ratchet exclude \S+ \S+/)
		expect(entry).toContain('no register and no schema')
	})

	it('imports the component from a file that exists', () => {
		const match = registrySource.match(/^import MyWorkWidget from '(.+)'$/m)
		expect(match, 'MyWorkWidget must be imported').not.toBeNull()
		expect(
			fs.existsSync(path.join(ROOT, 'src', match[1].replace(/^\.\//, ''))),
		).toBe(true)
	})
})
