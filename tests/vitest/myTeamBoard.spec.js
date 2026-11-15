/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqMijnTeam: the second tile reads Te laat, as on the Dashboard, and
 * the two bar lists carry their titles, Zaken per status and Zaken per type.
 *
 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const home = manifest.pages.find((page) => page.id === 'MyWorkHome')
const team = home.config.views.find((view) => view.id === 'team')

describe('the My team view', () => {
	it('names the overdue tile Late', () => {
		const tile = team.widgets.find((widget) => widget.id === 'kpi-overdue')
		expect(tile.title).toBe('Late')
		expect(tile.content.label).toBe('Late')
	})

	it('shows the titles of the two bar lists', () => {
		for (const id of ['cases-by-status', 'cases-by-type']) {
			expect(team.layout.find((item) => item.widgetId === id).showTitle, id).toBe(true)
		}
	})
})
