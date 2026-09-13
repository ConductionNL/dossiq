/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case page's identity header, as the manifest declares it.
 *
 * Every assertion here guards a declaration that renders nothing when it is
 * wrong and says nothing about it: a `subtitleField` no host reads, a layout
 * cell pointing at a widget id that no longer exists, a tab whose widget was
 * never declared. The manifest validator checks SHAPES; it cannot check that
 * the shapes refer to each other.
 *
 * @spec openspec/changes/case-header/specs/case-dashboard-view/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
const panels = require('./helpers/casePanels.js')

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)

const registrySource = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')
const iconsSource = fs.readFileSync(path.join(ROOT, 'src', 'icons.js'), 'utf8')

/** The CaseDetail page as the manifest declares it. @return {object} The page. */
const caseDetail = () => manifest.pages.find((page) => page.id === 'CaseDetail')

/**
 * One widget of the CaseDetail page.
 *
 * @param {string} id The manifest widget id.
 * @return {object|undefined} The widget entry.
 */
const widget = (id) => caseDetail().config.widgets.find((entry) => entry.id === id)

/**
 * The layout cells that place one widget.
 *
 * @param {string} id The manifest widget id.
 * @return {Array<object>} The layout entries.
 */
function cells(id) {
	return caseDetail().config.layout.filter((cell) => cell.widgetId === id)
}

describe('CaseDetail — the case number under the title (task 1.1)', () => {
	it('names identifier as the subtitle field', () => {
		expect(caseDetail().config.subtitleField).toBe('identifier')
	})

	it('leaves the menu and the page count alone', () => {
		// The subtitle is one key on one page. A change that moves the page
		// count or the menu has done something else as well.
		//
		// 49, not 44: `pluggable-integration-registry` adds Integrations,
		// `contacts-domain` adds Contacts, ContactDetail and
		// OrganisationDetail, and `contacts-you-can-find` adds Organisations —
		// the index that makes OrganisationDetail reachable by something other
		// than a case that already names the company. The number is what makes
		// this assertion worth anything, so it is raised by exactly the pages
		// that were added rather than loosened to a range.
		expect(manifest.pages).toHaveLength(49)
		expect(
			manifest.menu.filter((entry) => entry.route === 'Cases'),
		).toHaveLength(1)
	})
})

describe('CaseDetail — the facts lead the page as loose tiles (task 2.1, revised 2026-09-13)', () => {
	// The identity row was one custom widget (`case-header`) drawing the
	// facts inside a single cell, which clipped them and read as one strip.
	// The facts are built-in tiles now, one per fact and each its own grid
	// cell, laid out the way the page was arranged by hand in Buildiq edit
	// mode; the tiles the handler did not need (status, assignee) were
	// dropped there, and both facts read in the Data tab.
	const TILES = ['case-kpi-number', 'case-kpi-casetype', 'case-kpi-deadline']

	it('declares no case-header custom widget any more', () => {
		expect(widget('case-header')).toBeUndefined()
		expect(cells('case-header')).toHaveLength(0)
		expect(caseDetail().slots?.['widget-case-header']).toBeUndefined()
		expect(registrySource).not.toContain('CaseHeaderRow: {')
	})

	it('leads the page with the tiles on row 0, each its own cell', () => {
		for (const id of TILES) {
			const [cell, ...more] = cells(id)
			expect(cell, `${id} has no cell`).toBeTruthy()
			expect(more, `${id} is placed twice`).toEqual([])
			expect(cell.gridY, `${id} is not on the first row`).toBe(0)
			expect(cell.showTitle, `${id} labels itself, so no grid heading`).toBe(false)
		}
	})

	it('reads each fact off the loaded record through a built-in type', () => {
		expect(widget('case-kpi-number').type).toBe('stat')
		expect(widget('case-kpi-number').content.objectField).toBe('identifier')
		expect(widget('case-kpi-casetype').type).toBe('stat')
		expect(widget('case-kpi-casetype').content.objectField.field).toBe('caseType')
		expect(widget('case-kpi-deadline').type).toBe('countdown')
		expect(widget('case-kpi-deadline').content.field).toBe('deadline')
	})

	it('names only icons that src/icons.js registers', () => {
		for (const id of TILES) {
			const icon = widget(id).icon
			expect(icon, `${id} names no icon`).toBeTruthy()
			expect(
				iconsSource.includes(`import ${icon} from 'vue-material-design-icons/${icon}.vue'`),
				`${icon} is not imported in src/icons.js`,
			).toBe(true)
		}
	})

	it('has retired the Time left tile, whose count the deadline tile carries', () => {
		// The countdown tile computes days left from the deadline the way the
		// old tile did, with the same bands: 14 days warns, 5 days is danger.
		expect(widget('case-kpi-time-left')).toBeUndefined()
		expect(cells('case-kpi-time-left')).toHaveLength(0)
		expect(widget('case-kpi-deadline').content.thresholds).toEqual({ warn: 14, danger: 5 })
	})

	it('keeps every layout cell pointing at a declared widget', () => {
		const ids = caseDetail().config.widgets.map((entry) => entry.id)
		for (const cell of caseDetail().config.layout) {
			expect(ids, `layout cell ${cell.id} points at ${cell.widgetId}, which is not declared`).toContain(cell.widgetId)
		}
	})
})

describe('CaseDetail — the breadcrumb is gone, deliberately (regression guard)', () => {
	it('declares no breadcrumb trail on the page or the widget', () => {
		// The trail's LAST crumb was the case title, rendered one line below the
		// page header that already printed that title. It read as a repeat
		// rather than as a location, and it cost the top of the page a row.
		//
		// This asserts the ABSENCE because the removal is a decision, not an
		// oversight: without it, the next reader finds a `_breadcrumbsNote`
		// explaining a key that is not there and re-adds the key.
		expect(caseDetail().config.breadcrumbs).toBeUndefined()
		// The identity row that once carried its own trail is gone as well.
		expect(widget('case-header')).toBeUndefined()
	})

	it('keeps the note that says why, so the removal is not re-litigated', () => {
		expect(caseDetail().config._breadcrumbsNote).toMatch(/REMOVED/)
	})
})

describe('CaseDetail — the tab strip reads in work order (task 4.1)', () => {
	/** @return {Array<object>} The tab entries, in declaration order. */
	function tabs() {
		return widget('case-panels').content.tabs
	}

	it('is the work a handler does, in order, and nothing else', () => {
		// Task 4.1 asked for the work tabs to LEAD the strip, because there
		// were nine more behind them. There are none behind them now: the strip
		// is seven tabs and this is all of them, so what was a prefix assertion is
		// an exact one. Timeline is deliberately absent: the timeline is the
		// sidebar History tab (change case-timeline), and a body panel over the
		// same log would be the duplication that change exists to retire.
		expect(tabs().map((tab) => tab.widgetId)).toEqual([
			'case-core',
			'case-files',
			'case-notes-panel',
			'case-people-panel',
			'case-work-panel',
			'case-related-panel',
			'case-objects-panel',
		])
	})

	it('still renders every collection that used to close the strip', () => {
		// REQ-CDV-16 named four tabs that could be empty and asked for them to
		// come last. Three of them are SECTIONS now and the fourth is gone from
		// the body entirely, so "last" no longer describes anything. What the
		// requirement was protecting is that they are still reachable, and that
		// is what this asserts: a fold that dropped one instead of moving it
		// would leave a shorter strip and a passing count.
		const stillOnThePage = {
			'case-sub-cases': 'Related',
			'case-locaties': 'Objects and locations',
			'case-calendar': 'Work',
			'case-objects': 'Objects and locations',
		}
		for (const [id, tab] of Object.entries(stillOnThePage)) {
			const where = panels.caseTabOf(id)
			expect(where, `${id} is not reachable from the strip`).toBeTruthy()
			expect(where.tab, `${id} is on the wrong tab`).toBe(tab)
		}

		// `case-decidesk-decisions` is the fourth, and it is the exception: it
		// was REMOVED rather than folded, because it duplicated the
		// Besluitvorming sidebar tab. Assert the sidebar half is still there,
		// or the surface is simply gone.
		expect(panels.caseWidget('case-decidesk-decisions')).toBeUndefined()
		expect(caseDetail().config.sidebar.tabs.map((tab) => tab.id)).toContain(
			'besluitvorming',
		)
	})

	it('names a declared widget in every tab', () => {
		const declared = new Set(caseDetail().config.widgets.map((w) => w.id))
		for (const tab of tabs()) {
			expect(declared, `tab ${tab.widgetId}`).toContain(tab.widgetId)
		}
	})

	it('places no tab child on the layout grid', () => {
		// A tab child that also has a layout cell renders TWICE: once in the
		// grid and once in its panel.
		const placed = new Set(caseDetail().config.layout.map((c) => c.widgetId))
		for (const tab of tabs()) {
			expect(
				placed,
				`${tab.widgetId} is both a tab and a grid cell`,
			).not.toContain(tab.widgetId)
		}
	})

	it('declares no tab whose widget type resolves to nothing', () => {
		// A `type: "custom"` widget resolves through the page's `widget-<id>`
		// slot, which CnDetailPage renders for LAYOUT items only. As a tab
		// child it renders nothing and logs nothing: CnTabsWidget dispatches
		// through CnDetailWidgetHost, which resolves by widget TYPE.
		for (const tab of tabs()) {
			expect(widget(tab.widgetId).type, `tab ${tab.widgetId}`).not.toBe(
				'custom',
			)
		}
	})

	it('grew case-panels to take the Data tab it absorbed', () => {
		const panels = cells('case-panels')[0]
		expect(cells('case-core')).toHaveLength(0)
		// Nine rows since the layout was laid out by hand in Buildiq edit mode
		// (2026-09-12): enough for the data grid without an inner scrollbar at
		// a laptop height, with the terms and the case plan resting straight
		// under it rather than a void (ADR-062: the cell is the budget).
		expect(panels.gridHeight).toBeGreaterThanOrEqual(8)
		const bottom = (cell) => cell.gridY + cell.gridHeight
		const under = caseDetail().config.layout.filter((c) => c.gridY === bottom(panels) && c.gridX < panels.gridX + panels.gridWidth)
		expect(under.map((c) => c.widgetId).sort()).toEqual(['case-terms', 'cmmn-case-plan'])
	})
})
