/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The family plan surface: that it is declared, that it is PLACED, and that
 * nothing else on the case page claims to be it.
 *
 * 🔴 A WIDGET THAT IS DECLARED AND NOT PLACED IS DARK, AND DARK LOOKS EXACTLY
 * LIKE WORKING. CnDetailPage renders by LAYOUT, so a `widgets[]` entry with no
 * `layout[]` entry beside it renders nowhere at all, with no console warning
 * and no failing test anywhere. The whole of this change's front end would be
 * invisible and every backend test would still be green. So the placement is
 * asserted, and so is the absence of an overlap: two widgets on the same grid
 * rows are two widgets drawn over each other.
 *
 * 🔴 THE SECOND PROPERTY IS THAT IT IS NOT THE OTHER PLAN. `cmmn-case-plan`
 * renders OpenRegister's case layer, the stages and milestones every case type
 * has. This one is the Jeugdwet gezinsplan, what one household agreed to work
 * on. Two things called a plan, and the ids are the only thing keeping them
 * apart, so the test names both and asserts they are different widgets.
 *
 * 🔑 IT ASSERTS NO BEHAVIOUR OF THE PANEL. Overdue, due-for-review and the
 * provider party are computed on the SERVER, and they are tested there
 * (`InterventionProviderTest`, `CasePlanReviewTest`). A copy of those
 * assertions here would be a second answer to a question the panel does not
 * own, and it would go on passing after the server's answer changed.
 *
 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')

const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const registrySource = fs.readFileSync(
	path.join(ROOT, 'src', 'registry.js'),
	'utf8',
)

/** The case page, which is where a family plan is read. */
const caseDetail = manifest.pages.find(page => page.id === 'CaseDetail')

/** The widget this change adds. */
const WIDGET = 'family-plan'

/** The adaptive case plan, which is a different thing entirely. */
const ADAPTIVE = 'cmmn-case-plan'

describe('the family plan reaches the case page', () => {
	it('is declared as a widget with its own registry type', () => {
		const widget = caseDetail.config.widgets.find(entry => entry.id === WIDGET)

		expect(widget, 'the widget is declared').toBeTruthy()
		// The type names a REGISTRY KEY rather than "custom": CnDetailPage
		// resolves a grid item's renderer from `cnRegistry[widget.type]` when
		// the app supplies no `widget-<id>` slot, and dossiq supplies none.
		expect(widget.type).toBe(WIDGET)
		expect(registrySource).toContain(`'${WIDGET}': {`)
		expect(registrySource).toContain('CasePlanSociaalDomeinPanel')
	})

	it('🔴 is PLACED in the layout, so it is not dark', () => {
		const placed = caseDetail.config.layout.filter(
			entry => entry.widgetId === WIDGET,
		)

		expect(placed).toHaveLength(1)
		expect(placed[0].gridWidth).toBeGreaterThan(0)
		expect(placed[0].gridHeight).toBeGreaterThan(0)
	})

	it('🔴 does not overlap another widget on the grid', () => {
		const mine = caseDetail.config.layout.find(
			entry => entry.widgetId === WIDGET,
		)
		const myRows = new Set()
		for (let y = mine.gridY; y < mine.gridY + mine.gridHeight; y++) {
			myRows.add(y)
		}

		const overlapping = caseDetail.config.layout
			.filter(entry => entry.widgetId !== WIDGET)
			.filter((entry) => {
				for (let y = entry.gridY; y < entry.gridY + entry.gridHeight; y++) {
					if (myRows.has(y)) {
						return true
					}
				}
				return false
			})
			.map(entry => entry.widgetId)

		expect(overlapping).toEqual([])
	})

	it('🔴 is not the adaptive case plan, which is a different widget', () => {
		const ids = caseDetail.config.widgets.map(entry => entry.id)

		expect(ids).toContain(WIDGET)
		expect(ids).toContain(ADAPTIVE)
		expect(WIDGET).not.toBe(ADAPTIVE)
	})
})

describe('the cross-domain lookup is offered and gated on a ground', () => {
	it('is a header action opening the dialog, not an api-call', () => {
		const action = caseDetail.config.headerActions.find(
			entry => entry.id === 'cross-domain-lookup',
		)

		expect(action, 'the action is declared').toBeTruthy()
		// An api-call would fire on click with a fixed body, which is exactly
		// the shape a ground chosen BEFORE the answer rules out.
		expect(action.type).toBe('open-modal')
		expect(action.target).toBe('CrossDomainLookupDialog')
		expect(registrySource).toContain('CrossDomainLookupDialog: {')
	})

	it('🔴 carries no ground of its own, so the server owns the list', () => {
		const action = caseDetail.config.headerActions.find(
			entry => entry.id === 'cross-domain-lookup',
		)

		// A payload naming a ground here would be a ground nobody chose, and a
		// second copy of a list that lives on the server.
		expect(action.payload).toBeUndefined()
		expect(action.values).toBeUndefined()
	})
})
