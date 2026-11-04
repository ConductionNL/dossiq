/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqTermijnen draws "Verloopt het eerst" between the notices of default
 * and the quarterly report: the running terms that end first, with progress
 * and the days left, worked out when the page is opened.
 *
 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/termijn-reporting/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/manifest.json'), 'utf8'))
const page = manifest.pages.find((entry) => entry.id === 'TermijnDashboard')
const widget = page.config.widgets.find((entry) => entry.id === 'deadline-report-ending-first')
const placed = (id) => page.config.layout.find((entry) => entry.widgetId === id)
const nl = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../l10n/nl.json'), 'utf8')).translations

describe('Ending first on the term dashboard (DqTermijnen)', () => {
	it('stands between the notices of default and the quarterly report', () => {
		const notices = placed('deadline-report-notices')
		const card = placed('deadline-report-ending-first')
		const quarterly = placed('deadline-report-quarterly-card')
		expect(card.gridY).toBe(notices.gridY + notices.gridHeight)
		expect(quarterly.gridY).toBe(card.gridY + card.gridHeight)
		expect(placed('deadline-report-annual-card').gridY).toBe(quarterly.gridY + quarterly.gridHeight)
		expect(new Set(page.config.layout.map((entry) => entry.id)).size).toBe(page.config.layout.length)
	})

	it('lists the case, the term, the progress and the days left, opening the case', () => {
		expect(widget.content.endpointSource).toEqual({ url: '/apps/dossiq/api/deadlines/ending-first', responsePath: 'terms' })
		expect(widget.content.columns.map((column) => column.label)).toEqual(['Case', 'Term', 'Progress', 'Left'])
		expect(widget.content.columns[2].widget).toBe('progress')
		expect(widget.content.columns[3].widgetProps.due.upcomingLabel.other).toBe('{count} days left')
		expect(widget.content.rowRoute).toBe('CaseDetail')
		expect(widget.content.rowIdField).toBe('caseId')
	})

	it('is drawn only for the roles of this page, and only once its source answers', () => {
		expect(widget.roles).toEqual(['controllers', 'beheerders', 'admin'])
		const [roles, source] = widget.visibleWhen.all
		expect(roles.field).toBe('visible.deadline-report-ending-first')
		expect(source).toEqual({ endpoint: '/apps/dossiq/api/deadlines/ending-first', field: 'available', op: 'eq', value: true })
	})

	it('reads the board\'s Dutch', () => {
		expect(nl[widget.title]).toBe('Verloopt het eerst')
		expect(nl[widget.subtitle]).toBe('Voortgang en resterende dagen worden bij het openen berekend, niet opgeslagen')
	})
})
