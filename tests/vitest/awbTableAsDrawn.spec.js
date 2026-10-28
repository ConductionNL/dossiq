/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqBeheerGeavanceerd "AWB-termijndefinities": the rows are two lines high
 * because "Geldig" wraps its range, the actions column is headed "Acties",
 * and the card's text starts under its description without an extra 8px.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const sfc = fs.readFileSync(path.resolve(__dirname, '../../src/views/settings/tabs/DeadlineDefinitionsTab.vue'), 'utf8')

describe('the AWB deadline definitions table as the board draws it', () => {
	it('lets the validity range wrap', () => {
		expect(sfc).not.toMatch(/\.deadline-definitions-tab__validity \{[^}]*white-space: nowrap;/)
	})

	it('heads the actions column with a visible label', () => {
		expect(sfc).not.toMatch(/<span class="hidden-visually">\{\{ t\('dossiq', 'Actions'\) \}\}<\/span>/)
		expect(sfc).toMatch(/<th scope="col">\s*\{\{ t\('dossiq', 'Actions'\) \}\}\s*<\/th>/)
	})

	it('adds no space above the tab content', () => {
		expect(sfc).toMatch(/\.deadline-definitions-tab \{\s*padding: 0 0 8px;/)
	})
})
