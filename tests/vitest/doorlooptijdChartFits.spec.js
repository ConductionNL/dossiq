/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The processing-time chart on the Doorlooptijd page draws its plot 233px in a
 * tile sized to its content, and carries its source line as a footnote under
 * the plot, as the DqDoorlooptijd board draws it (a 352px card; r32 live 296).
 * The Naleving cards' charts are 130px, the board's 203px cards (live 223).
 *
 * @spec openspec/specs/dashboard/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/manifest.json'), 'utf8'))
const page = manifest.pages.find((item) => item.id === 'Doorlooptijd')
const widget = page.config.widgets.find((item) => item.id === 'processing-time-monthly')

describe('the Doorlooptijd monthly chart', () => {
	it('draws a 233px plot in a tile sized to its content', () => {
		expect(widget.content.height).toBe(233)
		expect(page.config.layout.find((item) => item.widgetId === 'processing-time-monthly').sizeToContent).toBe(true)
	})

	it('draws the Naleving cards\' charts 130px', () => {
		for (const id of ['processing-time-charts-distribution', 'processing-time-charts-trend', 'processing-time-charts-throughput']) {
			expect(page.config.widgets.find((item) => item.id === id).content.height, id).toBe(130)
		}
	})

	it('puts its source line under the plot', () => {
		expect(widget.subtitle).toBeUndefined()
		expect(widget.content.footnote).toBe('Chart from the OpenRegister analysis layer. Dossiq works out the norm and what counts as within it.')
	})
})
