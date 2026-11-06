/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqTermijnen closes on two cards side by side: the age of the open
 * workload per status and the first response, each over its own report route.
 *
 * @spec openspec/specs/termijn-reporting/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
const page = manifest.pages.find((entry) => entry.route === '/termijn-dashboard')
const widget = (id) => page.config.widgets.find((entry) => entry.id === id)
const placed = (id) => page.config.layout.find((entry) => entry.widgetId === id)

describe('the closing cards of DqTermijnen', () => {
	it('reads the workload age per status from its report route', () => {
		const card = widget('deadline-report-open-age')
		expect(card.content.endpointSource).toEqual({ url: '/apps/dossiq/api/deadlines/reports/open-workload-age', responsePath: 'perStatus' })
		expect(card.content.columns.map((column) => column.key)).toEqual(['status', 'cases', 'averageDays', 'oldestDays'])
	})

	it('reads the first response figures from their report route', () => {
		const card = widget('deadline-report-first-response')
		expect(card.content.endpointSource.url).toBe('/apps/dossiq/api/deadlines/reports/first-response')
		expect(card.content.cells.map((cell) => cell.valueField)).toEqual(['met', 'missed', 'averageOverrunDays'])
	})

	it('keeps both to the reporting audience and sets them side by side under the audit', () => {
		for (const id of ['deadline-report-open-age', 'deadline-report-first-response']) {
			expect(widget(id).roles).toEqual(['controllers', 'beheerders', 'admin'])
			expect(placed(id).gridY).toBeGreaterThanOrEqual(placed('deadline-report-annual-card').gridY + placed('deadline-report-annual-card').gridHeight)
			expect(placed(id).gridWidth).toBe(6)
		}
	})

	it('links the case in the notices table and wraps a long case type in the quarterly report', () => {
		const notices = widget('deadline-report-notices').content.columns.find((column) => column.key === 'caseLabel')
		expect(notices.widgetProps).toEqual({ route: 'CaseDetail', params: { id: 'caseId' } })
		const quarterly = widget('deadline-report-quarterly').content.columns.find((column) => column.key === 'caseTypeTitle')
		expect(quarterly.cellClass).toBe('cn-cell--wrap')
	})
})
