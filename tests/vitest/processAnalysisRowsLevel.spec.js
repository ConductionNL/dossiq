/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqProcesanalyse draws its four KPI tiles at one height and "Dwell time
 * by status" as tall as the throughput chart beside it. Measured to their
 * content, live drew the tiles 89/89/89/110 and the bar list 231 beside a 300
 * chart, and the bar list rose under the shorter tiles (r36 y -27). The
 * placements ask nextcloud-vue to share the row's height (`sameHeight`).
 *
 * @spec openspec/changes/archive/2026-07-14-process-mining-bottlenecks/specs/process-mining-bottlenecks/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const page = manifest.pages.find((candidate) => candidate.id === 'ProcessMiningDashboard')
const placement = (widgetId) => page.config.layout.find((item) => item.widgetId === widgetId)

describe('process analysis rows', () => {
	it('draws the four KPI tiles at one height', () => {
		const tiles = ['pm-kpis', 'pm-kpis-case-types', 'pm-kpis-rework', 'pm-kpis-bottleneck'].map(placement)
		expect(new Set(tiles.map((tile) => tile.gridY)).size).toBe(1)
		for (const tile of tiles) {
			expect(tile.sameHeight).toBe(true)
			expect(tile.sizeToContent).toBe(true)
		}
	})

	it('draws the dwell bars as tall as the throughput chart beside them', () => {
		const dwell = placement('pm-dwell')
		const throughput = placement('pm-throughput')
		expect(dwell.gridY).toBe(throughput.gridY)
		expect(dwell.sameHeight).toBe(true)
		expect(throughput.sameHeight).toBe(true)
	})
})

describe('throughput page rows', () => {
	const doorlooptijd = manifest.pages.find((candidate) => candidate.id === 'Doorlooptijd')
	const at = (widgetId) => doorlooptijd.config.layout.find((item) => item.widgetId === widgetId)

	it('draws "Zaken met risico" as tall as "Prestaties per zaaktype" beside it, as DqDoorlooptijd (r36 340 vs 396)', () => {
		expect(at('processing-time-at-risk').gridY).toBe(at('processing-time-breakdown').gridY)
		expect(at('processing-time-at-risk').sameHeight).toBe(true)
		expect(at('processing-time-breakdown').sameHeight).toBe(true)
	})
})
