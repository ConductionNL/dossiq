/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The lenses, the deadline column and the bulk actions on the two index
 * pages, as the manifest declares them.
 *
 * Three of these assertions exist because the failure they catch is silent.
 *
 * A `quickFilters` list ACTIVATES A CHIP ON MOUNT — the one marked
 * `default`, and the FIRST one when none is marked. So a page that ships a
 * Mine chip without an All chip marked `default` narrows every reader's
 * first paint to their own rows, and an empty result reads as an empty
 * register rather than as a filter. The default assertion is the guard.
 *
 * The Unclaimed chip and the Queue page's base filter are the same two
 * conditions written twice. Nothing at runtime compares them, so they drift
 * the moment one side is edited; the deep-equal here is what makes "the two
 * lists agree" a fact rather than an intention.
 *
 * An operator filter is spelled `deadline[lt]`, a FLAT key. The nested
 * `{ deadline: { lt: '@today' } }` form is what a reader reaches for, and
 * `buildQueryString` JSON-stringifies a nested object value — the API then
 * receives the literal string `{"lt":"@today"}` and matches nothing, with no
 * error anywhere. The shape assertion is the only place that shows.
 *
 * @spec openspec/changes/one-case-list/specs/my-work/spec.md
 * @spec openspec/changes/one-case-list/specs/task-management/spec.md
 * @spec openspec/changes/one-case-list/specs/case-management/spec.md
 * @spec openspec/changes/one-case-list/specs/signalering-widgets/spec.md
 * @spec openspec/changes/one-case-list/specs/case-bulk-status-transition/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const MANIFEST_PATH = path.join(ROOT, 'src', 'manifest.json')

const CELL_WIDGETS_PATH = path.join(ROOT, 'src', 'services', 'cellWidgets.js')

const manifest = JSON.parse(fs.readFileSync(MANIFEST_PATH, 'utf8'))
const cellWidgetsSource = fs.readFileSync(CELL_WIDGETS_PATH, 'utf8')
const iconsSource = fs.readFileSync(path.join(ROOT, 'src', 'icons.js'), 'utf8')
const customComponentsSource = fs.readFileSync(
	path.join(ROOT, 'src', 'customComponents.js'),
	'utf8',
)

/**
 * One page as the manifest declares it.
 *
 * @param {string} id The manifest page id.
 * @return {object} The page entry.
 */
const page = (id) => manifest.pages.find((entry) => entry.id === id)

/**
 * The quick-filter chips of one index page.
 *
 * @param {string} id The manifest page id.
 * @return {Array<object>} The chips, in declaration order.
 */
const chips = (id) => page(id).config.quickFilters

/**
 * One chip by its label.
 *
 * @param {string} id The manifest page id.
 * @param {string} label The chip label.
 * @return {object|undefined} The chip entry.
 */
const chip = (id, label) => chips(id).find((entry) => entry.label === label)

describe('Cases index lenses', () => {
	it('declares the five chips in order', () => {
		expect(chips('Cases').map((entry) => entry.label)).toEqual([
			'All',
			'Mine',
			'Unclaimed',
			'Closed',
			'Overdue',
		])
	})

	it('marks All as the default chip and nothing else', () => {
		const defaults = chips('Cases').filter((entry) => entry.default === true)
		expect(defaults).toHaveLength(1)
		expect(defaults[0].label).toBe('All')
		expect(defaults[0].filter).toEqual({})
	})

	it('keeps closed cases out of Mine and Unclaimed', () => {
		expect(chip('Cases', 'Mine').filter.isFinalStatus).toBe(false)
		expect(chip('Cases', 'Unclaimed').filter.isFinalStatus).toBe(false)
	})

	it('scopes Mine to the signed-in user through @me', () => {
		expect(chip('Cases', 'Mine').filter.assignee).toBe('@me')
	})

	it('shows closed cases under Closed only', () => {
		expect(chip('Cases', 'Closed').filter).toEqual({ isFinalStatus: true })
	})

	it('gives Unclaimed the same filter as the Queue page', () => {
		expect(chip('Cases', 'Unclaimed').filter).toEqual(
			page('Queue').config.filter,
		)
	})

	it('spells the Overdue operator as a flat bracket key', () => {
		expect(chip('Cases', 'Overdue').filter).toEqual({
			isFinalStatus: false,
			'deadline[lt]': '@today',
		})
	})
})

describe('Tasks index lenses', () => {
	it('declares the same first three labels as Cases', () => {
		expect(chips('Tasks').map((entry) => entry.label)).toEqual([
			'All',
			'Mine',
			'Unclaimed',
		])
		expect(chips('Tasks').map((entry) => entry.label)).toEqual(
			chips('Cases')
				.slice(0, 3)
				.map((entry) => entry.label),
		)
	})

	it('marks All as the default chip', () => {
		const defaults = chips('Tasks').filter((entry) => entry.default === true)
		expect(defaults).toHaveLength(1)
		expect(defaults[0].label).toBe('All')
	})

	it('keeps completed tasks out of Mine and Unclaimed', () => {
		expect(chip('Tasks', 'Mine').filter).toEqual({
			assignee: '@me',
			isTerminalStatus: false,
		})
		expect(chip('Tasks', 'Unclaimed').filter).toEqual({
			assignee: 'IS NULL',
			isTerminalStatus: false,
		})
	})
})

/**
 * The dashboard widgets that link to the Cases page with a deadline filter.
 *
 * `dashboard-tiles` merged `overdue-cases` and `deadline-alerts` into one
 * `deadlines` table whose window is WIDER than the Overdue chip: everything
 * already past its deadline AND everything due within three days. So the two
 * are held to different standards, on purpose. `kpi-overdue` counts open
 * overdue cases and nothing else, so its link must reproduce the Overdue
 * chip exactly; `deadlines` must reproduce its OWN filter, which is what
 * makes its count and its View all agree.
 *
 * @return {object} The Dashboard page's widgets, by id.
 */
function dashboardWidgets() {
	const byId = {}
	for (const widget of page('Dashboard').config.widgets) {
		byId[widget.id] = widget
	}
	return byId
}

/**
 * One widget's route query, whichever key it carries the route under.
 *
 * @param {object} widget The dashboard widget.
 * @return {object} The route query.
 */
function routeQuery(widget) {
	const route = widget.content.route || widget.content.viewAllRoute
	return route.query
}

/**
 * A filter map with every value stringified, because a route query is a URL
 * and every value in one is a string — an invariant
 * `dashboardViewAllRoutes.spec.js` holds for the whole dashboard.
 *
 * @param {object} filter The filter map.
 * @return {object} The same map with string values.
 */
function asQuery(filter) {
	return Object.fromEntries(
		Object.entries(filter).map(([key, value]) => [key, String(value)]),
	)
}

describe('the deadline widgets and the Overdue chip', () => {
	it('the Overdue stat tile carries exactly the Overdue chip filter', () => {
		const tile = dashboardWidgets()['kpi-overdue']

		expect(tile.content.route.name).toBe('Cases')
		expect(routeQuery(tile)).toEqual(asQuery(chip('Cases', 'Overdue').filter))
	})

	it('the Deadlines table carries its own, wider window', () => {
		// Not the chip's filter: this table also lists what is due within
		// three days, so landing on overdue-only would show fewer cases than
		// the table just listed. What must hold is that the filter survives
		// the trip at all (triage item 4) and reaches the page as a flat
		// bracket key rather than a JSON-stringified object.
		const table = dashboardWidgets().deadlines
		const query = routeQuery(table)

		expect(table.content.viewAllRoute.name).toBe('Cases')
		expect(query).toEqual(asQuery(flattenFilter(table.content.source.filter)))
		expect(query.isFinalStatus).toBe('false')
		expect(Object.keys(query)).toContain('deadline[lte]')
	})
})

/**
 * Flatten an object-table `source.filter` to OpenRegister's bracket grammar,
 * the same shaping `dashboardViewAllRoutes.spec.js` does.
 *
 * @param {object} filter The widget's source filter.
 * @return {object} Bracket-grammar pairs.
 */
function flattenFilter(filter) {
	const out = {}
	for (const [key, value] of Object.entries(filter || {})) {
		if (value && typeof value === 'object' && !Array.isArray(value)) {
			for (const [op, inner] of Object.entries(value)) {
				out[`${key}[${op}]`] = String(inner)
			}
		} else {
			out[key] = String(value)
		}
	}
	return out
}

describe('the Deadline column', () => {
	/**
	 * The Deadline column entry of the Cases page.
	 *
	 * @return {object} The column entry.
	 */
	const deadlineColumn = () =>
		page('Cases').config.columns.find(
			(column) => typeof column === 'object' && column.key === 'deadline',
		)

	it('renders through the deadlineCountdown cell widget', () => {
		expect(deadlineColumn().widget).toBe('deadlineCountdown')
	})

	it('names a widget id the cell registry resolves', () => {
		// A `widget` id that resolves to nothing in `cnCellWidgets` does not
		// error: CnCellRenderer falls through to the type-aware rendering and
		// the column silently reverts to a plain date.
		expect(cellWidgetsSource).toMatch(/^\tdeadlineCountdown: /m)
	})

	it('sorts on the stored deadline, not on the rendered text', () => {
		expect(deadlineColumn().sortable).not.toBe(false)
	})

	it('keeps its place after the assignee column', () => {
		const keys = page('Cases').config.columns.map((column) =>
			typeof column === 'string' ? column : column.key,
		)
		expect(keys.indexOf('deadline')).toBeGreaterThan(keys.indexOf('assignee'))
	})
})

describe('bulk actions on the Cases index', () => {
	/**
	 * The Cases page's bulk actions.
	 *
	 * @return {Array<object>} The bulk-action entries, in order.
	 */
	const actions = () => page('Cases').config.bulkActions

	it('offers reassign plus the four lifecycle gestures', () => {
		expect(actions().map((action) => action.id)).toEqual([
			'reassign',
			'transition',
			'suspend',
			'resume',
			'extend-term',
		])
	})

	it('names a handler that customComponents.js defines AND exports', () => {
		// Both halves matter and neither errors on its own: a handler name
		// with no function is a bulk action that does nothing when clicked,
		// and a function that is not in the default export is invisible to
		// the manifest renderer, which resolves the name through that map.
		for (const action of actions()) {
			expect(customComponentsSource).toContain(`function ${action.handler}(`)
			expect(customComponentsSource).toMatch(
				new RegExp(`^\\t${action.handler},$`, 'm'),
			)
		}
	})

	it('names an icon that src/icons.js registers', () => {
		// An unregistered icon name renders NO icon, not a fallback glyph
		// (hydra gate-60), so a bulk action would appear as a bare label.
		for (const action of actions()) {
			expect(iconsSource).toMatch(new RegExp(`^\\t${action.icon},$`, 'm'))
		}
	})

	it('gives every action a label the catalogue can translate', () => {
		for (const action of actions()) {
			expect(typeof action.label).toBe('string')
			expect(action.label.length).toBeGreaterThan(0)
		}
	})
})

describe('what this change does NOT move', () => {
	it('leaves the Queue page and the My Work page in place', () => {
		expect(page('Queue')).toBeTruthy()
		expect(page('MyWork')).toBeTruthy()
	})

	it('changes no menu entry', () => {
		expect(manifest.menu.map((entry) => entry.label)).toEqual([
			'Dashboard',
			'Queue',
			'Assigned to me',
			'My work',
			'All cases',
			'Tasks',
			'Workflow board',
			'Reports',
			'Processing time',
			'Process mining',
			'Map',
			'Settings',
			'Documentation',
			'Store',
			'Organisations',
			'Map layers',
			'Case types',
			'Flows',
			'Objection advisory committees',
			'Deadline monitoring',
			'Substitutions & reassignment',
			'Features & roadmap',
			'Processing activities (AVG)',
			'AI oversight',
		])
	})
})
