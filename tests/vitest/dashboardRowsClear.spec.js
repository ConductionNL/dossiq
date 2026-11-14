/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The rows the boards draw under the cards above them start below the lowest
 * of those cards (nextcloud-vue layout `clear`): DqMijnWerk "Zaken die u volgt"
 * and "Mijn archiefbeoordelingen" under My tasks and Recent activity, DqMijnTeam
 * "Zaken per status" and "Zaken per type" under the taller of the two cards
 * above. Without it each rose under its own column (r34 -189.8 / -23.8 / -28).
 *
 * @spec openspec/specs/dashboard-rows-clear/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const clears = (layout) => layout.filter((item) => item.clear === true).map((item) => item.widgetId).sort()

describe('dashboard rows that clear the rows above', () => {
	it('My work (simple menu): the followed cases and the archival reviews', () => {
		const layout = read('src/menu-layout.simple.json').pages[0].configPatch.views.mine.layout
		expect(clears(layout)).toEqual(['archival-reviews', 'followed-cases'])
	})

	it('My team: cases per status and per type', () => {
		const views = read('src/manifest.json').pages[1].config.views
		const team = views.find((view) => (view.id || view.key) === 'team')
		expect(clears(team.layout)).toEqual(['cases-by-status', 'cases-by-type'])
	})
})
