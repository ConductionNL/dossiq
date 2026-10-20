/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The processing-time chart on the Doorlooptijd page fits its tile and carries
 * its source line as a footnote under the plot, as the DqDoorlooptijd board
 * draws it. A pinned 260px plot was taller than its gridHeight 4 tile, which
 * cut it off and scrolled.
 *
 * @spec openspec/specs/dashboard/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/manifest.json'), 'utf8'))
const page = manifest.pages.find((item) => item.id === 'Doorlooptijd')
const widget = page.config.widgets.find((item) => item.id === 'dt-monthly')

describe('the Doorlooptijd monthly chart', () => {
	it('fits its tile instead of pinning a height', () => {
		expect(widget.content.height).toBeUndefined()
		expect(page.config.layout.find((item) => item.widgetId === 'dt-monthly').gridHeight).toBe(4)
	})

	it('puts its source line under the plot', () => {
		expect(widget.subtitle).toBeUndefined()
		expect(widget.content.footnote).toBe('Chart from the OpenRegister analysis layer. Dossiq works out the norm and what counts as within it.')
	})
})
