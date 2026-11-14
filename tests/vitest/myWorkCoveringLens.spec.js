// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
/**
 * Assigned to me includes the work the reader covers for an absent colleague,
 * as a lens that can be switched off from the Active line (board
 * DqAanMijToegewezen, "Inclusief werk waarvoor u waarneemt"). The server
 * computes the covered cases: the lens asks the urgency source, GET
 * /api/work-queue, for `covering=1` (nextcloud-vue `sourceParams`).
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/manifest.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../l10n/nl.json'), 'utf8')).translations
const cases = manifest.pages.find((page) => page.id === 'Cases')
const myWork = cases.config.defaultSavedViews.find((view) => view.slug === 'my-work')

describe('Assigned to me: work you cover', () => {
	it('opens on the lens that includes covered work', () => {
		const lens = myWork.overrides.quickFilters.find((tab) => tab.default === true)
		expect(lens.sourceParams).toEqual({ covering: '1' })
		expect(lens.activeLabel).toBe('Including work you cover')
		expect(nl[lens.activeLabel]).toBe('Inclusief werk waarvoor u waarneemt')
		expect(nl[lens.label]).toBeTruthy()
	})

	it('asks the work queue, which computes the covered cases, through the view\'s urgency order', () => {
		expect(myWork.overrides.sortOption).toBe('urgency')
		const urgency = cases.config.sortSelectOptions.find((option) => option.value === 'urgency')
		expect(urgency.source.url).toBe('/apps/dossiq/api/work-queue')
	})

	it('keeps the view to open cases in the lens filter, as its locked filters do', () => {
		const lens = myWork.overrides.quickFilters.find((tab) => tab.default === true)
		expect(lens.filter).toEqual({ statusHiddenInLists: false, isDraft: false })
	})
})
