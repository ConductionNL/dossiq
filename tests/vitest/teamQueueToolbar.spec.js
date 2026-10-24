/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqTeamwachtrij keeps the case type chips on the toolbar row, beside the
 * Filter button and the view switch, with the side panel next to the list.
 * The team queue view asks the library for that placement.
 *
 * @spec openspec/specs/add-work-queue/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const view = manifest.pages.find((page) => page.id === 'Cases').config.defaultSavedViews.find((item) => item.slug === 'team-queue')

describe('DqTeamwachtrij', () => {
	it('puts the case type chips on the toolbar row', () => {
		expect(view.overrides.quickFilterPlacement).toBe('toolbar')
	})

	it('keeps its side panel, which the toolbar placement sits beside', () => {
		expect(view.overrides.sidePanel.map((card) => card.id)).toEqual(['queue-team-today', 'queue-by-type'])
	})

	it('draws the case types as its chips, not the case list\'s lenses', () => {
		expect(view.overrides.quickFilters).toEqual([])
		expect(view.overrides.quickFiltersFrom).toMatchObject({ field: 'caseType', allLabel: 'All types' })
	})

	it('draws the bulk band only once rows are selected', () => {
		expect(view.overrides.bulkBandAlways).toBe(false)
	})
})
