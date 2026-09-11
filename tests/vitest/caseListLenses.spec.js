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

import { TODAY_DELTA_RE } from '@conduction/nextcloud-vue/src/utils/sentinelTokens.js'
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

/**
 * The six lens labels, in the order both index pages declare them.
 *
 * ONE list, asserted against both pages, because the parallel between the
 * two lists is the point rather than a coincidence: a person who learned the
 * Cases chips has learned the Tasks chips, and only the field underneath
 * differs. Two separate literals would let the pages drift apart while both
 * tests stayed green, which is the failure `a-test-that-feeds-two-different-
 * literals` names.
 */
const LENSES = ['All', 'Mine', 'Unclaimed', 'Closed', 'Overdue', 'Due this week']

describe('Cases index lenses', () => {
	it('declares the six chips in order', () => {
		expect(chips('Cases').map((entry) => entry.label)).toEqual(LENSES)
	})

	it('marks All as the default chip and nothing else', () => {
		const defaults = chips('Cases').filter((entry) => entry.default === true)
		expect(defaults).toHaveLength(1)
		expect(defaults[0].label).toBe('All')
		// All was literally `{}` until `case-type-authoring-extras` gave a
		// status a `hiddenInLists` flag: a status marked hidden keeps its
		// cases off every lens but Closed, and All is the lens the index
		// opens on. The ONE condition is still the whole filter — no
		// assignee, no case type, nothing that narrows to a person's own
		// work — which is what this test has always been guarding.
		expect(defaults[0].filter).toEqual({ statusHiddenInLists: false })
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

	it('gives Due this week the half-open window on deadline', () => {
		expect(chip('Cases', 'Due this week').filter).toEqual({
			isFinalStatus: false,
			'deadline[gte]': '@today',
			'deadline[lt]': '@today+7d',
		})
	})
})

describe('Tasks index lenses', () => {
	it('declares the same six labels as Cases, in the same order', () => {
		expect(chips('Tasks').map((entry) => entry.label)).toEqual(LENSES)
		expect(chips('Tasks').map((entry) => entry.label)).toEqual(
			chips('Cases').map((entry) => entry.label),
		)
	})

	it('marks All as the default chip', () => {
		const defaults = chips('Tasks').filter((entry) => entry.default === true)
		expect(defaults).toHaveLength(1)
		expect(defaults[0].label).toBe('All')
	})

	it('keeps completed tasks out of Mine and Unclaimed', () => {
		// The two person-scoped lenses, and the only two. `scope` says so
		// rather than relying on the default, which is the point of the
		// test below.
		expect(chip('Tasks', 'Mine').filter).toEqual({
			scope: 'assigned',
			isTerminal: false,
		})
		expect(chip('Tasks', 'Unclaimed').filter).toEqual({
			scope: 'pooled',
			isTerminal: false,
		})
	})

	it('shows completed tasks under Closed only', () => {
		expect(chip('Tasks', 'Closed').filter).toEqual({
			scope: 'all',
			isTerminal: true,
		})
	})

	/**
	 * 🔴 EVERY LENS NAMES ITS SCOPE, and four of the six name `all`.
	 *
	 * The task endpoint defaults to `scope: assigned`. A lens that left
	 * `scope` off would therefore answer "my closed tasks" where this list
	 * has always meant "closed tasks", and it would do it quietly: the
	 * table renders, the count is plausible, and only somebody who knows
	 * what their colleagues are working on would notice the rows missing.
	 * That is why this is asserted per lens rather than left to the reader
	 * of the manifest note.
	 */
	it('names a scope on every lens, so none inherits the assigned default', () => {
		for (const entry of chips('Tasks')) {
			expect(
				entry.filter.scope,
				`${entry.label} declares no scope`,
			).toBeTruthy()
		}

		const personal = chips('Tasks')
			.filter((entry) => entry.filter.scope !== 'all')
			.map((entry) => entry.label)
			.sort()
		expect(personal).toEqual(['Mine', 'Unclaimed'])
	})

	it('asks the engine for the two due windows rather than deriving them', () => {
		// `overdue` is the engine's own derived filter, so dossiq stops
		// comparing a date string against a `dueDate` column that no longer
		// exists. The week window is a real pair of instants.
		expect(chip('Tasks', 'Overdue').filter).toEqual({
			scope: 'all',
			overdue: true,
		})
		expect(chip('Tasks', 'Due this week').filter).toEqual({
			scope: 'all',
			isTerminal: false,
			dueAfter: '@today',
			dueBefore: '@today+7d',
		})
	})

	it('takes its columns from the source, priority included', () => {
		// REQ-TASK-004's first scenario names priority in the row. The page
		// no longer declares columns at all: the `tasks` source supplies
		// six, and its state and priority columns are badges whose colour
		// maps are built from the same t() calls as their labels. A copy
		// here would be a divergent one, which is what this change removes.
		expect(page('Tasks').config.columns).toBeUndefined()
		expect(page('Tasks').config.entitySource).toBe('tasks')
	})
})

/**
 * The two chips per page whose filter carries an `@`-token with arithmetic
 * in it.
 *
 * A token that is not in nextcloud-vue's CLOSED sentinel vocabulary does not
 * error: `resolveFilterValue` returns the string unchanged, `buildQueryString`
 * sends the literal `@today+7d` to OpenRegister, and the lens shows an empty
 * list that reads exactly like "no work due this week". `npm run
 * check:manifest` rejects an out-of-vocabulary token through the schema's
 * `$defs`, and this holds the same line for the delta grammar specifically,
 * against the library's own regex rather than a copy of it.
 */
describe('the relative-date tokens the windows are built from', () => {
	it('spells the week edge the way the library parses it', () => {
		for (const id of ['Cases', 'Tasks']) {
			const window = chip(id, 'Due this week').filter
			const far = Object.values(window).find(
				(value) => typeof value === 'string' && value.startsWith('@today+'),
			)
			expect(far, `${id} declares a forward day-delta token`).toBeTruthy()
			expect(TODAY_DELTA_RE.test(far)).toBe(true)
			expect(far.match(TODAY_DELTA_RE)[1]).toBe('+7')
		}
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
		// The list is exact ON PURPOSE: this change moved no menu entry, and an
		// exact list is what makes that a fact rather than an intention. A
		// LATER change may legitimately add an entry, and then this list grows
		// by exactly that entry. `Objects` (custom-objects-on-the-case) is the
		// first such addition, `Integrations`
		// (pluggable-integration-registry) the second, `Contacts`
		// (contacts-domain) the third and the `Organisations` right after it
		// (contacts-you-can-find) the fourth; every entry this change was
		// about is unmoved, in the same order.
		//
		// TWO ENTRIES ARE LABELLED `Organisations` AND THAT IS NOT A TYPO. The
		// second one, further down, is `TenantsMenu` — the multitenancy
		// tenant, not a KvK company. It sits in `menu-layout.json#removals`
		// and renders nowhere, so the two never appear together; the
		// collision is only visible here, on the RAW manifest, which is
		// exactly where it should be visible.
		expect(manifest.menu.map((entry) => entry.label)).toEqual([
			'Dashboard',
			'Queue',
			'Assigned to me',
			'My work',
			'Contacts',
			'Organisations',
			'All cases',
			'Objects',
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
			'Integrations',
			'Features & roadmap',
			'Processing activities (AVG)',
			'AI oversight',
		])
	})
})
