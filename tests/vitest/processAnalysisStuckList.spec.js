// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqProcesanalyse: each row of "Where cases get stuck" carries a second
 * line with the days and visits. A record list drawn as rows reads that line
 * from `meta`; a `text` template is read only by the feed presentation, so the
 * line never showed.
 *
 * @spec openspec/changes/archive/2026-07-14-process-mining-bottlenecks/specs/process-mining-bottlenecks/spec.md
 */

import { resolveEndpointRequest } from '@conduction/nextcloud-vue/src/composables/useEndpointSource.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const page = manifest.pages.find((candidate) => candidate.id === 'ProcessMiningDashboard')
const stuck = page.config.widgets.find((widget) => widget.id === 'pm-stuck')

describe('where cases get stuck', () => {
	it('puts the days and visits on the meta line of each row', () => {
		expect(stuck.content.presentation ?? 'rows').toBe('rows')
		expect(stuck.content.meta).toBe('Median {days} days, {visits} visits')
		expect(stuck.content.text).toBeUndefined()
	})

	it('lists the two phases where cases stick most, as board DqProcesanalyse draws them (r33: three)', () => {
		expect(stuck.content.limit).toBe(2)
	})

	it('draws its rows compact, as board DqProcesanalyse does (nextcloud-vue 24o density)', () => {
		expect(stuck.content.density).toBe('compact')
	})

	it('draws no widget Actions menu, as board DqProcesanalyse', () => {
		expect(page.config.showWidgetActions).toBe(false)
	})
})

describe('the Gemiddeld / Mediaan switch', () => {
	const matrix = page.config.widgets.find((widget) => widget.id === 'pm-phase-days')
	const statistic = page.config.pageFilters.find((filter) => filter.key === 'statistic')

	it('sits on the description line as a segmented control, opening on the mean', () => {
		expect(statistic).toMatchObject({ control: 'segmented', placement: 'description', default: 'mean' })
		expect(statistic.options.map((option) => option.value)).toEqual(['mean', 'median'])
	})

	it('names the group Maat, as the board does', () => {
		const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8')).translations
		expect(statistic.label).toBe('Measure')
		expect(nl[statistic.label]).toBe('Maat')
	})

	it('makes the matrix read the statistic the switch names, through the library', () => {
		const source = matrix.content.endpointSource
		expect(resolveEndpointRequest(source, { workspace: { statistic: 'median' } }).responsePath).toBe('phaseDays.median')
		expect(resolveEndpointRequest(source, { workspace: { statistic: 'mean' } }).responsePath).toBe('phaseDays.mean')
	})
})
