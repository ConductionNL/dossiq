/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Supervision plan page is manifest config over nextcloud-vue widgets:
 * a year field, the four figures, completion per category, the quarterly
 * overview and the frequency by risk, every one read from the year's
 * progress endpoint, so the page stores nothing of its own.
 *
 * @spec openspec/changes/supervision-plan-page/tasks.md#task-2
 * @spec openspec/changes/supervision-plan-page/tasks.md#task-3
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import en from '../../l10n/en.json'
import nl from '../../l10n/nl.json'
import fragment from '../../src/manifest.d/99-supervision-plan.json'
import manifest from '../../src/manifest.json'

const PROGRESS = '/apps/dossiq/api/supervision-plans/year/@workspace.year/progress'
const page = fragment.pages.find((p) => p.id === 'SupervisionPlan')
const widget = (id) => page.config.widgets.find((w) => w.id === id)
const ROUTES = fs.readFileSync(path.resolve(__dirname, '../../appinfo/routes.php'), 'utf8')

describe('the Supervision plan page', () => {
	it('is a dashboard at /inspection-plan, reached from its card on Reports and not from a menu entry', () => {
		expect(page).toMatchObject({ route: '/inspection-plan', type: 'dashboard', title: 'Supervision plan' })
		expect(fragment.menu).toBeUndefined()
		const cards = manifest.pages.find((p) => p.id === 'Reports').config.cards
		expect(cards).toContainEqual(expect.objectContaining({ id: 'SupervisionPlan', route: 'SupervisionPlan', label: 'Supervision plan' }))
		expect(page.config.allowEdit).toBe(false)
	})

	it('opens on a year the reader can change, written to @workspace.year', () => {
		expect(widget('supervision-plan-year')).toMatchObject({
			type: 'workspace-filter',
			content: { style: 'input', inputType: 'year', writes: '@workspace.year' },
		})
	})

	it('reads every figure from the year progress endpoint the routes declare', () => {
		expect(ROUTES).toContain("'url' => '/api/supervision-plans/year/{year}/progress'")
		const reading = page.config.widgets.filter((w) => w.type !== 'workspace-filter' && w.id !== 'supervision-plan-proposal')
		expect(reading.length).toBeGreaterThan(0)
		for (const w of reading) {
			expect(w.content.endpointSource.url, w.id).toBe(PROGRESS)
		}
	})

	it('shows the four figures and the capacity from the response totals', () => {
		const fields = ['scheduled', 'completed', 'cancelled', 'capacity'].map((key) => widget(`supervision-plan-${key}`).content.valueField)
		expect(fields).toEqual(['scheduled', 'completed', 'cancelled', 'capacityHours'])
		expect(widget('supervision-plan-completed').content.caption).toContain('{completion}')
		expect(widget('supervision-plan-capacity').content.caption).toContain('{demandHours}')
	})

	it('draws a bar per category, its "15 of 40" progress, the quarters and the risk rows', () => {
		const bars = widget('supervision-plan-per-category').content
		expect(bars).toMatchObject({ chartKind: 'bar-list', barMax: 100 })
		expect(bars.endpointSource).toMatchObject({ responsePath: 'categories', labelsPath: 'name', series: [{ path: 'completion' }] })

		const keys = (id) => widget(id).content.columns.map((c) => c.key)
		expect(widget('supervision-plan-categories').content.endpointSource.responsePath).toBe('categories')
		expect(keys('supervision-plan-categories')).toEqual(expect.arrayContaining(['name', 'label', 'cancelled', 'remaining']))
		expect(widget('supervision-plan-quarters').content.endpointSource.responsePath).toBe('quarters')
		expect(keys('supervision-plan-quarters')).toEqual(['quarter', 'scheduled', 'completed', 'cancelled', 'remaining', 'forecast'])
		expect(widget('supervision-plan-risk').content.endpointSource.responsePath).toBe('risk')
		expect(keys('supervision-plan-risk')).toEqual(['category', 'profile', 'objects', 'visitsPerYear', 'demand'])
	})

	it('shows the suggested schedule per category from the year proposal endpoint, which writes nothing', () => {
		expect(ROUTES).toContain("'url' => '/api/supervision-plans/year/{year}/schedule-proposal'")
		const proposal = widget('supervision-plan-proposal').content
		expect(proposal.endpointSource).toEqual({
			url: '/apps/dossiq/api/supervision-plans/year/@workspace.year/schedule-proposal',
			responsePath: 'categories',
		})
		expect(proposal.columns.map((c) => c.key)).toEqual(['name', 'demand', 'proposed', 'firstDate', 'lastDate'])
		expect(proposal.columns.filter((c) => c.formatter === 'date').map((c) => c.key)).toEqual(['firstDate', 'lastDate'])
		expect(page.config.headerActions ?? []).toEqual([])
	})

	it('places every widget exactly once, and nothing that is not declared', () => {
		const placed = page.config.layout.map((cell) => cell.widgetId)
		expect([...placed].sort()).toEqual(page.config.widgets.map((w) => w.id).sort())
		for (const cell of page.config.layout) {
			expect(cell.gridX + cell.gridWidth, cell.widgetId).toBeLessThanOrEqual(12)
		}
	})

	it('has an English source and a Dutch translation for every label it shows', () => {
		const card = manifest.pages.find((p) => p.id === 'Reports').config.cards.find((c) => c.id === 'SupervisionPlan')
		const texts = new Set([page.title, page.config.description, card.label, card.description, card.meta])
		for (const w of page.config.widgets) {
			texts.add(w.title)
			for (const key of ['label', 'caption', 'submitLabel', 'emptyText', 'emptyLabel']) {
				if (w.content[key]) texts.add(w.content[key])
			}
			for (const c of w.content.columns || []) texts.add(c.label)
			for (const s of w.content.endpointSource?.series || []) texts.add(s.name)
		}
		for (const text of texts) {
			expect(en.translations[text], text).toBe(text)
			expect(nl.translations[text], text).toBeTruthy()
			expect(nl.translations[text], text).not.toBe(text)
		}
	})
})
