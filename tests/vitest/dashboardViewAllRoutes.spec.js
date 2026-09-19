// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Every dashboard table's "View all" must reproduce the table's own filter.
 *
 * The defect this pins down: the Overdue tile listed seven overdue cases
 * and its View all opened `/cases` unfiltered, because `viewAllRoute` was
 * `{ name: "Cases" }` with no `query`. CnWidgetObjectTable forwards the
 * route verbatim and derives nothing from `source.filter`; CnIndexPage on
 * the other side merges every non-underscore query key into its fetch and
 * resolves `@today` / `@me` tokens there, so the query IS the filter.
 *
 * The check is mechanical rather than a literal per-widget list: the
 * widget's `source.filter` is flattened to OpenRegister's bracket grammar
 * (`{ deadline: { lt: "@today" } }` becomes `deadline[lt]=@today`) and every
 * resulting pair must appear in the query. A new table added with a bare
 * `viewAllRoute` fails here, which is the point.
 *
 * dashboard-my-work-split (2026-09-13) moved `my-work` / `deadlines` /
 * `open-cases` off the Dashboard page onto the My Work landing page
 * (`MyWorkHome`, route `/`). The invariant this file checks is a property of
 * the widget, not of which page hosts it, so both pages are read here rather
 * than only `Dashboard`.
 *
 * @spec openspec/specs/dashboard/spec.md
 * @spec openspec/specs/my-work-landing/spec.md
 */
import { describe, expect, it } from 'vitest'
import manifest from '../../src/manifest.json'

/**
 * Flatten an object-table `source.filter` to `key[op]=value` pairs.
 *
 * @param {object} filter The widget's `source.filter`.
 * @return {Record<string, string>} Bracket-grammar pairs, values stringified.
 */
function flattenFilter(filter) {
	const out = {}
	for (const [key, value] of Object.entries(filter || {})) {
		if (value && typeof value === 'object' && !Array.isArray(value)) {
			for (const [op, v] of Object.entries(value)) {
				out[`${key}[${op}]`] = String(v)
			}
		} else {
			out[key] = String(value)
		}
	}
	return out
}

const dashboardPageIds = ['Dashboard', 'MyWorkHome']
const dashboardWidgets = manifest.pages
	.filter((p) => dashboardPageIds.includes(p.id))
	.flatMap((p) => p.config.widgets)
const tables = dashboardWidgets.filter(
	(w) => w.type === 'object-table' && w.content && w.content.viewAllRoute,
)

describe('dashboard object-table viewAllRoute', () => {
	it('has tables to check', () => {
		// Four after `dashboard-tiles` merged `my-tasks` + `task-reminders`
		// into `my-work` and `overdue-cases` + `deadline-alerts` into
		// `deadlines`. Three since remove-casetask 2.3 moved `my-work` onto
		// the task engine: an engine task has no register and no schema, so
		// the tile is no longer an `object-table` and this file's premise,
		// that the route query reproduces `source.filter`, does not apply to
		// it. Its own View all is asserted in `dashboardWorkTables.spec.js`.
		// The floor guards against the list emptying out and this file
		// passing over nothing at all.
		expect(tables.length).toBeGreaterThanOrEqual(3)
		expect(tables.map((w) => w.id)).not.toContain('my-work')
	})

	for (const widget of tables) {
		it(`${widget.id}: View all carries the table's filter as route query`, () => {
			const route = widget.content.viewAllRoute
			expect(route, 'viewAllRoute must be an object route').toBeTypeOf(
				'object',
			)
			expect(route.query, 'viewAllRoute.query').toBeTypeOf('object')
			const expected = flattenFilter(widget.content.source.filter)
			for (const [key, value] of Object.entries(expected)) {
				expect(route.query[key], `${widget.id} query.${key}`).toBe(value)
			}
		})
	}

	it('stalled-cases: View all carries the inactivity sort, which is its only filter', () => {
		const widget = tables.find((w) => w.id === 'stalled-cases')
		const order = JSON.parse(widget.content.viewAllRoute.query._order)
		const [[key, dir]] = Object.entries(widget.content.source.order)
		expect(order).toEqual([{ key, order: dir }])
	})

	it('uses only string values, because the query is a URL', () => {
		for (const widget of tables) {
			for (const [key, value] of Object.entries(
				widget.content.viewAllRoute.query,
			)) {
				expect(typeof value, `${widget.id} query.${key}`).toBe('string')
			}
		}
	})
})
