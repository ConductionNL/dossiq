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

	it('draws no widget Actions menu, as board DqProcesanalyse', () => {
		expect(page.config.showWidgetActions).toBe(false)
	})
})
