/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqAanMijToegewezen: the Assigned to me view draws the team's workload chips
 * and its cards, without the case list's lenses and without a bulk band until
 * cards are selected. Copying and deleting the selection stay in its Actions
 * menu.
 *
 * @spec openspec/specs/add-work-queue/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const overrides = manifest.pages.find((page) => page.id === 'Cases').config.defaultSavedViews.find((item) => item.slug === 'my-work').overrides

describe('DqAanMijToegewezen', () => {
	it('draws no lenses of the case list, only the covering chip the board puts on the Active line', () => {
		expect(overrides.quickFilters.map((chip) => chip.label)).toEqual(['Including work you cover'])
		expect(overrides.quickFilterPlacement).toBe('active')
	})

	it('draws the workload heading under the header and the team\'s name chips at the start of the toolbar row', () => {
		expect(overrides.slots['below-header']).toEqual(['WorkloadTeamHeading'])
		expect(overrides.slots['toolbar-start']).toEqual(['WorkloadTeamChips'])
	})

	it('draws the bulk band only once cards are selected', () => {
		expect(overrides.bulkBandAlways).toBe(false)
	})

	it('keeps the export and copy and delete for the selection in the Actions menu', () => {
		const menu = overrides.headerButtons.find((button) => button.action === 'actions-menu')
		expect(menu.items).toEqual(['header-actions', 'export', 'copy-selected', 'delete-selected'])
	})

	it('counts once, in the note after the breadcrumb', () => {
		expect(overrides.headerMeta).toContain('{total}')
		expect(overrides.countText).toBe('')
		expect(overrides.countSubtitle).toBeNull()
	})
})
