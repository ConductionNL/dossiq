/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The processing-time page sends its period preset id as `period`, and the
 * metrics endpoint reads only a month count (DoorlooptijdController: /^\d+m$/).
 * A preset id the endpoint refuses empties every widget on the page.
 *
 * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#T02
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const page = manifest.pages.find((candidate) => candidate.id === 'Doorlooptijd')
const range = page.config.dateRange

describe('the processing-time period switch', () => {
	it('names every preset by a month count the metrics endpoint reads', () => {
		for (const preset of range.presets) {
			expect(preset.id, `${preset.label} is refused by the metrics endpoint`).toMatch(/^\d+m$/)
		}
	})

	it('opens on a preset it offers', () => {
		expect(range.presets.map((preset) => preset.id)).toContain(range.default.preset)
	})

	it('carries a Dutch translation for every word suffix on its tiles', () => {
		const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8')).translations
		const suffixes = page.config.widgets
			.map((widget) => widget.content?.format?.suffix)
			.filter((suffix) => typeof suffix === 'string' && /[a-z]/i.test(suffix))
		expect(suffixes.length).toBeGreaterThan(0)
		for (const suffix of suffixes) {
			expect(nl[suffix], `"${suffix}" reads English on a Dutch dashboard`).toBeTruthy()
		}
	})

	it('draws no widget Actions menu, as board DqDoorlooptijd', () => {
		expect(page.config.showWidgetActions).toBe(false)
	})
})
