/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The default filters dossiq ships on its case lists. Each one combines
 * conditions a quick-filter chip already carries, so each condition is held
 * equal to the chip it came from: a default that drifted from its chip would
 * count one set of cases while the chip beside it counts another.
 *
 * @spec openspec/changes/saved-filters-default-filters/specs/case-lists/spec.md
 */

import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n/nl.json'), 'utf8')).translations

const page = (id) => manifest.pages.find((p) => p.id === id)
const chip = (label) => page('Cases').config.quickFilters.find((q) => q.label === label).filter
const byslug = (id) => Object.fromEntries(page(id).config.defaultSavedFilters.map((d) => [d.slug, d.filters]))

describe('default filters on Cases', () => {
	const defaults = byslug('Cases')

	it('ships four, each with a Dutch name', () => {
		expect(Object.keys(defaults)).toEqual(['mine-overdue', 'mine-due-this-week', 'unclaimed-overdue', 'woo-requests'])
		for (const d of page('Cases').config.defaultSavedFilters) {
			expect(nl[d.name]).toBeTruthy()
		}
	})

	it('combines the Mine, Unclaimed, Overdue and Due this week chips literally', () => {
		expect(defaults['mine-overdue']).toEqual({ ...chip('Mine'), ...chip('Overdue') })
		expect(defaults['mine-due-this-week']).toEqual({ ...chip('Mine'), ...chip('Due this week') })
		expect(defaults['unclaimed-overdue']).toEqual({ ...chip('Unclaimed'), ...chip('Overdue') })
	})
})

describe('Woo requests as a filter on Cases', () => {
	it('is a default filter equal to the Woo chip of the case list', () => {
		const simple = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/menu-layout.simple.json'), 'utf8'))
		const wooChip = simple.pages.find((p) => p.id === 'Cases').configAppend.quickFilters.find((q) => q.label === 'Woo requests').filter
		expect(byslug('Cases')['woo-requests']).toEqual(wooChip)
	})
})

describe('default filters on Queue', () => {
	it('narrow the queue by the Overdue and Due this week windows only', () => {
		const defaults = byslug('Queue')
		const window = (f) => Object.fromEntries(Object.entries(f).filter(([k]) => k.startsWith('deadline[')))
		expect(defaults.late).toEqual(window(chip('Overdue')))
		expect(defaults['this-week']).toEqual(window(chip('Due this week')))
	})
})
