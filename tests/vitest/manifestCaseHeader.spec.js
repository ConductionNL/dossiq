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

describe('CaseDetail — the identity row (task 2.1)', () => {
	it('declares case-header as a custom widget on the page', () => {
		const entry = widget('case-header')
		expect(entry).toBeDefined()
		expect(entry.type).toBe('custom')
	})

	it('leads the page as a full-width row of KPI cards', () => {
		// THIS REVERSES A DELIBERATE MOVE, so the reason it is safe this time is
		// written down. The strip was pulled OUT of full width because it was
		// mostly air: twelve columns and two rows for three to five short facts
		// laid out in a line, more than half of it empty on a real case. That
		// objection was about the LAYOUT of the facts, not their placement.
		//
		// Each fact is its own card now, with `flex: 1 1 0` in
		// CaseHeaderRow.vue, so the row divides evenly across the full width
		// instead of ending in dead space. The rail card it replaced is gone,
		// so the same facts are read once, across the top, where a handler
		// looks first.
		const placed = cells('case-header')
		expect(placed).toHaveLength(1)
		expect(placed[0].gridY).toBe(0)
		expect(placed[0].gridX).toBe(0)
		expect(placed[0].gridWidth).toBe(12)
		// A KPI strip, not a titled panel: the cards carry their own labels, so
		// a heading above them would name the group twice.
		expect(placed[0].showTitle).toBe(false)
		expect(Math.min(...caseDetail().config.layout.map((c) => c.gridY))).toBe(0)
	})

	it('resolves the widget through a page slot to a registered component', () => {
		// A `custom` widget renders through THREE declarations no build step
		// compares: the widget entry, the layout cell, and the slot mapping.
		// Miss the slot and the page renders an empty cell, silently.
		expect(caseDetail().slots['widget-case-header']).toBe('CaseHeaderRow')
		expect(registrySource).toContain('CaseHeaderRow:')
		expect(registrySource).toContain('CaseHeaderRow.vue')
	})

	it('names only icons that src/icons.js registers', () => {
		// An unregistered name renders NO icon, not a fallback glyph (gate-60).
		// The trail's crumb icon used to be in this list; the trail is gone, so
		// the card's own icon is the only one this widget names.
		const names = [widget('case-header').icon]
		for (const name of names) {
			expect(iconsSource, `icon ${name} is not registered`).toContain(
				`import ${name} from 'vue-material-design-icons/${name}.vue'`,
			)
		}
	})

	it('has retired the Time left and Case type tiles from the page', () => {
		// Both facts now read in the identity row. Leaving the tiles beside it
		// would print each of them twice.
		for (const retired of ['case-kpi-time-left', 'case-kpi-casetype']) {
			expect(widget(retired), `${retired} is still declared`).toBeUndefined()
			expect(cells(retired), `${retired} is still placed`).toHaveLength(0)
		}
		// And no tab child names them either: a tabs entry is the third place
		// a widget id can hide, and it is not covered by the two above. The
		// `_note` prose still names both, on purpose, because that is where
		// the reason they went lives.
		const tabIds = (widget('case-panels')?.content?.tabs ?? []).map(
			(tab) => tab.widgetId,
		)
		expect(tabIds).not.toContain('case-kpi-time-left')
		expect(tabIds).not.toContain('case-kpi-casetype')
	})

	it('keeps every layout cell pointing at a declared widget', () => {
		const declared = new Set(caseDetail().config.widgets.map((w) => w.id))
		for (const cell of caseDetail().config.layout) {
			expect(declared, `layout cell ${cell.id}`).toContain(cell.widgetId)
		}
	})

	it('carries the countdown thresholds the retired tile counted with', () => {
		expect(widget('case-header').props.thresholds).toEqual({
			warn: 14,
			danger: 5,
		})
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
		expect(widget('case-header').props.breadcrumbs).toBeUndefined()
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
		// were nine more behind them. There are none behind them now: this is
		// the whole strip, so what was a prefix assertion is an exact one.
		// Timeline is deliberately absent: the timeline is the sidebar History
		// tab (change case-timeline), and a body panel over the same log would
		// be the duplication that change exists to retire.
		//
		// Communication joined on 2026-09-13, promoted out of People. It is not
		// that duplication: the sidebar Email tab reads the mail leaf, this one
		// reads `contactmoment`, and they are two logs rather than one log twice.
		expect(tabs().map((tab) => tab.widgetId)).toEqual([
			'case-core',
			'case-documents-panel',
			'case-people-panel',
			'case-communication-panel',
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
		expect(panels.gridHeight).toBeGreaterThanOrEqual(14)
		// The left column runs to the bottom of the right one, so the page has
		// no reserved void under the strip (ADR-062: the cell is the budget).
		const bottom = (cell) => cell.gridY + cell.gridHeight
		const right = caseDetail().config.layout.filter((c) => c.gridX >= 8)
		expect(bottom(panels)).toBe(Math.max(...right.map(bottom)))
	})
})
