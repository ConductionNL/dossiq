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
const { STATUS_COLOURS } = require('../../src/utils/statusColour.js')
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

describe('CaseDetail — the identity row is four configured tiles', () => {
	// Ruben, 2026-09-12: "The widgets on top of the page should be actual KPI
	// widgets configured to show what they show... it should not be a custom
	// widget spanning an entire row."
	//
	// So every assertion here is about a widget the LIBRARY renders from
	// `content`. A `type` the library does not know falls back to the page's
	// `widget-<id>` slot, and a page with no such slot renders an empty cell
	// and logs nothing, which is the failure this file exists to catch.
	const TILES = [
		['case-tile-number', 'stat', 0, 2],
		['case-tile-type', 'stat', 2, 3],
		['case-tile-status', 'stat', 5, 3],
		['case-tile-assignee', 'stat', 8, 2],
		['case-tile-deadline', 'countdown', 10, 2],
	]

	it('has retired the custom band and its slot', () => {
		expect(widget('case-header')).toBeUndefined()
		expect(cells('case-header')).toHaveLength(0)
		expect(caseDetail().slots['widget-case-header']).toBeUndefined()
		// The IMPORT and the ENTRY, not the name: the registry keeps a comment
		// saying the three components went and why, and a bare name match would
		// read that explanation as the thing it explains.
		expect(registrySource).not.toContain('CaseHeaderRow.vue')
		expect(registrySource).not.toContain('CaseHeaderRow: {')
	})

	for (const [id, type, gridX, gridWidth] of TILES) {
		it(`declares ${id} as a configured ${type} widget`, () => {
			const entry = widget(id)
			expect(entry).toBeDefined()
			expect(entry.type).toBe(type)
			// A configured widget carries its config, not a registry key. An
			// empty `content` renders a tile with no label and no value, which
			// looks exactly like a tile whose data has not arrived.
			expect(Object.keys(entry.content ?? {}).length).toBeGreaterThan(0)
		})

		it(`places ${id} in the top row without spanning it`, () => {
			const placed = cells(id)
			expect(placed).toHaveLength(1)
			expect(placed[0].gridY).toBe(0)
			expect(placed[0].gridX).toBe(gridX)
			expect(placed[0].gridWidth).toBe(gridWidth)
			expect(placed[0].gridWidth).toBeLessThan(12)
			// A card widget draws its own label, so the wrapper header would
			// print the title twice.
			expect(placed[0].showTitle).toBe(false)
		})
	}

	it('fills the top row exactly, leaving no gap and no overhang', () => {
		const top = caseDetail().config.layout.filter((c) => c.gridY === 0)
		expect(top).toHaveLength(TILES.length)
		expect(top.reduce((sum, c) => sum + c.gridWidth, 0)).toBe(12)
		expect(Math.min(...caseDetail().config.layout.map((c) => c.gridY))).toBe(0)
	})

	it('resolves the case type through the register, not in JavaScript', () => {
		// The uuid-to-title resolve CaseHeaderRow did by hand. `emptyText` is
		// what keeps a raw uuid off the page when the lookup finds nothing: a
		// uuid under the title is a fact about the database, not about the case.
		const content = widget('case-tile-type').content
		expect(content.objectField.field).toBe('caseType')
		expect(content.objectField.resolve).toMatchObject({
			register: 'dossiq',
			schema: 'caseType',
			labelField: 'title',
		})
		expect(content.emptyText).toBeTruthy()
	})

	it('draws the status as a badge coloured by its own status type', () => {
		const content = widget('case-tile-status').content
		expect(content.display).toBe('badge')
		expect(content.objectField.field).toBe('status')
		expect(content.objectField.resolve).toMatchObject({
			register: 'dossiq',
			schema: 'statusType',
			labelField: 'name',
			variantField: 'colour',
		})
		// REQ-CT-19 asks for the case type's authored colour to reach this
		// badge. CnStatusBadge takes one of six variants, so every one of the
		// twelve palette names the schema enumerates must map to one, or an
		// authored purple silently falls back to the default grey pill.
		const map = content.objectField.resolve.variantMap
		expect(Object.keys(map).sort()).toEqual([...STATUS_COLOURS].sort())
		const variants = ['default', 'primary', 'success', 'warning', 'error', 'info']
		for (const [colour, variant] of Object.entries(map)) {
			expect(variants, `${colour} maps to a real badge variant`).toContain(
				variant,
			)
		}
		expect(content.emptyText).toBeTruthy()
	})

	it('still prints the case number, which subtitleField does not', () => {
		// REQ-CDV-14 asks for the number under the title through
		// `config.subtitleField`. The key is set and no detail-page code in
		// @conduction/nextcloud-vue reads it, so the number renders nowhere
		// unless a widget carries it. Assert BOTH: the declaration the
		// requirement names, and the tile that actually delivers it.
		expect(caseDetail().config.subtitleField).toBe('identifier')
		expect(widget('case-tile-number').content.objectField).toBe('identifier')
	})

	it('counts the deadline down in the bands the retired tile used', () => {
		const content = widget('case-tile-deadline').content
		expect(content.field).toBe('deadline')
		expect(content.thresholds).toEqual({ warn: 14, danger: 5 })
	})

	it('names only icons that src/icons.js registers', () => {
		// An unregistered name renders NO icon, not a fallback glyph (gate-60).
		// Both spellings matter: the wrapper header reads `widget.icon` and the
		// tile itself reads `content.icon`.
		const names = TILES.flatMap(([id]) => [
			widget(id).icon,
			widget(id).content.icon,
		]).filter(Boolean)
		expect(names.length).toBeGreaterThan(0)
		for (const name of names) {
			expect(iconsSource, `icon ${name} is not registered`).toContain(
				`import ${name} from 'vue-material-design-icons/${name}.vue'`,
			)
		}
	})

	it('has retired the Time left and Case type tiles from the page', () => {
		// Both facts read in the identity row. Leaving the old tiles beside it
		// would print each of them twice.
		for (const retired of ['case-kpi-time-left', 'case-kpi-casetype']) {
			expect(widget(retired), `${retired} is still declared`).toBeUndefined()
			expect(cells(retired), `${retired} is still placed`).toHaveLength(0)
		}
		// And no tab child names them either: a tabs entry is the third place
		// a widget id can hide, and it is not covered by the two above.
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
		// The widget half of this guard went with CaseHeaderRow. Nothing on the
		// page declares a trail now, so assert that across every widget rather
		// than against one that no longer exists.
		expect(JSON.stringify(caseDetail().config.widgets)).not.toContain(
			'breadcrumb',
		)
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
		// is six tabs and this is all of them, so what was a prefix assertion is
		// an exact one. Timeline is deliberately absent: the timeline is the
		// sidebar History tab (change case-timeline), and a body panel over the
		// same log would be the duplication that change exists to retire.
		expect(tabs().map((tab) => tab.widgetId)).toEqual([
			'case-core',
			'case-documents-panel',
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
		expect(panels.gridHeight).toBeGreaterThanOrEqual(14)
		// The left column runs to the bottom of the right one, so the page has
		// no reserved void under the strip (ADR-062: the cell is the budget).
		const bottom = (cell) => cell.gridY + cell.gridHeight
		const right = caseDetail().config.layout.filter((c) => c.gridX >= 8)
		expect(bottom(panels)).toBe(Math.max(...right.map(bottom)))
	})
})
