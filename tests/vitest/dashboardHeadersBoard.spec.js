/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The dashboard headers as boards DqDashboard, DqTermijnen and DqDoorlooptijd
 * draw them: no page Actions menu, no way back to the dashboard on the
 * processing-time page, and the Dashboard's info line about its layout.
 *
 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')

/**
 * Read a JSON file from the repository.
 *
 * @param {string} file Path under the repository root.
 * @return {object} The parsed document.
 */
function readJson(file) {
	return JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
}

const manifest = readJson('src/manifest.json')
const simple = readJson('src/menu-layout.simple.json')

/**
 * One page of the shipped manifest.
 *
 * @param {string} id The page id.
 * @return {object} The page entry.
 */
function page(id) {
	return manifest.pages.find((entry) => entry.id === id)
}

describe('the dashboard headers', () => {
	it.each(['TermijnDashboard', 'Doorlooptijd'])('%s draws no page Actions menu and no Edit button', (id) => {
		expect(page(id).config.showActionsMenu).toBe(false)
		expect(page(id).config.allowEdit).toBe(false)
	})

	it('leaves the way back to the dashboard to the menu on the processing-time page', () => {
		const ids = (page('Doorlooptijd').config.headerActions || []).map((action) => action.id)
		expect(ids).not.toContain('back-to-dashboard')
	})

	it('draws the Dashboard without a page Actions menu, with the layout note under the grid', () => {
		const overlay = simple.pages.find((entry) => entry.id === 'Dashboard')
		expect(overlay.config.showActionsMenu).toBe(false)

		const note = overlay.configAppend.widgets.find((widget) => widget.id === 'layout-note')
		expect(note).toMatchObject({ type: 'banner', content: { variant: 'info' } })
		// The board's sentences about the in-page Edit layout button are gone with the button.
		expect(note.content.text).not.toMatch(/Edit layout/)
		expect(overlay.config.editLabel).toBeUndefined()

		const placed = overlay.config.layout.find((item) => item.widgetId === 'layout-note')
		const lowest = Math.max(...overlay.config.layout.filter((item) => item !== placed).map((item) => item.gridY + item.gridHeight))
		expect(placed.gridY).toBeGreaterThanOrEqual(lowest)
		expect(placed.gridWidth).toBe(12)
	})

	it('sizes the two charts to their rows, as DqDashboard draws them (251px, not four grid rows)', () => {
		const overlay = simple.pages.find((entry) => entry.id === 'Dashboard')
		for (const id of ['cases-by-status', 'cases-by-type']) {
			const placed = overlay.config.layout.find((item) => item.widgetId === id)
			expect(placed.sizeToContent, id).toBe(true)
		}
	})

	it.each(['cases-by-status', 'cases-by-type'])('subtitles the %s chart with what it counts, as DqDashboard does', (id) => {
		// "Lopende zaken" under "Zaken per status" and "Zaken per type": both
		// charts count the open cases only (`isFinalStatus: false`). DqMijnTeam
		// draws the same charts without it, so the subtitle is the simple
		// Dashboard's, not the shared definition's.
		const overlay = simple.pages.find((entry) => entry.id === 'Dashboard')
		const patch = overlay.configPatch.widgets[id]
		expect(patch.subtitle).toBe('Open cases')
		expect(patch.content.dataSource.filter.isFinalStatus).toBe(false)
		expect(page('Dashboard').config.widgets.find((entry) => entry.id === id).subtitle).toBeUndefined()
	})

	it('counts the cases per status NAME, as DqDashboard and DqMijnTeam do', () => {
		// Status types are declared per case type, so every case type's
		// "Ontvangen" is its own id; the boards draw one Ontvangen bar.
		const aggregates = [
			page('Dashboard').config.widgets.find((entry) => entry.id === 'cases-by-status'),
			page('MyWorkHome').config.views.find((view) => view.id === 'team').widgets.find((entry) => entry.id === 'cases-by-status'),
			simple.pages.find((entry) => entry.id === 'Dashboard').configPatch.widgets['cases-by-status'],
		].map((widget) => widget.content.dataSource.aggregate)
		for (const aggregate of aggregates) {
			expect(aggregate).toMatchObject({ groupBy: 'status', mergeByLabel: true, labelResolve: { schema: 'statusType' } })
		}
	})
})
