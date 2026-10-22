/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The search page as DqZoekresultaten draws it: the kind chips share one row
 * with the Filter button and the view switch, and no row of saved-view chips
 * sits under them (the saved search shows in its side card).
 *
 * @spec openspec/specs/semantic-case-intake/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const page = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.d', '89-search.json'), 'utf8')).pages[0]

describe('DqZoekresultaten', () => {
	it('puts the kind chips on the toolbar row', () => {
		expect(page.config.quickFilterPlacement).toBe('toolbar')
	})

	it('draws no saved-view chips, and keeps saved searches with their side card', () => {
		expect(page.config.savedViewChips).toBe(false)
		expect(page.config.allowSavedViews).toBe(true)
		expect(page.config.sidePanel.map((card) => card.id)).toEqual(['search-saved', 'search-exports'])
	})
})
