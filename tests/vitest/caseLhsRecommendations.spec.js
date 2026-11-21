// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The enforcement recommendations the LHS engine stores are read on their
 * case, on the Decisions tab, rather than through a settings list with no
 * menu entry (enforcement-intervention-from-the-case 3.4).
 *
 * @spec openspec/changes/enforcement-intervention-from-the-case/tasks.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
const panels = require('./helpers/casePanels.js')

const ROOT = path.resolve(__dirname, '../..')
const readJson = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const manifest = readJson('src/manifest.json')

describe('enforcement recommendations on the case page', () => {
	const sections = panels.caseWidget('case-decisions-panel').content.sections
	const section = sections.find((entry) => entry.widget.id === 'case-lhs-recommendations')

	it('is a section of the Decisions tab, after the decision-table advice', () => {
		expect(section).toBeDefined()
		const ids = sections.map((entry) => entry.widget.id)
		expect(ids.indexOf('case-lhs-recommendations')).toBeGreaterThan(ids.indexOf('case-decision-advice'))
	})

	it('lists only this case\'s lhsRecommendation objects and never creates one', () => {
		expect(section.widget.type).toBe('object-list')
		expect(section.widget.content).toMatchObject({
			register: 'dossiq',
			schema: 'lhsRecommendation',
			allowCreate: false,
			filter: { case: '@objectId' },
		})
	})

	it('opens a row on the recommendation detail page, which stays routable', () => {
		expect(section.widget.content.rowRoute).toBe('LhsRecommendationDetail')
		expect(manifest.pages.map((page) => page.id)).toContain('LhsRecommendationDetail')
	})

	it('shows the recommended and the final intervention', () => {
		const keys = section.widget.content.columns.map((column) => column.key)
		expect(keys).toEqual(expect.arrayContaining(['recommendedIntervention', 'finalIntervention', 'override']))
	})

	it('names no settings menu entry for a procedure', () => {
		for (const file of ['src/menu-layout.json', 'src/menu-layout.simple.json']) {
			expect(readJson(file).settingsSection).not.toContain('LhsRecommendationsMenu')
		}
	})

	it('has a Dutch value for every label it shows', () => {
		const nl = readJson('l10n/nl.json').translations
		for (const label of [section.label, section.widget.content.emptyText, ...section.widget.content.columns.map((c) => c.label)]) {
			expect(nl[label], label).toBeTruthy()
		}
		expect(nl['Enforcement recommendations']).toBe('Handhavingsaanbevelingen')
	})
})
