/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqProcesanalyse ends with "Klachten: terugkerende patronen": four
 * figures against their targets, the system report, complaints per category,
 * department, channel and month, and the handling time per category. All of
 * it reads one endpoint, and none of it is drawn before that endpoint answers.
 *
 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/complaint-management/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const read = (...parts) => JSON.parse(fs.readFileSync(path.resolve(__dirname, '../..', ...parts), 'utf8'))
const page = read('src', 'manifest.json').pages.find((entry) => entry.id === 'ProcessMiningDashboard')
const nl = read('l10n', 'nl.json').translations
const URL = '/apps/dossiq/api/complaints/patterns'
const IDS = ['pm-complaint-kpis', 'pm-complaint-finding', 'pm-complaints-by-category', 'pm-complaints-by-department', 'pm-complaints-by-channel', 'pm-complaints-by-month', 'pm-complaint-resolution']
const widget = (id) => page.config.widgets.find((entry) => entry.id === id)
const placed = (id) => page.config.layout.find((entry) => entry.widgetId === id)

describe('complaint patterns on the process analysis page (DqProcesanalyse)', () => {
	it('places the cards under the existing ones, in the board\'s order', () => {
		const assignee = placed('pm-dwell-by-assignee')
		const ys = IDS.map((id) => placed(id).gridY)
		expect(ys[0]).toBeGreaterThanOrEqual(assignee.gridY + assignee.gridHeight)
		expect([...ys].sort((a, b) => a - b)).toEqual(ys)
		expect(new Set(page.config.layout.map((entry) => entry.id)).size).toBe(page.config.layout.length)
	})

	it('reads one endpoint and is drawn only once it answers, for the roles of this page', () => {
		for (const id of IDS) {
			const card = widget(id)
			expect(JSON.stringify(card.content), id).toContain(URL)
			expect(card.roles, id).toEqual(['controllers', 'beheerders', 'admin'])
			const [roles, source] = card.visibleWhen.all
			expect(roles.field, id).toBe(`visible.${id}`)
			expect(source, id).toEqual({ endpoint: URL, field: 'available', op: 'eq', value: true })
		}
	})

	it('exports the system report as pdf and leads to the complaints', () => {
		const content = widget('pm-complaint-finding').content
		expect(content.footerAction).toMatchObject({ type: 'api-call', url: URL, params: { format: 'pdf' }, download: true })
		expect(content.headerLink.query).toEqual({ savedView: 'default:complaints' })
	})

	it('reads the board\'s Dutch titles', () => {
		expect(nl[widget('pm-complaint-kpis').title]).toBe('Klachten: terugkerende patronen')
		expect(nl[widget('pm-complaints-by-category').title]).toBe('Klachten per categorie')
		expect(nl[widget('pm-complaint-resolution').title]).toBe('Gemiddelde afhandeltijd per categorie')
	})
})
