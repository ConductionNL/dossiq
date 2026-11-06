/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The default views dossiq ships on its case lists. Each one combines
 * conditions a quick-filter chip already carries, so each condition is held
 * equal to the chip it came from: a default that drifted from its chip would
 * count one set of cases while the chip beside it counts another.
 *
 * @spec openspec/specs/case-lists/spec.md
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
// A view's base conditions: its own filters, plus the ones it locks.
const byslug = (id) => Object.fromEntries(page(id).config.defaultSavedViews.map((d) => [d.slug, { ...d.filters, ...d.overrides?.lockedFilters }]))

const defaults = byslug('Cases')

describe('default views on Cases', () => {

	// DqFilterBeheren leads with the board's three (default-views-board-first);
	// the ten before them follow, re-integration pathways among them.
	it('ships thirteen, the board\'s three first, each with a Dutch name', () => {
		expect(Object.keys(defaults)).toEqual([
			'my-cases',
			'all-open-cases',
			'deadline-passed',
			'mine-overdue',
			'mine-due-this-week',
			'unclaimed-overdue',
			'woo-requests',
			'subsidies',
			're-integration-pathways',
			'my-work',
			'team-queue',
			'unclaimed-due-this-week',
			'data-subject-requests',
		])
		for (const d of page('Cases').config.defaultSavedViews) {
			expect(nl[d.name]).toBeTruthy()
		}
	})

	it('combines the Mine, Unclaimed, Overdue and Due this week chips literally', () => {
		expect(defaults['mine-overdue']).toEqual({ ...chip('Mine'), ...chip('Overdue') })
		expect(defaults['mine-due-this-week']).toEqual({ ...chip('Mine'), ...chip('Due this week') })
		expect(defaults['unclaimed-overdue']).toEqual({ ...chip('Unclaimed'), ...chip('Overdue') })
	})
})

describe('Woo requests as a view of Cases', () => {
	it('is a default view equal to the Woo chip of the case list', () => {
		const simple = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/menu-layout.simple.json'), 'utf8'))
		const wooChip = simple.pages.find((p) => p.id === 'Cases').configAppend.quickFilters.find((q) => q.label === 'Woo requests').filter
		expect(byslug('Cases')['woo-requests']).toEqual(wooChip)
	})
})

describe('lists that were pages, as views of Cases', () => {
	it('keeps the team queue equal to the Unclaimed chip on the reader\'s team, with its late and this-week windows', () => {
		expect(defaults['team-queue']).toEqual({ ...chip('Unclaimed'), assignedGroup: '@myTeam' })
		expect(defaults['unclaimed-overdue']).toEqual({ ...chip('Unclaimed'), ...chip('Overdue') })
		expect(defaults['unclaimed-due-this-week']).toEqual({ ...chip('Unclaimed'), ...chip('Due this week') })
	})

	it('opens Assigned to me on the urgency order, which the Cases sort offers from the work queue', () => {
		const view = page('Cases').config.defaultSavedViews.find((d) => d.slug === 'my-work')
		expect(view.overrides.lockedFilters.assignee).toBe('@me')
		expect(view.overrides.sortOption).toBe('urgency')
		const option = page('Cases').config.sortSelectOptions.find((o) => o.value === view.overrides.sortOption)
		expect(option.source.url).toBe('/apps/dossiq/api/work-queue')
	})

	it('keeps the data subject requests on the request kind', () => {
		expect(defaults['data-subject-requests']).toEqual({ dataSubjectRequestType: ['inzage', 'correctie', 'verwijdering'] })
	})
})

describe('Re-integration pathways as a view of Cases', () => {
	it('lists the cases of the re-integration case type, newest start first', () => {
		const view = page('Cases').config.defaultSavedViews.find((d) => d.slug === 're-integration-pathways')
		expect(view.filters).toEqual({ caseTypeIdentifier: 're-integratietraject', statusHiddenInLists: false, isDraft: false })
		expect(view.overrides.defaultSort).toEqual([{ key: 'startDate', order: 'desc' }])
	})
})
