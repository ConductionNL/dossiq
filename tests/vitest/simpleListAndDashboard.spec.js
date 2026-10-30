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
 * @spec openspec/specs/my-work-landing/spec.md
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import { spawnSync } from 'child_process'
import fs from 'fs'
import os from 'os'
import path from 'path'
import { describe, expect, it } from 'vitest'
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
	it('keeps the list, the dashboard and the landing page exactly as the manifest declares them', () => {
		for (const id of ['Dashboard', 'MyWorkHome']) {
			expect(page(builtFull, id), id).toEqual(original(id))
		}
		// The list too, but for the team queue view, which the full profile
		// patches back to the Queue page it had (menu-layout.json `pages`).
		const withoutQueue = (cases) => ({
			...cases,
			config: { ...cases.config, defaultSavedViews: cases.config.defaultSavedViews.filter((view) => view.slug !== 'team-queue') },
		})
		expect(withoutQueue(page(builtFull, 'Cases'))).toEqual(withoutQueue(original('Cases')))
	})
})

describe('the cases list', () => {
	const simple = page(builtSimple, 'Cases').config
	const before = original('Cases').config

	it('leads with six views that each show a count, three on the row and the rest behind More (Ruben, 23 Oct)', () => {
		expect(simple.quickFilterMaxVisible).toBe(3)
		const lead = simple.quickFilters.slice(0, 6)
		expect(lead.map((lens) => lens.label)).toEqual([
			'All',
			'Mine',
			'Due this week',
			'Waiting on the applicant',
			'Woo requests',
			'Suspended',
		])
		for (const lens of lead) {
			expect(lens.showCount, lens.label).toBe(true)
		}
		expect(lead[0].default).toBe(true)
	})

	it('keeps every one of the 20 lenses it had (With drafts joined them with decision 201), with the filter it had', () => {
		expect(before.quickFilters).toHaveLength(20)
		expect(simple.quickFilters).toHaveLength(21)
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
							'Suspended',
						].includes(label),
				)
		expect(rest(simple.quickFilters)).toEqual(rest(before.quickFilters))
	})

	it('narrows Woo requests on every version of the seeded Woo case type', () => {
		const woo = simple.quickFilters.find((lens) => lens.label === 'Woo requests')
		expect(woo.filter).toEqual({
			caseTypeIdentifier: 'woo-verzoek',
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

	it('opens New case as the board\'s two-column form with the duplicate check (DqNieuweZaak)', () => {
		const button = simple.headerButtons.find((entry) => entry.label === 'New case')
		const form = simple.headerActions.find((entry) => entry.id === button.action)
		expect(form).toMatchObject({
			type: 'open-form',
			formEyebrow: 'All cases',
			formTitle: 'New case',
			columns: 2,
			createOverride: 'caseCreateWithDuplicateCheck',
		})
		expect(form.includeFields.slice(0, 4)).toEqual(['caseType', 'title', 'requester', 'intakeChannel'])
		// The actions menu keeps what the bar's own menu loses while header buttons show.
		expect(simple.headerButtons.find((entry) => entry.action === 'actions-menu').items).toContain('import')
	})

	it('draws the map with its numbered list and keeps the full profile\'s map (DqZakenKaart)', () => {
		expect(simple.mapConfig.list).toMatchObject({ title: '{title}' })
		// The second line is the case address, falling back to the identifier.
		expect(simple.mapConfig.list.sub).toContain('{identifier}')
		const { list, ...rest } = simple.mapConfig
		const { _basemapNote, _parityNote, plotAll, count, ...fullMap } = before.mapConfig
		expect(rest).toEqual(fullMap)
	})

	it('colours the markers, counts them in the header only and plots only the current page', () => {
		expect(simple.mapConfig.markerColor).toEqual(before.mapConfig.markerColor)
		// The header says "· 5 met een adres op de kaart" with the list; no line under the map repeats it.
		expect(simple.mapConfig).not.toHaveProperty('count')
		expect(simple.mapConfig).not.toHaveProperty('plotAll')
	})

	it('touches nothing else on the list', () => {
		const rest = ({
			quickFilters,
			quickFilterMaxVisible,
			_quickFilterMaxVisibleNote,
			columns,
			folderSidebar,
			showTitle,
			countSubtitle,
			countText,
			countTextByView,
			_countTextByViewNote,
			defaultFilters,
			_defaultFiltersNote,
			heldDefaultFilters,
			_heldDefaultFiltersNote,
			savedViewCountText,
			_savedViewCountTextNote,
			defaultOrder,
			footerNote,
			footerNoteByView,
			bulkHint,
			bulkNoun,
			headerButtons,
			headerActions,
			mapConfig,
			_mapConfigNote,
			showAdd,
			showViewToggle,
			showMassCopy,
			showMassDelete,
			_massCopyDeleteNote,
			cardLayout,
			_cardLayoutNote,
			headerFilters,
			searchPlaceholder,
			...others
		}) => others
		expect(rest(simple)).toEqual(rest(before))
	})
})

describe('the board', () => {
	const board = (built) => page(built, 'Cases').config.board

	it('is the Cases list in board view in both structures, with no page of its own', () => {
		for (const built of [builtSimple, builtFull]) {
			expect(page(built, 'Cases').config.viewModes).toContain('board')
			expect(
				(built.pages || []).some((entry) => entry.id === 'WorkflowBoard'),
			).toBe(false)
		}
	})

	it('marks the deadline by the library rule in both structures', () => {
		// No `dueRule` override: the library's default (late from the day after
		// the deadline, soon from three days before) is the rule the simple
		// structure declared for the old board (REQ-OTE-05).
		for (const built of [builtSimple, builtFull]) {
			expect(board(built).card.due).toBe('deadline')
			expect(board(built).dueRule).toBeUndefined()
		}
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
			'simple-recent-activity',
			'followed-cases',
			'archival-reviews',
		])
		// The design (DqDashboard) draws two columns under the counts: the
		// week, the steps and "continue working" take two thirds on the left,
		// my tasks and recent activity under it the right third beside all three.
		for (const id of ['simple-week', 'simple-per-step', 'simple-continue']) {
			expect(entry(id).gridX, id).toBe(0)
			expect(entry(id).gridWidth, id).toBe(8)
		}
		expect(entry('simple-my-tasks')).toMatchObject({
			gridX: 8,
			gridY: 4,
			gridWidth: 4,
		})
		expect(entry('simple-recent-activity')).toMatchObject({
			gridX: 8,
			gridY: entry('simple-my-tasks').gridY + entry('simple-my-tasks').gridHeight,
			gridWidth: 4,
		})
		expect(
			entry('simple-recent-activity').gridY + entry('simple-recent-activity').gridHeight,
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

	it('changes My team only where DqMijnTeam differs: the stalled cases and the team queue as rows', () => {
		const team = (built) => pageView(page(built, 'MyWorkHome'), 'team')
		expect(team(builtFull)).toEqual(pageView(original('MyWorkHome'), 'team'))
		// The stalled-cases and team-queue rows are held in landingViews.spec.js;
		// the rest of the view is the manifest's.
		const rest = (view) => ({ ...view, widgets: withoutNotes(view.widgets.filter((w) => !['stalled-cases', 'your-teams-queue'].includes(w.id))) })
		expect(rest(team(builtSimple))).toEqual(rest(team(builtFull)))
		expect(simple.config.views.map((view) => view.id)).toEqual(['mine', 'team'])
	})

	it('keeps My archival reviews, which the design has no stand-in for, as the manifest declares it', () => {
		const base = pageView(original('MyWorkHome'), 'mine').widgets
		// The simple profile adds the board's subtitle (DqMijnWerk "Mijn archiefbeoordelingen").
		const { subtitle, ...kept } = widget('archival-reviews')
		expect(subtitle).toBe('Archival decisions waiting for your signature')
		expect(kept).toEqual(
			withoutNotes(base.find((w) => w.id === 'archival-reviews')),
		)
		expect(widget('archival-reviews').type).toBe('decision-list')
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
			{ name: 'Cases', query: { savedView: 'default:my-work' } },
			'EndOfDay',
		])
	})

	it('gives the dashboard back its full-structure widgets', () => {
		const dashboard = page(builtSimple, 'Dashboard')
		const before = original('Dashboard')
		// Every widget is the full structure's (both structures draw the two
		// charts as the board's bar lists), and the five KPI tiles leave the
		// grid for the KPI row above it.
		// Two KPI tiles carry the board's own words: Late (Te laat) and Within
		// deadline (Binnen termijn).
		const boardWords = {
			'kpi-overdue': 'Late',
			'kpi-sla-compliance': 'Within deadline',
		}
		// The two charts carry the board's subtitle, "Lopende zaken".
		const subtitled = ['cases-by-status', 'cases-by-type']
		const sameButBars = (list) =>
			list.map((item) => {
				if (subtitled.includes(item.id)) {
					return { ...item, subtitle: 'Open cases' }
				}
				if (boardWords[item.id]) {
					return {
						...item,
						title: boardWords[item.id],
						content: { ...item.content, label: boardWords[item.id] },
					}
				}
				return item
			})
		// Plus the board's info line under the grid (DqDashboard), which only
		// the simple structure draws, and the three case tiles, which it draws
		// as rows (see below).
		const asRows = ['stalled-cases', 'favourite-cases', 'recent-cases']
		const kept = (list) => list.filter((item) => item.id !== 'layout-note' && !asRows.includes(item.id))
		expect(kept(dashboard.config.widgets)).toEqual(sameButBars(kept(before.config.widgets)))
		expect(dashboard.config.widgets.map((item) => item.id)).toContain('layout-note')
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
			'layout-note',
		])
		expect(dashboard.config.showHeader).toBeUndefined()
		expect(dashboard.slots).toEqual(before.slots)
		expect(dashboard.config.headerActions.map((action) => action.id)).toContain(
			'open-end-of-day',
		)
	})

	it('draws the dashboard case tiles as the board\'s rows: title, number, one line, no chevron', () => {
		// DqDashboard: Vastgelopen zaken, Zaken die u volgt and Recent geopend
		// are rows of the case title, its number and a muted trailing line, without a
		// chevron, and their View all sits in the card header.
		const dashboard = page(builtSimple, 'Dashboard')
		const before = original('Dashboard')
		const tile = (list, id) => list.find((item) => item.id === id)
		const lines = {
			'stalled-cases': '{daysSinceActivity} days idle',
			'favourite-cases': undefined,
			'recent-cases': '{@self.viewedAt|relative}',
		}
		for (const [id, text] of Object.entries(lines)) {
			const rows = tile(dashboard.config.widgets, id)
			const table = tile(before.config.widgets, id)
			expect(rows.type).toBe('record-list')
			expect(rows.content).toMatchObject({
				register: table.content.source.register,
				schema: table.content.source.schema,
				filter: table.content.source.filter,
				limit: 3,
				presentation: 'rows',
				title: '{title}',
				meta: '{identifier}',
				chevron: false,
				rowRoute: 'CaseDetail',
				emptyText: table.content.emptyText,
			})
			// The line is the row's trailing text, which wraps under the number at
			// the row's start as the board draws it, not a second meta line.
			expect(rows.content.text).toBeUndefined()
			expect(rows.content.trailing).toEqual(text === undefined ? undefined : [text])
			// The header link opens the list the table's View all opened.
			const layout = dashboard.config.layout.find((entry) => entry.widgetId === id)
			const { name, ...rest } = table.content.viewAllRoute
			// Under the title, where the board draws "Alles bekijken".
			expect(layout.headerLink).toEqual({ label: 'View all', route: name, ...rest, placement: 'title' })
			// Three rows in a card the board's height (376px: five grid rows).
			expect(layout.gridHeight, id).toBe(5)
		}
		// Zaken die u volgt and Recent geopend also end with "Alles bekijken"
		// under the rows, as the board draws them (record-list-footer-link).
		for (const id of ['favourite-cases', 'recent-cases']) {
			const { name, ...rest } = tile(before.config.widgets, id).content.viewAllRoute
			expect(tile(dashboard.config.widgets, id).content.footerLink, id).toEqual({ label: 'View all', route: name, ...rest })
		}
		expect(tile(dashboard.config.widgets, 'stalled-cases').content.footerLink).toBeUndefined()
		const stalled = tile(dashboard.config.widgets, 'stalled-cases').content
		expect(stalled.extend).toEqual(['calculations'])
		expect(stalled.sort).toEqual({ field: 'lastActivityAt', dir: 'asc' })
		// The info line under the grid is a line, not a card (DqDashboard).
		const note = dashboard.config.layout.find((entry) => entry.widgetId === 'layout-note')
		expect(note).toMatchObject({ borderless: true, sizeToContent: true })
		expect(tile(dashboard.config.widgets, 'recent-cases').content.lensReasonTexts)
			.toEqual(tile(before.config.widgets, 'recent-cases').content.lensReasonTexts)
	})

	it('names the dashboard\'s month and who it covers in the description, as the board does', () => {
		// DqDashboard: "Hoe het team ervoor staat · oktober 2026, alle teams
		// waar u in zit". `{dateRange}` reads the dashboard's date range as a
		// period (nextcloud-vue description-period-from-date-range), so the
		// range must open on a calendar month preset.
		const dashboard = page(builtSimple, 'Dashboard')
		expect(dashboard.config.description).toBe('How the team is doing · {dateRange}, all teams you are in')
		const range = dashboard.config.dateRange
		expect(range.enabled).toBe(true)
		const preset = range.presets.find((entry) => entry.id === range.default.preset)
		expect(preset.period).toBe('month')
		const nl = readJson('l10n', 'nl.json').translations
		expect(nl[dashboard.config.description]).toBe('Hoe het team ervoor staat · {dateRange}, alle teams waar u in zit')
	})

	it('rules above the first case row as well, as the boards draw their case lists', () => {
		// DqDashboard, DqMijnWerk and DqMijnTeam draw a hairline above every
		// row of Vastgelopen zaken and Zaken die u volgt, the first included
		// (nextcloud-vue record-list ruleAbove).
		const dashboard = page(builtSimple, 'Dashboard').config.widgets
		for (const id of ['stalled-cases', 'favourite-cases', 'recent-cases']) {
			expect(dashboard.find((item) => item.id === id).content.ruleAbove, id).toBe(true)
		}
		expect(widget('followed-cases').content.ruleAbove).toBe(true)
		expect(widget('stalled-cases').content.ruleAbove).toBe(true)
		// DqMijnWerk Verder werken too (design-system #393).
		expect(widget('simple-continue').content.ruleAbove).toBe(true)
		// The status pills take their status type's colour (DqMijnWerk "In behandeling" purple).
		expect(widget('simple-continue').content.pill.colorField).toBe('status.colour')
		expect(widget('followed-cases').content.pill.colorField).toBe('status.colour')
		// DqMijnTeam Wachtrij van uw team too.
		expect(widget('your-teams-queue').content.ruleAbove).toBe(true)
		// DqMijnTeam "Oppakken" is the outlined row action (nextcloud-vue 24t1o opt-in).
		expect(widget('your-teams-queue').content.rowAction.variant).toBe('outlined')
	})

	it('ends a Verder werken row with the chevron, and only that list among the dashboard lists', () => {
		// DqMijnWerk draws a chevron after the time on each Verder werken row; no
		// other list on DqMijnWerk, DqMijnTeam, DqDashboard or DqDagAfsluiten
		// does. The board look hides a dashboard row's chevron unless the list
		// asks for it (nextcloud-vue record-list `chevron: true`).
		expect(widget('simple-continue').content.chevron).toBe(true)
		for (const id of ['followed-cases', 'your-teams-queue', 'stalled-cases']) {
			expect(widget(id).content.chevron, id).not.toBe(true)
		}
	})

	it('shows recent activity without tag changes, each item in the tone of its app', () => {
		// DqMijnWerk Recente activiteit: a reaction primary, a publication
		// green, a handover grey; tag changes are not work.
		const content = widget('simple-recent-activity').content
		expect(content.excludeApps).toEqual(['systemtags'])
		expect(content.itemTones).toEqual({
			comments: 'info',
			files: 'success',
			openregister: 'neutral',
			dossiq: 'neutral',
		})
	})

	it('continues where the handler left off, over the recent lens the full dashboard already uses', () => {
		// "Verder werken" on the design: the cases this reader opened last.
		// OpenRegister's `_recent` lens carries its own order, so the widget
		// declares none, exactly like the full dashboard's Recently opened.
		const recent = original('Dashboard').config.widgets.find(
			(item) => item.id === 'recent-cases',
		)
		const cont = widget('simple-continue')
		expect(cont.type).toBe('record-list')
		expect(cont.content).toMatchObject({
			register: recent.content.source.register,
			schema: recent.content.source.schema,
			filter: recent.content.source.filter,
			limit: 3,
			rowRoute: 'CaseDetail',
			title: '{title}',
		})
		expect(cont.content.sort).toBeUndefined()
		// The pill and the meta line read NAMES through _extend, never uuids.
		expect(cont.content.extend).toEqual(['status', 'caseType'])
		expect(cont.content.pill.field).toBe('status.name')
		// A meta line of parts (nextcloud-vue 2.82.0): a case without a case
		// type still shows its number, as "2026-0082" rather than nothing.
		expect(cont.content.meta).toEqual({
			parts: ['{identifier}', '{caseType.title}'],
		})
		expect(caseFields.has('identifier')).toBe(true)
		expect(caseFields.has('caseType')).toBe(true)
		// When the reader opened it, from the lens, not the case's own change,
		// said as DqMijnWerk does: "10 min geleden", "vanochtend", "vrijdag"
		// (nextcloud-vue relative-ago-formatter).
		expect(cont.content.trailing).toEqual(['{@self.viewedAt|ago}'])
		// An unavailable lens says why, in the same words as the full tile.
		expect(cont.content.lensReasonTexts).toEqual(recent.content.lensReasonTexts)
		expect(Object.keys(cont.content.lensReasonTexts).length).toBeGreaterThan(0)
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
			route: 'Cases',
			query: { _view: 'board' },
		})
		const pageIds = new Set(builtSimple.pages.map((item) => item.id))
		for (const item of mine.layout.filter((cell) => cell.headerLink)) {
			expect(pageIds.has(item.headerLink.route), item.widgetId).toBe(true)
		}
	})

	it('names ONE case in First today, through the library banner, with the case page as the primary act', () => {
		// DqMijnWerk: "Vandaag eerst" names the case furthest past its
		// deadline: the first row of the ordered query is the banner's subject.
		const card = widget('simple-first-today')
		expect(card.type).toBe('banner')
		expect(simple.slots?.['widget-simple-first-today']).toBeUndefined()
		expect(registrySource).not.toContain('FirstTodayWidget')
		const open = card.content.actions.find((action) => action.primary === true)
		expect(open.route).toEqual({ name: 'CaseDetail', params: { id: '@subject.id' } })
		expect(card.content.visibleWhen.source.order).toEqual({ deadline: 'asc' })
		expect(card.content.visibleWhen.source.filter).toHaveProperty(['deadline[lt]'], '@today+1d')
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

	it('counts the cases in the steps title, as DqMijnWerk does', () => {
		// "Mijn 14 zaken per stap": `{count}` is the total the bar reports
		// under headerCount (nextcloud-vue widget-title-count).
		const bar = widget('simple-per-step')
		expect(bar.title).toBe('My {count} cases per step')
		expect(bar.content.headerCount).toBe(true)
		const nl = readJson('l10n', 'nl.json').translations
		expect(nl[bar.title]).toBe('Mijn {count} zaken per stap')
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

		// First today counts everything past its deadline, today included, and
		// its first link opens exactly those cases (the same filter).
		const card = widget('simple-first-today').content
		expect(card.visibleWhen.source.filter['deadline[lt]']).toBe('@today+1d')
		expect(card.actions[0].route.query['deadline[lt]']).toBe('@today+1d')
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
		expect(simple.slots?.['widget-simple-my-tasks']).toBeUndefined()
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

	// DqMijnWerk draws each link pill with the icon of the menu entry it
	// stands in for, so the pill reads as that entry.
	it('draws each view link with the icon of the menu entry it replaces', () => {
		const entries = []
		const walk = (items) => (items ?? []).forEach((entry) => {
			entries.push(entry)
			walk(entry.children)
		})
		walk(builtFull.menu)
		const menuIcon = (route) => entries.find((entry) => entry.route === route && entry.icon)?.icon
		for (const link of simple.config.viewLinks) {
			expect(link.icon, link.label).toBeTruthy()
			// A link to a saved view (an object route) replaces no menu entry by
			// route, so only a string route is held to its menu entry's icon.
			if (typeof link.route === 'string') {
				expect(link.icon, link.label).toBe(menuIcon(link.route))
			}
			expect(iconsSource, link.label).toContain(`\n\t${link.icon},\n`)
		}
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
		// A lag of the installed library is not a defect here (validate-manifest.js
		// tells them apart); an error of this app's own still fails.
		expect(run.stdout + run.stderr).toMatch(
			/PASS \(0 errors(\)| of this app's own)/,
		)
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

	it('says the count line and the search placeholder in Dutch', () => {
		// A Dutch reader saw "38 case types": the key was in the manifest and
		// in no catalogue, so the library drew it as written.
		const nl = JSON.parse(read('l10n', 'nl.json')).translations
		// DqZaaktypen: "14 zaaktypen · 9 meegeleverd, 5 eigen".
		expect(nl[list.config.headerMeta]).toBe('{total} zaaktypen · {count:shipped} meegeleverd, {count:own} eigen')
		// DqZaaktypen draws the field as "Zoek een zaaktype".
		expect(nl[list.config.searchPlaceholder]).toBe('Zoek een zaaktype')
	})

	it('counts the shipped and the own case types, current versions only, for the header line', () => {
		const current = { supersededBy: { isnull: true } }
		expect(list.config.headerMetaCounts).toEqual({
			shipped: { register: 'dossiq', schema: 'caseType', filter: { ...current, '@self': { owner: '__system__' } } },
			own: { register: 'dossiq', schema: 'caseType', filter: { ...current, '@self[owner][ne]': '__system__' } },
		})
	})

	it('names each case type shipped or own under its title, as DqZaaktypen does ("Meegeleverd · Woo")', () => {
		const nl = JSON.parse(read('l10n', 'nl.json')).translations
		const title = list.config.columns.find((column) => column.key === 'title')
		expect(title.secondary).toBe('{@self.owner} · {subject}')
		expect(title.secondaryLabels).toEqual({ '@self.owner': { __system__: 'Shipped', '*': 'Own case type' } })
		expect(nl.Shipped).toBe('Meegeleverd')
		expect(nl['Own case type']).toBe('Eigen zaaktype')
	})

	it('has an Actions menu with the import dialog the case type page already owns', () => {
		expect(list.config.headerActions).toEqual([
			expect.objectContaining({
				id: 'case-type-import',
				type: 'open-modal',
				target: 'CaseTypeImportDialog',
			}),
			expect.objectContaining({
				id: 'case-type-templates-reload',
				type: 'api-call',
				url: '/apps/dossiq/api/packages/reload',
				adminOnly: true,
			}),
		])
		expect(registrySource).toContain('CaseTypeImportDialog')
	})

	it('shows the term in words, the version as Version n and a status pill', () => {
		const column = (key) => list.config.columns.find((c) => c.key === key)
		expect(column('processingDeadline').formatter).toBe('processingTime')
		expect(column('version').formatter).toBe('versionLabel')
		expect(column('isDraft')).toMatchObject({
			label: 'Status',
			formatter: 'draftState',
			widget: 'badge',
		})
	})
})
