/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The hours surface is a leaf PLACEMENT, not a query against humaniq.
 *
 * Three of `hours-onto-humaniq-leaf`'s scenarios are claims about
 * `src/manifest.json` and nothing else: no cross-app register query survives,
 * the widget keeps its cell, and no integration widget gates itself on
 * `requiredApp`. They had no test anywhere, and two carried no citation at
 * all. They are not browser-shaped, so they belong here rather than in an e2e
 * spec: a Playwright run cannot see a declaration on a page it never mounts,
 * which is exactly why the e2e citation on the first of them was withdrawn.
 *
 * Every rule below fails SILENTLY. A register query against an app that is not
 * installed answers 404 and the widget renders empty rather than raising. A
 * `requiredApp` on a leaf placement hides the widget on an install where the
 * leaf would have handled its own absence, so the surface is simply missing.
 * And a layout entry naming a widget that no longer exists resolves to nothing
 * and leaves a hole in the grid. None of those raise.
 *
 * @spec openspec/changes/hours-onto-humaniq-leaf/specs/case-hours-via-humaniq-leaf/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const MANIFEST_PATH = path.join(ROOT, 'src', 'manifest.json')
const manifestSource = fs.readFileSync(MANIFEST_PATH, 'utf8')
const manifest = JSON.parse(manifestSource)

/**
 * The CaseDetail page as the manifest declares it.
 *
 * @return {object} The page.
 */
function caseDetail() {
	return manifest.pages.find((page) => page.id === 'CaseDetail')
}

/**
 * Every widget of `type: "integration"` anywhere in the manifest.
 *
 * Walked rather than read off one page: the rule is "anywhere", and a widget
 * added to a page nobody thought of is the case it has to catch.
 *
 * @return {Array<object>} The integration widgets.
 */
function integrationWidgets() {
	const found = []
	const walk = (node) => {
		if (Array.isArray(node)) {
			node.forEach(walk)
			return
		}
		if (node === null || typeof node !== 'object') return
		if (node.type === 'integration') found.push(node)
		Object.values(node).forEach(walk)
	}
	walk(manifest)
	return found
}

describe('the hours surface is a leaf placement', () => {
	// @spec openspec/changes/hours-onto-humaniq-leaf/specs/case-hours-via-humaniq-leaf/spec.md#no-cross-app-register-query-survives
	it('queries no humaniq register from this manifest', () => {
		// The scenario's WHEN is literally a search of the file, so the
		// assertion is a search of the file. The widget used to be a stats
		// block summing humaniq/TimeEntry straight out of here, which answered
		// 404 and rendered empty on any install without humaniq.
		expect(
			manifestSource.includes('"register": "humaniq"'),
			'src/manifest.json must not query humaniq\'s register: the hours '
				+ 'surface is a placement of humaniq\'s own leaf, and a cross-app '
				+ 'register query renders empty rather than failing when the app '
				+ 'is absent',
		).toBe(false)
	})

	// @spec openspec/changes/hours-onto-humaniq-leaf/specs/case-hours-via-humaniq-leaf/spec.md#no-requiredapp-anywhere-on-an-integration-widget
	it('gates no integration widget on requiredApp', () => {
		const widgets = integrationWidgets()

		// A guard on the guard. If the walk stops finding integration widgets,
		// every assertion below passes over an empty list and this test starts
		// proving nothing at all.
		expect(
			widgets.length,
			'the manifest must still declare integration widgets, or this test '
				+ 'is asserting over an empty list',
		).toBeGreaterThan(0)

		expect(
			widgets.filter((widget) => 'requiredApp' in widget).map((w) => w.id),
			'no integration widget may declare requiredApp: a leaf whose app is '
				+ 'absent is never registered, so there is nothing to gate, and '
				+ 'the gate only hides the surface on installs that have it',
		).toEqual([])
	})

	// @spec openspec/changes/hours-onto-humaniq-leaf/specs/case-hours-via-humaniq-leaf/spec.md#the-leaf-reads-the-right-case
	it('declares no host object context, so the leaf derives it', () => {
		// REQ-HRS-002's manifest half. The runtime half, that the leaf filters
		// on `dossiq:case` and the case uuid, is not assertable from here and
		// is not claimed: see the withdrawn e2e citation, which the current
		// test could not back because its write and its read went through the
		// same leaf with the same literal.
		const declaring = integrationWidgets()
			.filter(
				(widget) =>
					'domainObjectType' in widget || 'domainObjectRef' in widget,
			)
			.map((widget) => widget.id)

		expect(
			declaring,
			'an integration widget must not declare domainObjectType or '
				+ 'domainObjectRef: the host forwards register, schema and objectId '
				+ 'and the leaf derives the literal, so a page whose register is '
				+ 'renamed cannot keep pointing at the old one',
		).toEqual([])
	})

	// @spec openspec/changes/hours-onto-humaniq-leaf/specs/case-hours-via-humaniq-leaf/spec.md#the-layout-entry-still-resolves
	it('keeps the hours widget its identity and its cell', () => {
		const page = caseDetail()
		const widget = page.config.widgets.find(
			(entry) => entry.id === 'case-kpis-hours',
		)
		const cell = page.config.layout.filter(
			(entry) => entry.widgetId === 'case-kpis-hours',
		)

		expect(widget, 'the case-kpis-hours widget must exist').toBeDefined()
		expect(
			widget.type,
			'case-kpis-hours must be the integration widget, not a stats block',
		).toBe('integration')
		expect(widget.integrationId).toBe('humaniq-hours')

		expect(
			cell.length,
			'exactly one layout entry must name case-kpis-hours: a layout entry '
				+ 'naming a widget that is gone resolves to nothing and leaves a '
				+ 'hole in the grid rather than an error',
		).toBe(1)
		expect(
			[cell[0].gridX, cell[0].gridWidth, cell[0].gridHeight],
			'the hours widget must keep its cell: right column, four wide, two high',
		).toEqual([8, 4, 2])

		// 🔴 gridY IS DELIBERATELY NOT PINNED, AND THE SCENARIO'S NUMBER IS
		// STALE. The scenario says gridY 8. The manifest says 10, and 8 is not
		// merely different, it is impossible: `initiator` sits at gridY 7 with
		// height 3, so it occupies rows 7 to 9 and an hours widget at 8 would
		// overlap it. The column above grew; the widget did not move out of its
		// place in it. Pinning an absolute row would redden this test every
		// time a neighbour changes height, which is churn rather than coverage,
		// so the invariant asserted instead is that it overlaps nothing.
		const others = page.config.layout.filter(
			(entry) => entry.widgetId !== 'case-kpis-hours',
		)
		const top = cell[0].gridY
		const bottom = top + cell[0].gridHeight
		const overlapping = others
			.filter((entry) => {
				const sameColumn
					= entry.gridX < cell[0].gridX + cell[0].gridWidth
					&& cell[0].gridX < entry.gridX + (entry.gridWidth ?? 0)
				const otherTop = entry.gridY
				const otherBottom = otherTop + (entry.gridHeight ?? 0)
				return sameColumn && otherTop < bottom && top < otherBottom
			})
			.map((entry) => `${entry.widgetId} at y${entry.gridY}`)

		expect(
			overlapping,
			'the hours widget must occupy a cell of its own: an overlapping '
				+ 'entry is rendered by the grid as a stacked or displaced widget '
				+ 'rather than reported',
		).toEqual([])
	})
})
