// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The Dashboard shows your work once, not four times.
 *
 * What this pins down: `my-tasks` and `task-reminders` read `caseTask` with
 * the same `assignee: @me` + `isTerminalStatus: false` filter and differed
 * only in a due-date window, so seven of ten rows appeared on the page twice.
 * `overdue-cases` and `deadline-alerts` did the same to `case`. They are now
 * one `my-work` table and one `deadlines` table.
 *
 * The duplication test is mechanical rather than a list of retired ids. It
 * refuses any two tables over one schema whose filters nest AND whose sort key
 * is the same, because the narrower one is then the wider one's opening rows
 * shown again. A future tile added that way fails here, which is the point.
 * The predicate is proved able to say yes before the sweep asks it anything.
 *
 * @spec openspec/specs/dashboard/spec.md
 * @spec openspec/specs/signalering-widgets/spec.md
 */
import { describe, expect, it } from 'vitest'
import manifest from '../../src/manifest.json'

/**
 * The Dashboard page definition.
 *
 * @return {object} The manifest page whose id is `Dashboard`.
 */
export function dashboardPage() {
	return manifest.pages.find((p) => p.id === 'Dashboard')
}

/**
 * Every `object-table` widget on the Dashboard.
 *
 * `my-work` is deliberately NOT one of them since remove-casetask 2.3. Its
 * rows are engine tasks behind `/api/flow-tasks`, which have no register and
 * no schema, and `object-table` takes exactly those two. What that costs this
 * file is the duplication sweep over the task tile, and the sweep is over
 * `source.schema` so it had nothing to say about a widget with no source. The
 * tile's own contract moved to `myWorkWidget.spec.js`.
 *
 * @return {Array<object>} The widget entries, in manifest order.
 */
export function objectTables() {
	return dashboardPage().config.widgets.filter((w) => w.type === 'object-table')
}

/**
 * One Dashboard widget by id, whatever its type.
 *
 * @param {string} id The manifest widget id.
 * @return {object|undefined} The widget entry.
 */
export function widgetById(id) {
	return dashboardPage().config.widgets.find((w) => w.id === id)
}

/**
 * Flatten a widget `source.filter` to comparable `key[op]=value` strings, the
 * same bracket grammar OpenRegister takes on the wire. An equality entry has
 * no operator, so it flattens to `key=value`.
 *
 * @param {object} filter A widget's `source.filter`.
 * @return {Set<string>} One entry per leaf condition.
 */
export function filterConditions(filter) {
	const out = new Set()
	for (const [key, value] of Object.entries(filter || {})) {
		if (value && typeof value === 'object' && !Array.isArray(value)) {
			for (const [op, v] of Object.entries(value))
				out.add(`${key}[${op}]=${v}`)
		} else {
			out.add(`${key}=${value}`)
		}
	}
	return out
}

/**
 * Whether one condition set contains the other, in either direction.
 *
 * @param {Set<string>} a The first table's conditions.
 * @param {Set<string>} b The second table's conditions.
 * @return {boolean} True when either set contains the other.
 */
export function oneContainsTheOther(a, b) {
	const contains = (outer, inner) => [...inner].every((c) => outer.has(c))
	return contains(a, b) || contains(b, a)
}

/**
 * Whether two `object-table` sources are the same list shown twice.
 *
 * Containment on its own is not the fault: `open-cases` is every open case, so
 * every other `case` table sits inside it by design, and the page is right to
 * carry both a general list and a signal. What made `my-tasks` and
 * `task-reminders` duplicates is that they were also SORTED the same way, so
 * the narrower tile was the wider one's opening rows, re-rendered. Same schema
 * plus nested filters plus one sort key is the shape to refuse.
 *
 * @param {object} a The first widget's `content.source`.
 * @param {object} b The second widget's `content.source`.
 * @return {boolean} True when the two tables show one list twice.
 */
export function duplicatesAnother(a, b) {
	if (a.schema !== b.schema) return false
	if (
		!oneContainsTheOther(filterConditions(a.filter), filterConditions(b.filter))
	) {
		return false
	}
	const sortKey = (src) => Object.keys(src.order || {})[0] || null
	return sortKey(a) !== null && sortKey(a) === sortKey(b)
}

/**
 * The layout cells that place one widget.
 *
 * @param {string} widgetId The widget id.
 * @return {Array<object>} Matching layout entries.
 */
export function cellsFor(widgetId) {
	return dashboardPage().config.layout.filter((c) => c.widgetId === widgetId)
}

describe('dashboard work tables', () => {
	it('shows one My work table and one Deadlines table', () => {
		const ids = dashboardPage().config.widgets.map((w) => w.id)
		expect(ids).toContain('my-work')
		expect(ids).toContain('deadlines')
		// The four tiles these two replace are gone, not merely hidden.
		for (const retired of [
			'my-tasks',
			'task-reminders',
			'overdue-cases',
			'deadline-alerts',
		]) {
			expect(ids, `retired widget ${retired}`).not.toContain(retired)
		}
		// Exactly one of each, counted over the whole page rather than over
		// one widget type: since `my-work` stopped being an `object-table`, a
		// count taken from `objectTables()` would not see a second copy of it
		// added back as a custom widget.
		expect(ids.filter((id) => id === 'my-work')).toHaveLength(1)
		expect(ids.filter((id) => id === 'deadlines')).toHaveLength(1)
	})

	it('recognises the shape the retired tiles had', () => {
		// The predicate has to be able to say yes, or the sweep below is a
		// loop that can only pass. These two are `my-tasks` and
		// `task-reminders` as they stood before this change.
		const myTasks = {
			schema: 'caseTask',
			filter: { assignee: '@me', isTerminalStatus: false },
			order: { dueDate: 'asc' },
		}
		const taskReminders = {
			schema: 'caseTask',
			filter: {
				assignee: '@me',
				isTerminalStatus: false,
				dueDate: { lte: '@today+3d' },
			},
			order: { dueDate: 'asc' },
		}
		expect(duplicatesAnother(myTasks, taskReminders)).toBe(true)
		// A general list beside a signal is not the fault: `open-cases` holds
		// every open case and sorts by start date, `deadlines` by deadline.
		expect(
			duplicatesAnother(
				{
					schema: 'case',
					filter: { isFinalStatus: false },
					order: { startDate: 'desc' },
				},
				{
					schema: 'case',
					filter: { isFinalStatus: false, deadline: { lte: '@today+3d' } },
					order: { deadline: 'asc' },
				},
			),
		).toBe(false)
	})

	it('never lists the same rows in two tables', () => {
		const tables = objectTables()
		for (let i = 0; i < tables.length; i++) {
			for (let j = i + 1; j < tables.length; j++) {
				const a = tables[i]
				const b = tables[j]
				expect(
					duplicatesAnother(a.content.source, b.content.source),
					`${a.id} and ${b.id} read ${a.content.source.schema} the same way, so the page shows one list twice`,
				).toBe(false)
			}
		}
	})

	it('my-work reads the task engine, and names no register or schema', () => {
		// remove-casetask 2.3. The tile used to be an `object-table` over
		// `register: dossiq, schema: caseTask`. That read answered 200 while
		// the store behind it had stopped being written, so the failure was
		// invisible: a full table of rows nothing updates.
		const w = widgetById('my-work')
		expect(w.type).toBe('custom')
		expect(
			Object.hasOwn(w.content, 'source'),
			'a custom widget declares no object source',
		).toBe(false)
		expect(JSON.stringify(w.content)).not.toContain('caseTask')
	})

	it('no Dashboard widget reads caseTask any more', () => {
		// The whole page, not just this tile: `content` is where a retired
		// slug survives a migration, and every other widget on the page is
		// declared the same way.
		for (const w of dashboardPage().config.widgets) {
			expect(
				JSON.stringify(w.content ?? {}),
				`widget ${w.id} still reads caseTask`,
			).not.toContain('caseTask')
		}
	})

	it('my-work resolves through the page slot, or it renders a placeholder', () => {
		// A `type: "custom"` widget with no `slots` entry renders the
		// "Widget not available" placeholder and reports nothing. The map has
		// to be a SIBLING of `config`: CnPageRenderer reads `page.slots`, and
		// one nested under `config` is accepted by the schema and never read.
		const page = dashboardPage()
		expect(page.slots).toBeTypeOf('object')
		expect(page.slots['widget-my-work']).toBe('MyWorkWidget')
		expect(
			Object.hasOwn(page.config, 'slots'),
			'slots must not be nested under config',
		).toBe(false)
	})

	it('my-work keeps the config the component reads', () => {
		// Every key here is read by MyWorkWidget.vue, and every key here is
		// what a generic widget takes back the day nextcloud-vue grows a task
		// source for widgets. A key dropped from the manifest turns the swap
		// back into a rewrite.
		const w = widgetById('my-work')
		expect(w.content.limit).toBe(10)
		expect(w.content.emptyText).toBe('You have no open tasks')
		expect(w.content.viewAllLabel).toBe('View all')
		expect(w.content.viewAllRoute.name).toBe('Tasks')
	})

	it('my-work opens the task, which is where Pick up and Complete live', () => {
		// Row actions are blocked on nextcloud-vue, so the interim is the row
		// route. `rowRoute` names a route, and the route name is the page id.
		const w = widgetById('my-work')
		expect(w.content.rowRoute).toBe('TaskDetail')
		// Absence, not an `undefined` value: `toBeUndefined()` and
		// `toBe(undefined)` both pass on a key that is present and set to
		// undefined, which is not the same claim.
		expect(
			Object.hasOwn(w.content, 'rowActions'),
		).toBe(false)
		const target = manifest.pages.find((p) => p.id === 'TaskDetail')
		expect(target, 'rowRoute must name a page that exists').toBeDefined()
	})

	it('deadlines covers everything past due and the next three days', () => {
		const w = objectTables().find((t) => t.id === 'deadlines')
		expect(w.content.source.schema).toBe('case')
		expect(w.content.source.filter).toEqual({
			isFinalStatus: false,
			deadline: { lte: '@today+3d' },
		})
		expect(w.content.source.order).toEqual({ deadline: 'asc' })
	})

	it('deadlines leaves closed cases out', () => {
		const w = objectTables().find((t) => t.id === 'deadlines')
		expect(w.content.source.filter.isFinalStatus).toBe(false)
	})

	it('a row past its deadline reads in the error colour', () => {
		const w = objectTables().find((t) => t.id === 'deadlines')
		expect(w.content.rowClass).toEqual([
			{
				when: { field: 'daysUntilDeadline', op: 'lt', value: 0 },
				class: 'cn-row--danger',
			},
		])
	})

	it('places each widget in one cell, and never two in the same cell', () => {
		// Every widget, not only the object-tables: `my-work` left that set
		// when it became a custom widget, and a tile placed twice renders
		// twice whatever its type.
		const page = dashboardPage()
		for (const w of page.config.widgets) {
			expect(cellsFor(w.id), `layout cells for ${w.id}`).toHaveLength(1)
		}
		const taken = new Map()
		for (const cell of page.config.layout) {
			for (let x = cell.gridX; x < cell.gridX + cell.gridWidth; x++) {
				for (let y = cell.gridY; y < cell.gridY + cell.gridHeight; y++) {
					const at = `${x},${y}`
					expect(
						taken.has(at),
						`${cell.widgetId} overlaps ${taken.get(at)} at ${at}`,
					).toBe(false)
					taken.set(at, cell.widgetId)
				}
			}
			expect(cell.gridX + cell.gridWidth).toBeLessThanOrEqual(12)
		}
	})

	it('every widget on the page is placed, and every cell names a widget', () => {
		const page = dashboardPage()
		const declared = new Set(page.config.widgets.map((w) => w.id))
		const placed = new Set(page.config.layout.map((c) => c.widgetId))
		expect([...declared].filter((id) => !placed.has(id))).toEqual([])
		expect([...placed].filter((id) => !declared.has(id))).toEqual([])
	})
})
