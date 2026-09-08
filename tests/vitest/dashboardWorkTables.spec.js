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
 * @return {Array<object>} The widget entries, in manifest order.
 */
export function objectTables() {
	return dashboardPage().config.widgets.filter((w) => w.type === 'object-table')
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
		const ids = objectTables().map((w) => w.id)
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

	it('my-work reads your open tasks, soonest due first', () => {
		const w = objectTables().find((t) => t.id === 'my-work')
		expect(w.content.source.schema).toBe('caseTask')
		expect(w.content.source.filter).toEqual({
			assignee: '@me',
			isTerminalStatus: false,
		})
		expect(w.content.source.order).toEqual({ dueDate: 'asc' })
		expect(w.content.source.limit).toBe(10)
	})

	it('my-work names the case, not its uuid', () => {
		const w = objectTables().find((t) => t.id === 'my-work')
		// A $ref column renders the stored uuid unless the referenced object
		// is extended AND the column reads through to a label field.
		expect(w.content.source.extend).toContain('case')
		const keys = w.content.columns.map((c) => c.key)
		expect(keys).toEqual(['title', 'case.title', 'daysUntilDue'])
		expect(keys).not.toContain('case')
	})

	it('my-work shows days left on every row', () => {
		const w = objectTables().find((t) => t.id === 'my-work')
		expect(w.content.source.extend).toContain('calculations')
		const col = w.content.columns.find((c) => c.key === 'daysUntilDue')
		expect(col.formatter).toBe('conditionalPhrase')
		// All three branches, or a task due today reads as a bare number.
		expect(Object.keys(col.formatterOptions).sort()).toEqual([
			'negative',
			'positive',
			'zero',
		])
	})

	it('my-work opens the task, which is where Pick up and Complete live', () => {
		// rowActions is blocked on nextcloud-vue: the 2.40.0 object-table
		// vocabulary has no such key, so declaring it would render nothing and
		// report nothing. The interim is the row route.
		const w = objectTables().find((t) => t.id === 'my-work')
		expect(w.content.rowRoute).toBe('TaskDetail')
		expect(w.content.rowActions).toBeUndefined()
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

	it('places each table in one cell, and never two in the same cell', () => {
		const page = dashboardPage()
		for (const table of objectTables()) {
			expect(cellsFor(table.id), `layout cells for ${table.id}`).toHaveLength(
				1,
			)
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
