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
		['case-transitions', 'widget-case-transitions', 'CaseTransitionsWidget'],
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

	it('leads the page with the transition strip, above the KPI row', () => {
		const strip = cells('case-transitions')[0]
		expect(strip.gridY).toBe(0)
		expect(strip.gridWidth).toBe(12)
		const timeLeft = cells('case-kpi-time-left')[0]
		expect(timeLeft.gridY).toBeGreaterThanOrEqual(strip.gridY + strip.gridHeight)
	})

	it('gives the stepper the cell the milestone tile had', () => {
		const steps = cells('case-steps')[0]
		expect(steps.gridX).toBe(8)
		expect(steps.gridWidth).toBe(4)
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
			widget('case-transitions').icon,
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
