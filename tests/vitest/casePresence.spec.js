/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case page says who else has the case open.
 *
 * 🔴 IT IS A LIBRARY WIDGET TYPE, AND THIS APP SHIPS NO COMPONENT FOR IT. That
 * is the assertion that matters: `type` must be the library's own `presence`
 * and NOT a key in this app's registry. A registry key would mean twenty-one
 * apps each carrying their own twenty lines of heartbeat, each slightly
 * different, each needing an app release to fix a library bug. The test is
 * written the other way round from `casePlanTab.spec.js` on purpose: there the
 * registry entry is required, here its ABSENCE is.
 *
 * 🔴 A WIDGET DECLARED AND NOT PLACED IS DARK, AND DARK LOOKS LIKE WORKING.
 * CnDetailPage renders by layout, so a `widgets[]` entry with no `layout[]`
 * entry renders nowhere, with no warning and no failing test anywhere.
 *
 * 🔑 THE OVERLAP CHECK IS NOT DECORATION. Inserting a strip means every row
 * below it moves, and the id of a layout entry is unique within a PAGE and not
 * within the file — `"id": "3"` appears twelve times across the fifty-three
 * pages. A shift applied by id over the whole manifest rewrites some other
 * page's grid and reports a confident success. This is what catches it.
 *
 * @spec exclude The presence mechanism belongs to OpenRegister and is specified
 * there, not here (competitor-parity-2026-09 Q2.31). What this file asserts is
 * the dossiq side of it, which is manifest wiring rather than behaviour: that
 * the case page places the library's own `presence` widget and that this app
 * ships no component of its own for it.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')

const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const registrySource = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')

/** The case page. */
const caseDetail = manifest.pages.find((page) => page.id === 'CaseDetail')

/** The widget this change declares. */
const WIDGET = 'case-presence'

describe('the case page shows who else has it open', () => {
	it('declares the widget with the LIBRARY type, not a registry key', () => {
		const widget = caseDetail.config.widgets.find((entry) => entry.id === WIDGET)

		expect(widget, 'the widget is declared').toBeTruthy()
		expect(widget.type).toBe('presence')
	})

	it('🔴 ships no component of its own for it', () => {
		// The whole point: the widget, its heartbeat and its avatars live in
		// @conduction/nextcloud-vue, so a bug in them is fixed by a library
		// release rather than by one in each of the twenty-one apps.
		expect(registrySource).not.toContain('CnObjectPresenceWidget')
		expect(registrySource).not.toContain('useObjectPresence')
		expect(registrySource).not.toContain(`'${WIDGET}':`)
	})

	it('🔴 is PLACED in the layout, so it is not dark', () => {
		const placed = caseDetail.config.layout.filter(
			(entry) => entry.widgetId === WIDGET,
		)

		expect(placed).toHaveLength(1)
		expect(placed[0].gridWidth).toBeGreaterThan(0)
		expect(placed[0].gridHeight).toBeGreaterThan(0)
	})

	it('sits above the strips it belongs with, not at the bottom of the page', () => {
		const at = (widgetId) =>
			caseDetail.config.layout.find((entry) => entry.widgetId === widgetId)
				?.gridY

		// Who else is here is only useful BEFORE you start typing, so it reads
		// with the other strips rather than below nine rows of panels.
		expect(at(WIDGET)).toBeLessThan(at('case-panels'))
		expect(at(WIDGET)).toBeLessThan(at('cmmn-case-plan'))
	})

	it('🔴 no two widgets on the page share a cell', () => {
		const occupied = new Map()
		const clashes = []

		for (const entry of caseDetail.config.layout) {
			for (let y = entry.gridY; y < entry.gridY + entry.gridHeight; y++) {
				const row = occupied.get(y) ?? []
				for (const other of row) {
					const overlaps =
						entry.gridX < other.gridX + other.gridWidth
						&& other.gridX < entry.gridX + entry.gridWidth
					if (overlaps) {
						clashes.push(
							`${entry.widgetId} over ${other.widgetId} at row ${y}`,
						)
					}
				}
				row.push(entry)
				occupied.set(y, row)
			}
		}

		expect(clashes).toEqual([])
	})
})
