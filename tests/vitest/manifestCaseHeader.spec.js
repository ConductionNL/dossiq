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
		expect(manifest.pages).toHaveLength(43)
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

	it('places it on the first layout row, full width', () => {
		const placed = cells('case-header')
		expect(placed).toHaveLength(1)
		expect(placed[0].gridY).toBe(0)
		expect(placed[0].gridX).toBe(0)
		expect(placed[0].gridWidth).toBe(12)
		// A KPI-style cell draws its own label, so the grid heading would
		// print the title a second time above it.
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
		const names = [
			widget('case-header').icon,
			...widget('case-header')
				.props.breadcrumbs.map((crumb) => crumb.icon)
				.filter(Boolean),
		]
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

describe('CaseDetail — the breadcrumb back to Cases (task 3.1)', () => {
	it('declares two crumbs, Cases first and the case title last', () => {
		const crumbs = caseDetail().config.breadcrumbs
		expect(crumbs).toHaveLength(2)
		expect(crumbs[0].route).toBe('Cases')
		// The last crumb IS the current page, so it carries no target:
		// CnBreadcrumbs renders it unlinked with aria-current either way.
		expect(crumbs[1].route).toBeUndefined()
		expect(crumbs[1].field).toBe('title')
	})

	it('routes the first crumb at a page the manifest actually declares', () => {
		const routes = new Set(manifest.pages.map((page) => page.id))
		expect(routes).toContain(caseDetail().config.breadcrumbs[0].route)
	})

	it('hands the widget the same trail the page declares', () => {
		// Two declarations of one trail is a drift machine. CnDetailPage 2.41.0
		// reads no `breadcrumbs` key, so the widget renders it; the page key is
		// the shape the host will read. This is what keeps them equal until the
		// day the widget's copy can be deleted.
		expect(widget('case-header').props.breadcrumbs).toEqual(
			caseDetail().config.breadcrumbs,
		)
	})
})
