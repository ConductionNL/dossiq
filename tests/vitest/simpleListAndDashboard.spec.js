/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The cases list, the board, the dashboard and the landing page in the
 * simple structure.
 *
 * All three are overlays in `src/menu-layout.simple.json`. An overlay fails
 * quietly: a patch that names a lens which does not exist adds no count, a
 * filter on a field the case does not carry counts nothing and shows 0, and a
 * layout entry that names no widget leaves a hole. So every name here is
 * checked against the thing it names, and the full structure is held equal to
 * the manifest.
 *
 * @spec openspec/specs/dashboard/spec.md
 * @spec openspec/changes/landing-views/specs/my-work-landing/spec.md
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import { spawnSync } from 'child_process'
import fs from 'fs'
import os from 'os'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { cardDueSeverity } from '../../src/utils/cardDueSeverity.js'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'
import { pageGrids, pageView, pageWidgets } from './helpers/pageViews.js'

const ROOT = path.resolve(__dirname, '../..')
const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')
const readJson = (...parts) => JSON.parse(read(...parts))

const fragments = fs
	.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
	.filter((name) => name.endsWith('.json'))
	.sort()
	.map((name) => readJson('src', 'manifest.d', name))
const simpleFile = readJson('src', 'menu-layout.simple.json')
const fullFile = readJson('src', 'menu-layout.json')
const register = readJson('lib', 'Settings', 'dossiq_register.json')
const iconsSource = read('src', 'icons.js')
const registrySource = read('src', 'registry.js')
const cardSource = read('src', 'views', 'workflow-board', 'CaseCard.vue')

const WOO = '3c0f5a00-0000-4000-a000-00000000a001'

// The case schema is the main register plus what the fragments add to it.
const caseFields = new Set(Object.keys(register.components.schemas.case.properties))
for (const name of fs.readdirSync(
	path.join(ROOT, 'lib', 'Settings', 'register.d'),
)) {
	if (name.endsWith('.json')) {
		const fragment = readJson('lib', 'Settings', 'register.d', name)
		Object.keys(fragment.components?.schemas?.case?.properties ?? {}).forEach(
			(field) => caseFields.add(field),
		)
	}
}

function build(file) {
	return buildProfiledManifest(
		buildManifest,
		readJson('src', 'manifest.json'),
		fragments,
		file,
	)
}
const builtSimple = build(simpleFile)
const builtFull = build(fullFile)
const page = (built, id) => built.pages.find((item) => item.id === id)
function original(id) {
	return readJson('src', 'manifest.json').pages.find((item) => item.id === id)
}

/**
 * A definition without its `_` notes, which a copy leaves behind.
 *
 * @param {object} definition A widget definition.
 * @return {object} The same definition, notes left out.
 */
function withoutNotes(definition) {
	return Object.fromEntries(
		Object.entries(definition).filter(([key]) => !key.startsWith('_')),
	)
}

/**
 * The case fields a filter narrows on, bracket and nested forms alike.
 *
 * @param {object} filter A widget or lens filter.
 * @return {Array<string>} The field names, lenses that start with `_` aside.
 */
function filterFields(filter) {
	return Object.keys(filter)
		.map((key) => key.replace(/\[.*$/, '').replace(/_isnull$/, ''))
		.filter((key) => !key.startsWith('_'))
}

describe('the full structure', () => {
	it('keeps the list, the board, the dashboard and the landing page exactly as the manifest declares them', () => {
		for (const id of ['Cases', 'WorkflowBoard', 'Dashboard', 'MyWorkHome']) {
			expect(page(builtFull, id), id).toEqual(original(id))
		}
	})
})

describe('the cases list', () => {
	const simple = page(builtSimple, 'Cases').config
	const before = original('Cases').config

	it('leads with five views that each show a count', () => {
		expect(simple.quickFilterMaxVisible).toBe(5)
		const lead = simple.quickFilters.slice(0, 5)
		expect(lead.map((lens) => lens.label)).toEqual([
			'All',
			'Mine',
			'Due this week',
			'Waiting on the applicant',
			'Woo requests',
		])
		for (const lens of lead) {
			expect(lens.showCount, lens.label).toBe(true)
		}
		expect(lead[0].default).toBe(true)
	})

	it('keeps every one of the 19 lenses it had (With drafts joined them with decision 201), with the filter it had', () => {
		expect(before.quickFilters).toHaveLength(19)
		expect(simple.quickFilters).toHaveLength(20)
		for (const lens of before.quickFilters) {
			const kept = simple.quickFilters.find(
				(item) => item.label === lens.label,
			)
			expect(kept, lens.label).toBeTruthy()
			expect(kept.filter, lens.label).toEqual(lens.filter)
		}
		// Behind the chip they keep their own order.
		const rest = (list) =>
			list
				.map((lens) => lens.label)
				.filter(
					(label) =>
						![
							'All',
							'Mine',
							'Due this week',
							'Waiting on the applicant',
							'Woo requests',
						].includes(label),
				)
		expect(rest(simple.quickFilters)).toEqual(rest(before.quickFilters))
	})

	it('narrows Woo requests on the seeded Woo case type', () => {
		const woo = simple.quickFilters.find((lens) => lens.label === 'Woo requests')
		expect(woo.filter).toEqual({
			caseType: WOO,
			statusHiddenInLists: false,
			isDraft: false,
		})
		expect(
			read('lib', 'Settings', 'register.d', '81-woo-verzoek.json'),
		).toContain(`"${WOO}"`)
	})

	it('shows five columns, the number and the requester under the title, each a field the case carries', () => {
		const keys = simple.columns.map((column) =>
			typeof column === 'string' ? column : column.key,
		)
		expect(keys).toEqual(['title', 'caseType', 'status', 'assignee', 'deadline'])
		for (const key of keys) {
			expect(caseFields.has(key), key).toBe(true)
		}
		// DqZaken's "Zaak" column: the title, and "2026-0061 · M. de Graaf"
		// under it (nextcloud-vue 2.64.0 column `secondary`).
		const title = simple.columns.find((column) => column.key === 'title')
		expect(title).toMatchObject({
			label: 'Case',
			secondary: '{identifier} · {initiatorDisplayName}',
		})
		for (const field of title.secondary.match(/\{([^}]+)\}/g)) {
			expect(caseFields.has(field.slice(1, -1)), field).toBe(true)
		}
		// Type and status keep the formatter and widget they had.
		for (const key of ['caseType', 'status']) {
			const was = before.columns.find((column) => column.key === key)
			const now = simple.columns.find((column) => column.key === key)
			expect(now.formatter).toBe(was.formatter)
			expect(now.widget).toBe(was.widget)
		}
	})

	it('draws the handler as an avatar and the deadline in colour, red from the day after it ends', () => {
		const handler = simple.columns.find((column) => column.key === 'assignee')
		expect(handler).toMatchObject({
			widget: 'avatar',
			widgetProps: { user: true },
		})
		const deadline = simple.columns.find((column) => column.key === 'deadline')
		expect(deadline.widget).toBe('date')
		// A term is not late on its last day (one-term-engine, REQ-OTE-05).
		expect(deadline.widgetProps.variantWhen).toEqual([
			{ op: 'lt', value: 0, variant: 'error' },
			{ op: 'lte', value: 5, variant: 'warning' },
		])
	})

	it('drops the case type pane the design has none of, and keeps the case type a column', () => {
		// The full list opens with a folder pane of case types on the left.
		// The design draws the list without it: the case type is a column, a
		// view (Woo requests) and the `?caseType=` the menu entry carries,
		// which the library merges into the fetch with or without a pane.
		expect(before.folderSidebar.filterField).toBe('caseType')
		expect(simple.folderSidebar).toBeUndefined()
		expect(page(builtFull, 'Cases').config.folderSidebar).toEqual(
			before.folderSidebar,
		)
		expect(simple.columns.some((column) => column.key === 'caseType')).toBe(true)
	})

	it('shows its title and the count of rows, as the design does', () => {
		// DqZaken: "Alle zaken" over "48 ...". The page title is the menu
		// label, and `{total}` is the total of the view the list shows.
		expect(page(builtSimple, 'Cases').title).toBe('All cases')
		expect(simple.showTitle).toBe(true)
		expect(simple.countSubtitle).toBe('{total} cases')
		expect(page(builtFull, 'Cases').title).toBe(original('Cases').title)
		expect(before.showTitle).toBeUndefined()
		expect(before.countSubtitle).toBeUndefined()
	})

	it('draws the view switch as the board does', () => {
		expect(simple.showViewToggle).toBe(true)
		expect(before.showViewToggle).toBeUndefined()
	})

	it('shows a filter funnel on every column, as DqZaken draws them on Zaak, Type, Status, Behandelaar and Termijn', () => {
		// The board is the canon (case-search-and-lists, REQ-CSL-07). The
		// library draws the funnels unless the page sets `headerFilters: false`
		// or a column sets `filterable: false`, so neither may appear here.
		expect(simple.headerFilters).not.toBe(false)
		expect(before.headerFilters).not.toBe(false)
		expect(simple.columns.map((column) => column.label)).toEqual([
			'Case',
			'Type',
			'Status',
			'Handler',
			'Deadline',
		])
		for (const column of simple.columns) {
			expect(column.filterable, column.key).not.toBe(false)
		}
		// And the library default is what makes "not false" mean "on".
		const indexPage = read(
			'node_modules',
			'@conduction',
			'nextcloud-vue',
			'src',
			'components',
			'CnIndexPage',
			'CnIndexPage.vue',
		)
		expect(indexPage).toMatch(
			/headerFilters: \{\s*type: Boolean,\s*default: true,\s*\}/,
		)
	})

	it('touches nothing else on the list', () => {
		const rest = ({
			quickFilters,
			quickFilterMaxVisible,
			columns,
			folderSidebar,
			showTitle,
			countSubtitle,
			countText,
			footerNote,
			bulkHint,
			headerButtons,
			showViewToggle,
			headerFilters,
			searchPlaceholder,
			...others
		}) => others
		expect(rest(simple)).toEqual(rest(before))
	})
})

describe('the board', () => {
	const rule = page(builtSimple, 'WorkflowBoard').config.dueRule

	it('drops the Dashboard button in the simple structure only, read from the board page', () => {
		// DqWerkbord's header holds the title and the case type select; the
		// dashboard is in the navigation already.
		expect(page(builtSimple, 'WorkflowBoard').config.showDashboardLink).toBe(
			false,
		)
		expect(
			page(builtFull, 'WorkflowBoard').config.showDashboardLink,
		).toBeUndefined()
		const boardSource = read(
			'src',
			'views',
			'workflow-board',
			'WorkflowBoard.vue',
		)
		expect(boardSource).toContain('v-if="showDashboardLink"')
		expect(boardSource).toContain(
			'return board?.config?.showDashboardLink !== false',
		)
	})

	it('declares a due rule in the simple structure and none in the full one', () => {
		expect(rule).toEqual({
			field: 'deadline',
			variantWhen: [
				{ op: 'lt', value: 0, variant: 'error' },
				{ op: 'lte', value: 3, variant: 'warning' },
			],
		})
		expect(page(builtFull, 'WorkflowBoard').config.dueRule).toBeUndefined()
	})

	it('counts only yesterday as late, with the rule and without it', () => {
		expect(cardDueSeverity(-1, rule)).toBe('overdue')
		expect(cardDueSeverity(0, rule)).toBe('warning')
		expect(cardDueSeverity(1, rule)).toBe('warning')
		expect(cardDueSeverity(3, rule)).toBe('warning')
		expect(cardDueSeverity(4, rule)).toBe('ok')
		expect(cardDueSeverity(null, rule)).toBeNull()

		// The full structure: the card's rule as it always was.
		expect(cardDueSeverity(-1, null)).toBe('overdue')
		expect(cardDueSeverity(0, null)).toBe('warning')
		expect(cardDueSeverity(3, null)).toBe('warning')
		expect(cardDueSeverity(4, null)).toBe('ok')
	})

	it('is read by the card from the board page, which is the caller that matters', () => {
		// A rule nothing reads is a rule that does nothing.
		expect(cardSource).toContain('cnManifest: { default: null }')
		expect(cardSource).toContain(
			"pages.find((page) => page.id === 'WorkflowBoard')",
		)
		expect(cardSource).toContain(
			'return cardDueSeverity(this.daysRemaining, this.dueRule)',
		)
	})
})

describe('the landing page', () => {
	// landing-views: the design's widgets moved from the dashboard to the
	// landing page (MyWorkHome), whose greeting switches between two views
	// of that page instead of opening two other pages.
	const simple = page(builtSimple, 'MyWorkHome')
	const mine = pageView(simple, 'mine')
	const widget = (id) => pageWidgets(simple).find((item) => item.id === id)
	const entry = (id) => mine.layout.find((item) => item.widgetId === id)

	it('puts the greeting in the page header and the design in the My work view', () => {
		// nextcloud-vue 2.79.0 draws the greeting subtitle and the view
		// switch itself (`greeting`), so the page grid is empty.
		expect(simple.config.layout).toEqual([])
		expect(simple.config.widgets).toEqual([])
		expect(simple.config.greeting).toBe('first')
		expect(simple.config.showActionsMenu).toBe(false)
		// DqMijnWerk draws the greeting and the date and nothing after them.
		expect(simple.config.description).toBe('')
		const order = [...mine.layout]
			.sort((a, b) => a.gridY - b.gridY || a.gridX - b.gridX)
			.map((item) => item.widgetId)
		expect(order).toEqual([
			'simple-first-today',
			'simple-my-open',
			'simple-due-soon',
			'simple-waiting',
			'simple-closed-month',
			'simple-week',
			'simple-my-tasks',
			'simple-per-step',
			'simple-continue',
			'followed-cases',
			'archival-reviews',
		])
		// The design (DqDashboard) draws two columns under the counts: the
		// week, the steps and "continue working" take two thirds on the left,
		// my tasks the right third beside all three.
		for (const id of ['simple-week', 'simple-per-step', 'simple-continue']) {
			expect(entry(id).gridX, id).toBe(0)
			expect(entry(id).gridWidth, id).toBe(8)
		}
		expect(entry('simple-my-tasks')).toMatchObject({
			gridX: 8,
			gridY: 4,
			gridWidth: 4,
		})
		expect(
			entry('simple-my-tasks').gridY + entry('simple-my-tasks').gridHeight,
		).toBe(entry('simple-continue').gridY + entry('simple-continue').gridHeight)
	})

	it('switches views of this page, not pages', () => {
		// Ruben, 7 October 2026: the switch opened the dashboard and the team
		// queue, and the greeting stood over an empty area. The page's own
		// view switch now names the two views the page declares.
		const views = simple.config.views
		expect(views.map((item) => [item.id, item.label])).toEqual([
			['mine', 'My work'],
			['team', 'My team'],
		])
		expect(simple.config.defaultView).toBe('mine')
	})

	it('changes only the My work view: My team is the manifest declaration, in both structures', () => {
		const team = (built) => pageView(page(built, 'MyWorkHome'), 'team')
		expect(team(builtSimple)).toEqual(pageView(original('MyWorkHome'), 'team'))
		expect(team(builtSimple)).toEqual(team(builtFull))
		expect(simple.config.views.map((view) => view.id)).toEqual(['mine', 'team'])
	})

	it('keeps My archival reviews, which the design has no stand-in for, as the manifest declares it', () => {
		const base = pageView(original('MyWorkHome'), 'mine').widgets
		expect(widget('archival-reviews')).toEqual(
			withoutNotes(base.find((w) => w.id === 'archival-reviews')),
		)
		expect(simple.slots['widget-archival-reviews']).toBe('MyArchivalReviews')
	})

	it('draws the cases you follow as rows, and loses nothing the table had', () => {
		// DqMijnWerk: the title, the case number under it, the status as a
		// pill, "Alles bekijken" in the card header. Everything the full
		// profile's table does is still here: the same lens, the same two
		// fields, a row that opens the case, the link to the whole list and
		// the same empty text.
		const base = pageView(original('MyWorkHome'), 'mine').widgets.find(
			(w) => w.id === 'followed-cases',
		).content
		const rows = widget('followed-cases')
		expect(rows.type).toBe('record-list')
		expect(rows.content).toMatchObject({
			register: base.source.register,
			schema: base.source.schema,
			filter: base.source.filter,
			limit: base.source.limit,
			rowRoute: base.rowRoute,
			emptyText: base.emptyText,
		})
		expect(base.columns.map((column) => column.key).sort()).toEqual([
			'identifier',
			'title',
		])
		expect(rows.content.title).toBe('{title}')
		expect(rows.content.meta).toBe('{identifier}')
		// The pill reads the status NAME through _extend, never the uuid.
		expect(rows.content.extend).toEqual(['status'])
		expect(rows.content.pill.field).toBe('status.name')
		expect(entry('followed-cases').headerLink).toEqual({
			label: base.viewAllLabel,
			route: base.viewAllRoute.name,
			query: base.viewAllRoute.query,
		})
	})

	it('draws First today without a card around it, no widget menus and stacked counts', () => {
		// The attention card is a card of its own; its grid cell drew a second,
		// larger white card under it. DqDashboard has no Actions menus, and
		// draws each count as the label over the value.
		expect(entry('simple-first-today').borderless).toBe(true)
		expect(simple.config.showWidgetActions).toBe(false)
		for (const id of [
			'simple-my-open',
			'simple-due-soon',
			'simple-waiting',
			'simple-closed-month',
		]) {
			expect(widget(id).content.layout, id).toBe('stacked')
		}
		// The full landing page keeps its menus and cards.
		expect(original('MyWorkHome').config.showWidgetActions).toBeUndefined()
	})

	it('keeps the page header, because it carries the links that stand in for removed menu entries', () => {
		// The dashboard hid its header for the greeting. Here that would also
		// hide Your queue, Assigned to me and Close out your day, which this
		// profile takes out of the menu.
		expect(simple.config.showHeader).toBeUndefined()
		// They are pills on the switch row now (`viewLinks`).
		expect(simple.config.viewLinks.map((link) => link.route)).toEqual([
			'PersonalQueue',
			'MyWork',
			'EndOfDay',
		])
	})

	it('gives the dashboard back its full-structure widgets', () => {
		const dashboard = page(builtSimple, 'Dashboard')
		const before = original('Dashboard')
		// The two charts draw as the board's horizontal bars; every other
		// widget is the full structure's, and the five KPI tiles leave the
		// grid for the KPI row above it.
		// Two KPI tiles carry the board's own words: Late (Te laat) and Within
		// deadline (Binnen termijn).
		const boardWords = {
			'kpi-overdue': 'Late',
			'kpi-sla-compliance': 'Within deadline',
		}
		const sameButBars = (list) =>
			list.map((item) => {
				if (boardWords[item.id]) {
					return {
						...item,
						title: boardWords[item.id],
						content: { ...item.content, label: boardWords[item.id] },
					}
				}
				return item.type === 'chart'
					? { ...item, content: { ...item.content, horizontal: true } }
					: item
			})
		expect(dashboard.config.widgets).toEqual(sameButBars(before.config.widgets))
		expect(dashboard.config.kpiRow).toEqual([
			'kpi-open-cases',
			'kpi-overdue',
			'kpi-completed',
			'kpi-my-tasks',
			'kpi-sla-compliance',
		])
		expect(dashboard.config.layout.map((entry) => entry.widgetId)).toEqual([
			'cases-by-status',
			'cases-by-type',
			'stalled-cases',
			'favourite-cases',
			'recent-cases',
		])
		expect(dashboard.config.showHeader).toBeUndefined()
		expect(dashboard.slots).toEqual(before.slots)
		expect(dashboard.config.headerActions.map((action) => action.id)).toContain(
			'open-end-of-day',
		)
	})

	it('continues where the handler left off, over the recent lens the full dashboard already uses', () => {
		// "Verder werken" on the design: the cases this reader opened last.
		// OpenRegister's `_recent` lens carries its own order, so the widget
		// declares none, exactly like the full dashboard's Recently opened.
		const recent = original('Dashboard').config.widgets.find(
			(item) => item.id === 'recent-cases',
		)
		const cont = widget('simple-continue')
		expect(cont.type).toBe('object-table')
		expect(cont.content.source).toEqual({ ...recent.content.source, limit: 3 })
		expect(cont.content.rowRoute).toBe('CaseDetail')
		expect(cont.content.viewAllRoute).toEqual(recent.content.viewAllRoute)
		// The case number sits under the title, as on the design, not in a
		// column of its own (nextcloud-vue 2.64.0 `columns[].secondary`).
		expect(cont.content.columns.map((column) => column.key)).toEqual([
			'title',
			'status',
		])
		expect(cont.content.columns[0].secondary).toBe('identifier')
		expect(caseFields.has('identifier')).toBe(true)
	})

	it('gives the week, the steps and the tasks a link of their own', () => {
		const link = (widgetId) => entry(widgetId).headerLink
		expect(link('simple-week').route).toBe('Cases')
		expect(link('simple-week').query).toEqual(
			widget('simple-due-soon').content.route.query,
		)
		expect(link('simple-my-tasks')).toEqual({
			label: 'All tasks',
			route: 'Tasks',
		})
		expect(link('simple-per-step')).toEqual({
			label: 'To the board',
			route: 'WorkflowBoard',
		})
		const pageIds = new Set(builtSimple.pages.map((item) => item.id))
		for (const item of mine.layout.filter((cell) => cell.headerLink)) {
			expect(pageIds.has(item.headerLink.route), item.widgetId).toBe(true)
		}
	})

	it('names ONE case in First today, through its own widget, with the case page as the primary act', () => {
		// DqMijnWerk: "Vandaag eerst" names the case furthest past its
		// deadline. The library banner counts and cannot name one.
		expect(widget('simple-first-today').type).toBe('custom')
		expect(simple.slots['widget-simple-first-today']).toBe('FirstTodayWidget')
		expect(registrySource).toContain('FirstTodayWidget')
		const source = read('src', 'views', 'widgets', 'FirstTodayWidget.vue')
		expect(source).toMatch(/primary: true,\s+route: \{ name: 'CaseDetail'/)
		expect(source).toContain('pastTermFilter')
		expect(
			widget('simple-first-today').content.visibleWhen.source.filter,
		).toHaveProperty(['deadline[lt]'], '@today+1d')
	})

	it('places every widget of every grid, and no two cards of one grid overlap', () => {
		for (const grid of pageGrids(simple)) {
			const ids = new Set(grid.widgets.map((item) => item.id))
			const cells = new Set()
			for (const item of grid.layout) {
				expect(ids.has(item.widgetId), `${grid.id}: ${item.widgetId}`).toBe(
					true,
				)
				expect(item.gridX + item.gridWidth).toBeLessThanOrEqual(12)
				for (let x = item.gridX; x < item.gridX + item.gridWidth; x++) {
					for (let y = item.gridY; y < item.gridY + item.gridHeight; y++) {
						const cell = `${x}:${y}`
						expect(
							cells.has(cell),
							`${grid.id}: ${item.widgetId} overlaps at ${cell}`,
						).toBe(false)
						cells.add(cell)
					}
				}
			}
			const placed = new Set(grid.layout.map((item) => item.widgetId))
			for (const id of ids) {
				expect(placed.has(id), `${grid.id}: ${id}`).toBe(true)
			}
		}
	})

	it('counts and lists only over fields the case carries, and only MY cases', () => {
		const sources = [
			'simple-my-open',
			'simple-due-soon',
			'simple-waiting',
			'simple-week',
			'simple-per-step',
		].map((id) => [id, widget(id).content.source])
		for (const [id, source] of sources) {
			expect(source.register, id).toBe('dossiq')
			expect(source.schema, id).toBe('case')
			expect(source.filter.assignee, id).toBe('@me')
			for (const field of filterFields(source.filter)) {
				expect(caseFields.has(field), `${id}: ${field}`).toBe(true)
			}
		}
		expect(caseFields.has(widget('simple-week').content.dateField)).toBe(true)
		expect(
			caseFields.has(widget('simple-per-step').content.source.groupBy),
		).toBe(true)
	})

	it('names the steps by status roles a status can have', () => {
		const roles = register.components.schemas.statusType.properties.role.enum
		const bar = widget('simple-per-step').content
		expect(bar.source.groupBy).toBe('statusRole')
		for (const key of [...bar.order, ...Object.keys(bar.labels)]) {
			expect(roles, key).toContain(key)
		}
	})

	it('marks only a passed deadline as late in the week strip, like the list and the board', () => {
		expect(widget('simple-week').content.lateWhen).toEqual({
			op: 'lt',
			value: 0,
		})
		expect(widget('simple-week').content.itemRoute).toBe('CaseDetail')
	})

	it('tells one story about deadlines: the tile is the list view, the card is what is late', () => {
		// Live check, 5 October 2026: the tile said 58 over an empty week,
		// because it counted overdue cases and the strip only this week.
		const lens = original('Cases').config.quickFilters.find(
			(item) => item.label === 'Due this week',
		).filter
		const tile = widget('simple-due-soon').content
		expect(tile.label).toBe('Due this week')
		expect(tile.source.filter).toEqual({
			assignee: '@me',
			isFinalStatus: lens.isFinalStatus,
			statusHiddenInLists: lens.statusHiddenInLists,
			isDraft: lens.isDraft,
			deadline: { gte: lens['deadline[gte]'], lt: lens['deadline[lt]'] },
		})
		expect(tile.route.query['deadline[gte]']).toBe('@today')
		expect(tile.route.query['deadline[lt]']).toBe('@today+7d')

		// First today lists everything past its deadline, today included, and
		// its link opens exactly those cases (the widget's own query).
		const source = read('src', 'views', 'widgets', 'FirstTodayWidget.vue')
		expect(source).toContain('this.content?.actions?.[0]?.route')
	})

	it('keeps the tile labels short enough to read', () => {
		for (const id of [
			'simple-my-open',
			'simple-due-soon',
			'simple-waiting',
			'simple-closed-month',
		]) {
			expect(widget(id).content.label.length, id).toBeLessThanOrEqual(24)
		}
	})

	it('places my tasks as the library tasks widget in its list variant', () => {
		expect(widget('simple-my-tasks').type).toBe('tasks')
		expect(simple.slots).not.toHaveProperty('widget-simple-my-tasks')
		// The full My work view keeps the app's own tile (a table, with the
		// substitution toggle); only the simple profile uses the list variant.
		const onMyWork = pageView(original('MyWorkHome'), 'mine').widgets.find(
			(item) => item.id === 'my-work',
		)
		expect(onMyWork.content).not.toHaveProperty('variant')
		expect(widget('simple-my-tasks').content).toMatchObject({
			scope: 'assigned',
			variant: 'list',
			rowRoute: 'TaskDetail',
			viewAllRoute: { name: 'Tasks' },
		})
	})

	it('uses icons the app registers', () => {
		for (const item of pageWidgets(simple).filter((w) =>
			w.id.startsWith('simple-'),
		)) {
			if (item.content?.icon) {
				expect(iconsSource, item.id).toContain(`\n\t${item.content.icon},\n`)
			}
		}
	})
})

describe('the built simple manifest', () => {
	it('still passes the schema the installed library ships', () => {
		const file = path.join(
			fs.mkdtempSync(path.join(os.tmpdir(), 'dossiq-simple-')),
			'manifest.json',
		)
		fs.writeFileSync(file, JSON.stringify(builtSimple))
		const run = spawnSync(
			'node',
			[path.join(ROOT, 'tests', 'validate-manifest.js')],
			{
				cwd: ROOT,
				env: { ...process.env, APP_MANIFEST: file },
				encoding: 'utf8',
			},
		)
		expect(run.stdout + run.stderr).toContain('PASS (0 errors)')
		expect(run.status).toBe(0)
	}, 120_000)
})

describe('the case type list', () => {
	// DqZaaktypen: Downloaden, Acties and the primary button in the header, and
	// a Status pill beside the version, and a count on every lens (nextcloud-vue
	// 2.81.0 counts the `supersededBy: IS NULL` filter right; before it answered
	// 0 on every lens).
	const list = page(builtSimple, 'CaseTypes')

	it('draws the header buttons and a count on each lens', () => {
		expect(list.config.headerButtons.map((b) => b.action)).toEqual([
			'export',
			'actions-menu',
			'add',
		])
		expect(list.config.headerButtons[2].variant).toBe('primary')
		for (const lens of list.config.quickFilters) {
			expect(lens.showCount, lens.label).toBe(true)
		}
	})

	it('shows the term in words, the version as Version n and a status pill', () => {
		const column = (key) => list.config.columns.find((c) => c.key === key)
		expect(column('processingDeadline').formatter).toBe('isoDuration')
		expect(column('version').formatter).toBe('versionLabel')
		expect(column('isDraft')).toMatchObject({
			label: 'Status',
			formatter: 'draftState',
			widget: 'badge',
		})
	})
})
