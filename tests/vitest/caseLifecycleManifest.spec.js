/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case page's lifecycle wiring, as three files have to agree on it.
 *
 * A `custom` widget renders through THREE declarations that no build step
 * compares: the widget entry in `config.widgets`, the layout cell that places
 * it, and the `slots` mapping from `widget-<id>` to a registry key. Miss the
 * slot and the page renders an empty cell; miss the layout and the widget is
 * declared and never placed. Both are green on every gate — the manifest
 * validator only checks shapes, and the registry only checks that a key it is
 * asked for exists.
 *
 * The same holds for an icon: CnAppNav and the widget headers resolve an
 * `icon` through registerIcons(), and a name that is not in src/icons.js
 * renders NO icon rather than a fallback glyph, silently (hydra gate-60).
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 * @spec openspec/specs/case-dashboard-view/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const MANIFEST_PATH = path.join(ROOT, 'src', 'manifest.json')
const ICONS_PATH = path.join(ROOT, 'src', 'icons.js')
const REGISTRY_PATH = path.join(ROOT, 'src', 'registry.js')

const manifest = JSON.parse(fs.readFileSync(MANIFEST_PATH, 'utf8'))
const iconsSource = fs.readFileSync(ICONS_PATH, 'utf8')
const registrySource = fs.readFileSync(REGISTRY_PATH, 'utf8')

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

/**
 * One header action of the CaseDetail page.
 *
 * @param {string} id The action id.
 * @return {object|undefined} The action entry.
 */
function action(id) {
	return caseDetail().config.headerActions.find((entry) => entry.id === id)
}

describe('CaseDetail: the transition strip and the stepper', () => {
	for (const [id, slot, component] of [
		['case-steps', 'widget-case-steps', 'CaseStepsWidget'],
	]) {
		it(`declares ${id} as a custom widget`, () => {
			expect(widget(id)).toBeTruthy()
			expect(widget(id).type).toBe('custom')
		})

		it(`places ${id} in exactly one layout cell`, () => {
			expect(cells(id)).toHaveLength(1)
		})

		it(`binds ${id} to ${component} through the page slot`, () => {
			expect(caseDetail().slots[slot]).toBe(component)
		})

		it(`registers ${component} as a widget`, () => {
			expect(registrySource).toContain(`${component}: {`)
			expect(registrySource).toContain(`component: ${component},`)
		})
	}

	it('mounts the transition buttons in the page header, not in the grid', () => {
		// The buttons sit in the header's action row, left of Edit, through
		// the page's `actionsComponent`. They were a grid widget under the
		// identity row: a status chip the Status card already showed, and the
		// one button a handler reaches for mid call, two rows below the title.
		// Nothing of that strip may survive in the grid, or the page shows the
		// buttons twice.
		expect(caseDetail().actionsComponent).toBe('CaseTransitionsWidget')
		expect(widget('case-transitions')).toBeUndefined()
		expect(cells('case-transitions')).toHaveLength(0)
		expect(caseDetail().slots['widget-case-transitions']).toBeUndefined()
		expect(registrySource).toContain('CaseTransitionsWidget: {')
		expect(registrySource).toContain('component: CaseTransitionsWidget,')
	})

	it('leads with three loose KPI tiles, and the panels sit straight under them', () => {
		// The case's facts lead the page as three cards, one per fact, each its
		// own grid cell on row 0, with the hours card beside them.
		//
		// It has been three ways. The facts were a full-width band, then a
		// titled card in the right rail, then one `case-header` widget drawing
		// five cards inside a single two-row cell, which clipped them and read
		// as one strip. Five cells is what "individual KPI cards" means to the
		// grid: each has its own chrome and Buildiq edit mode can move each.
		// What survived every move is the reading order: a handler sees WHICH
		// case they are on before WHAT they may do to it. The panels take the
		// rows straight under the tiles, with no gutter row between.
		const tiles = caseDetail().config.layout
			.filter((cell) => cell.widgetId.startsWith('case-kpi-'))
			.sort((a, b) => a.gridX - b.gridX)
		expect(tiles.map((cell) => cell.widgetId)).toEqual([
			'case-kpi-number',
			'case-kpi-casetype',
			'case-kpi-deadline',
		])
		let x = 0
		for (const cell of tiles) {
			expect([cell.widgetId, cell.gridY], 'every tile sits on row 0').toEqual([cell.widgetId, 0])
			expect([cell.widgetId, cell.gridX], 'the tiles abut, in order').toEqual([cell.widgetId, x])
			x += cell.gridWidth
		}
		// The hours card heads the right column beside them, so the row is full.
		const hours = cells('case-kpis-hours')[0]
		expect([hours.gridX, hours.gridY]).toEqual([x, 0])
		expect(x + hours.gridWidth, 'the three tiles and the hours card fill the twelve columns').toBe(12)
		// The status and the assignee are not tiles: both read in the Data tab.
		expect(widget('case-kpi-status')).toBeUndefined()
		expect(widget('case-kpi-assignee')).toBeUndefined()
		expect(widget('case-header'), 'the one-cell identity row is gone').toBeUndefined()
		expect(cells('case-header')).toHaveLength(0)

		const panels = cells('case-panels')[0]
		expect(panels.gridY).toBe(tiles[0].gridY + tiles[0].gridHeight)
		expect(panels.gridX).toBe(0)
	})

	it('reads each KPI off the loaded record, resolving references to names', () => {
		// Built-in first (ADR-049): `stat` in object-field mode and `countdown`
		// read the record the page already loaded, so the row costs no request
		// of its own and no custom component. A reference field holds a uuid,
		// which is not something to show a person, so the two references name
		// the register and schema to resolve the label in.
		expect(widget('case-kpi-number').type).toBe('stat')
		expect(widget('case-kpi-number').content.objectField).toBe('identifier')
		expect(widget('case-kpi-casetype').content.objectField).toEqual({
			field: 'caseType',
			resolve: { register: 'dossiq', schema: 'caseType', labelField: 'title' },
		})
		expect(widget('case-kpi-deadline').type).toBe('countdown')
		expect(widget('case-kpi-deadline').content.field).toBe('deadline')
	})

	it('keeps the stepper at the head of the right column, under the hours card', () => {
		// The right column is three wide now and starts at column 9, with the
		// hours card on row 0 and the stepper straight under it.
		const steps = cells('case-steps')[0]
		const hours = cells('case-kpis-hours')[0]
		expect(steps.gridX).toBe(9)
		expect(steps.gridWidth).toBe(3)
		expect(steps.gridY).toBe(hours.gridY + hours.gridHeight)
	})

	it('has retired the milestone progress tile from this page', () => {
		expect(widget('case-kpi-progress')).toBeUndefined()
		expect(cells('case-kpi-progress')).toHaveLength(0)
		expect(JSON.stringify(caseDetail())).not.toContain('milestones/progress')
	})

	it('has retired the lifecycleActions block that could not render', () => {
		expect(caseDetail().config.lifecycleActions).toBeUndefined()
	})

	it('fills its rows: every cell fits the twelve-column grid, none overlap', () => {
		const grid = new Map()
		for (const cell of caseDetail().config.layout) {
			expect(cell.gridX + cell.gridWidth, cell.widgetId).toBeLessThanOrEqual(
				12,
			)
			for (let y = cell.gridY; y < cell.gridY + cell.gridHeight; y++) {
				for (let x = cell.gridX; x < cell.gridX + cell.gridWidth; x++) {
					const key = `${x},${y}`
					expect(
						grid.has(key),
						`${cell.widgetId} overlaps ${grid.get(key)} at ${key}`,
					).toBe(false)
					grid.set(key, cell.widgetId)
				}
			}
		}
	})
})

describe('CaseDetail: suspend, resume, extend and reopen', () => {
	const gestures = [
		['case-suspend', 'suspend', 'neq'],
		['case-resume', 'resume', 'neq'],
		['case-extend', 'extend', 'neq'],
		['case-reopen', 'reopen', 'eq'],
	]

	for (const [id, gesture, op] of gestures) {
		it(`opens the reason dialog for ${gesture}`, () => {
			expect(action(id)).toBeTruthy()
			expect(action(id).type).toBe('open-modal')
			expect(action(id).target).toBe('CaseLifecycleActionDialog')
			expect(action(id).props.action).toBe(gesture)
		})

		it(`gates ${gesture} on the case record's own isFinalStatus`, () => {
			// LOCAL mode only. An `endpoint` predicate is fetched verbatim (no
			// @objectId interpolation) and an OpenRegister `source` predicate
			// filtered by id reads the WHOLE table, so either would answer about
			// a case that is not this one.
			expect(action(id).visibleWhen).toEqual({
				field: 'isFinalStatus',
				op,
				value: true,
			})
			expect(action(id).visibleWhen.endpoint).toBeUndefined()
			expect(action(id).visibleWhen.source).toBeUndefined()
		})
	}

	it('registers the dialog as a modal, which open-modal requires', () => {
		// dispatchAction refuses a target whose registry kind is not "modal",
		// with a console warning and no dialog. Four dead menu entries.
		expect(registrySource).toMatch(
			/CaseLifecycleActionDialog: \{\s*\n\s*kind: 'modal',/,
		)
	})
})

describe('CaseDetail: every icon it names is registered', () => {
	it('registers each icon the new widgets and actions use', () => {
		const named = [
			widget('case-steps').icon,
			action('case-suspend').icon,
			action('case-resume').icon,
			action('case-extend').icon,
			action('case-reopen').icon,
		]
		for (const name of named) {
			expect(name, 'every new widget and action names an icon').toBeTruthy()
			expect(
				iconsSource.includes(
					`import ${name} from 'vue-material-design-icons/${name}.vue'`,
				),
				`${name} is imported in src/icons.js`,
			).toBe(true)
			expect(
				iconsSource.includes(`\n\t${name},`),
				`${name} is exported in src/icons.js`,
			).toBe(true)
		}
	})
})
