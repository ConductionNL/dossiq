/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The processing-time page's Maand / Kwartaal / Jaar switch groups the
 * averages over a one-year window; it does not shorten the window (Ruben,
 * 21 Oct). The switch writes `granularity`, which the metrics endpoint reads
 * (DoorlooptijdController: week, month, quarter or year); no widget sends a
 * period of its own, so every widget reads the endpoint's twelve months.
 *
 * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#T02
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const page = manifest.pages.find((candidate) => candidate.id === 'Doorlooptijd')

describe('the processing-time period switch', () => {
	const grouping = page.config.pageFilters.find((filter) => filter.key === 'granularity')

	it('groups per month, quarter or year, opening on Maand, on the description line', () => {
		expect(grouping).toMatchObject({ control: 'segmented', placement: 'description', default: 'month' })
		expect(grouping.options.map((option) => [option.value, option.label])).toEqual([['month', 'Month'], ['quarter', 'Quarter'], ['year', 'Year']])
		expect(page.config.dateRange).toBeUndefined()
	})

	it('sends the grouping to the monthly chart and no window to any widget', () => {
		const monthly = page.config.widgets.find((widget) => widget.id === 'processing-time-monthly')
		expect(monthly.content.endpointSource.params.granularity).toBe('@workspace.granularity?')
		expect(JSON.stringify(page.config.widgets)).not.toContain('@workspace.datePreset')
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
