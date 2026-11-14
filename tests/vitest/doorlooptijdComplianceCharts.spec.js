/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqDoorlooptijd draws the three charts in the Naleving card 140px high with
 * nothing under them. ApexCharts adds 15px under a chart (parentHeightOffset),
 * so a chart set to 130 took 145 and the Doorstroom card stood 5px taller
 * than drawn (r34 Wettelijke Woo-termijnen y +6).
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const page = manifest.pages.find((candidate) => candidate.id === 'Doorlooptijd')

describe('the Naleving charts', () => {
	for (const title of ['Processing Time Distribution', 'Monthly SLA Trend', 'Throughput (cases closed per week)']) {
		it(`draws "${title}" 140px high with no room added under it`, () => {
			const chart = page.config.widgets.find((widget) => widget.title === title)
			expect(chart.content.height).toBe(140)
			expect(chart.content.options.chart.parentHeightOffset).toBe(0)
		})
	}
})
