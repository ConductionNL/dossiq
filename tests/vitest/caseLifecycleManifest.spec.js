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

describe('CaseDetail: the timeline widget IS the transition surface', () => {
	it('declares case-stages as the library stages widget, configured', () => {
		// `stages` is a LIBRARY key, so it resolves through the dashboard widget
		// catalog and needs no registry entry and no page slot. A typo here does
		// not error: an unknown type falls back to the `widget-<id>` slot, the
		// page declares none, and the cell renders empty in silence.
		const entry = widget('case-stages')
		expect(entry).toBeTruthy()
		expect(entry.type).toBe('stages')
		expect(caseDetail().slots['widget-case-stages']).toBeUndefined()
	})

	it('reads the case type blueprint, not the type own status rows', () => {
		// A case type that derives its lifecycle from a parent carries no
		// statusType rows of its own, so `statusType where caseType = X` said
		// "no statuses yet" about a type that plainly has four. /blueprint
		// merges the chain server-side.
		const source = widget('case-stages').content.stagesEndpoint
		expect(source.url).toBe(
			'/apps/dossiq/api/case-types/@object.caseType/blueprint',
		)
		expect(source.path).toBe('statusTypes')
		expect(source.orderField).toBe('order')
		expect(source.finalField).toBe('isFinal')
		expect(source.labelField).toBe('name')
	})

	it('moves the case through the lifecycle, never by writing the field', () => {
		// `{ kind: 'field' }` writes `currentField` straight onto the record
		// with nothing validating the move: whatever the timeline offers is
		// what happens. `lifecycle` asks OpenRegister what is reachable and
		// lets it re-validate the write, which is dossiq's own guarded engine
		// answering through CaseActionProvider.
		const content = widget('case-stages').content
		expect(content.transition).toEqual({ kind: 'lifecycle' })
		expect(content.currentField).toBe('status')
		expect(content.unreachableReason).toBeTruthy()
	})

	it('places the timeline in one cell, where the stepper stood', () => {
		const placed = cells('case-stages')
		expect(placed).toHaveLength(1)
		// The right rail is three columns wide in Ruben's layout (2026-09-12).
		expect(placed[0].gridX).toBe(9)
		expect(placed[0].gridWidth).toBe(3)
		// WITH its title. A bare column of labelled dots in the right rail says
		// nothing about what the column is, so a reader has to infer that it is
		// the case progressing rather than, say, a checklist.
		expect(placed[0].showTitle).toBe(true)
	})

	it('has retired the transition strip and its component', () => {
		// Ruben, 2026-09-12, on the transition buttons: "let drop it, and make
		// clicking a status in the timeline widget set that status." So the
		// page names no actions component, no widget, no cell and no slot, and
		// the registry holds no entry. Any one of those left behind renders a
		// second way to move the case beside the timeline.
		// The actions slot may hold the headless requester projection, never
		// the transition strip.
		expect(caseDetail().actionsComponent ?? '').not.toBe('CaseTransitionsWidget')
		expect(widget('case-transitions')).toBeUndefined()
		expect(cells('case-transitions')).toHaveLength(0)
		expect(caseDetail().slots['widget-case-transitions']).toBeUndefined()
		expect(registrySource).not.toContain('CaseTransitionsWidget.vue')
		expect(registrySource).not.toContain('CaseTransitionsWidget: {')
	})

	it('has retired the custom stepper and its component', () => {
		expect(widget('case-steps')).toBeUndefined()
		expect(cells('case-steps')).toHaveLength(0)
		expect(caseDetail().slots['widget-case-steps']).toBeUndefined()
		expect(registrySource).not.toContain('CaseStepsWidget.vue')
		expect(registrySource).not.toContain('CaseStepsWidget: {')
	})

	it('leads with the identity tiles, and the panels sit straight under them', () => {
		// What survived every move of this row is the reading order: a handler
		// sees WHICH case they are on before WHAT they may do to it. The tiles
		// are the top row and the panels take the rows under it, with no gutter
		// row between.
		const tiles = caseDetail().config.layout.filter((c) => c.gridY === 0)
		const panels = cells('case-panels')[0]
		expect(tiles.length).toBeGreaterThan(1)
		expect(panels.gridY).toBe(Math.max(...tiles.map((c) => c.gridHeight)))
		expect(panels.gridX).toBe(0)
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
			widget('case-stages').icon,
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
