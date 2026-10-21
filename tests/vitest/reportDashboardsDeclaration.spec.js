/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The processing-time, process-mining and deadline dashboards are manifest
 * configuration over library widgets, fed by server-shaped report endpoints.
 *
 * The browser used to load every case, case type and status and shape the
 * figures itself; the endpoints shape them now, and each widget reads one
 * `view`. These assertions hold the declarations to what the endpoints send
 * (lib/Service/Reporting, lib/Service/ProcessMining/ReportViews.php) and to
 * what the pages promised: text beside colour, keyboard-reachable rows, the
 * clock named in the duration headings, the deadline report's formats.
 *
 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
 * @spec openspec/specs/process-mining-bottlenecks/spec.md
 * @spec openspec/specs/termijn-reporting/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const registry = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')

const page = (id) => manifest.pages.find((p) => p.id === id)
const widget = (pageId, id) => page(pageId).config.widgets.find((w) => w.id === id)
const onGrid = (pageId, id) => page(pageId).config.layout.some((l) => l.widgetId === id)

describe('processing time', () => {
	const PT = '/apps/dossiq/api/reports/processing-time'

	it('draws the four compliance charts from the server report, one view each', () => {
		const views = {
			'processing-time-charts': ['donut', 'sla'],
			'processing-time-charts-distribution': ['bar', 'distribution'],
			'processing-time-charts-trend': ['line', 'trend'],
			'processing-time-charts-throughput': ['line', 'throughput'],
		}
		for (const [id, [kind, view]] of Object.entries(views)) {
			const w = widget('Doorlooptijd', id)
			expect(w.type).toBe('chart')
			expect(w.content.chartKind).toBe(kind)
			expect(w.content.endpointSource.url).toBe(PT)
			expect(w.content.endpointSource.params.view).toBe(view)
			expect(w.content.endpointSource.responsePath).toBe(view)
			expect(w.content.endpointSource.params.period).toBeUndefined()
			expect(onGrid('Doorlooptijd', id)).toBe(true)
		}
	})

	it('lists the cases at risk with the severity as text beside its colour, rows opening the case', () => {
		const w = widget('Doorlooptijd', 'processing-time-at-risk')
		expect(w.type).toBe('object-table')
		expect(w.content.endpointSource).toMatchObject({ url: PT, responsePath: 'atRisk', params: { view: 'atRisk' } })
		expect(w.content.rowRoute).toBe('CaseDetail')
		const severity = w.content.columns.find((c) => c.key === 'severity')
		expect(severity.enumLabels).toEqual({ overdue: 'Overdue', critical: 'Critical', warning: 'At risk', ok: 'On track' })
		expect(Object.keys(severity.colorMap).sort()).toEqual([...severity.enum].sort())
		expect(w.content.columns.find((c) => c.key === 'percentUsedDisplay').widget).toBe('progress')
	})

	it('selects one case type for the deadline list by configuration, never by a word in its title', () => {
		const w = widget('Doorlooptijd', 'processing-time-case-type-deadlines')
		expect(w.type).toBe('object-table')
		expect(w.content.endpointSource.params).toMatchObject({ view: 'atRisk', threshold: 1 })
		expect(typeof w.content.endpointSource.params.caseType).toBe('string')
		expect(JSON.stringify(w)).not.toContain('caseTypeContains')
		expect(w.content.rowRoute).toBe('CaseDetail')
	})

	it('sorts the breakdown and names each performance band', () => {
		const w = widget('Doorlooptijd', 'processing-time-breakdown')
		expect(w.content.endpointSource).toMatchObject({ url: PT, responsePath: 'breakdown' })
		expect(w.content.columns.filter((c) => c.sortable)).toHaveLength(5)
		const status = w.content.columns.find((c) => c.key === 'status')
		expect(Object.keys(status.enumLabels).sort()).toEqual(['critical', 'good', 'no-target', 'warning'])
	})
})

describe('process mining', () => {
	const PM = '/apps/dossiq/api/reports/process-mining'

	it('reads the four headline figures from the summary view', () => {
		const fields = {
			'pm-kpis': 'summary.totalCases',
			'pm-kpis-case-types': 'summary.caseTypeCount',
			'pm-kpis-rework': 'summary.overallReworkPercent',
			'pm-kpis-bottleneck': 'summary.topBottleneck.medianHours',
		}
		for (const [id, field] of Object.entries(fields)) {
			const w = widget('ProcessMiningDashboard', id)
			expect(w.type).toBe('stat')
			expect(w.content.endpointSource).toMatchObject({ url: PM, params: { view: 'summary' } })
			expect(w.content.valueField).toBe(field)
			expect(onGrid('ProcessMiningDashboard', id)).toBe(true)
		}
		expect(widget('ProcessMiningDashboard', 'pm-kpis-rework').content.caption).toBe('{summary.reworkNotice}')
	})

	it('names the clock in the dwell series and in both duration headings', () => {
		const dwell = widget('ProcessMiningDashboard', 'pm-dwell')
		expect(dwell.content.endpointSource.series[0].namePath).toBe('labels.workingHours')
		for (const id of ['pm-bottlenecks', 'pm-dwell-by-assignee']) {
			const paths = widget('ProcessMiningDashboard', id).content.columns.map((c) => c.labelPath).filter(Boolean)
			expect(paths).toEqual(['labels.workingHours', 'labels.wallHours'])
		}
	})

	it('keeps the by-handler table on the page and on the grid, with its hint', () => {
		const w = widget('ProcessMiningDashboard', 'pm-dwell-by-assignee')
		expect(w.type).toBe('object-table')
		expect(w.content.endpointSource.responsePath).toBe('byAssignee')
		expect(w.subtitle).toContain('not recorded')
		expect(onGrid('ProcessMiningDashboard', 'pm-dwell-by-assignee')).toBe(true)
	})
})

describe('deadline reports', () => {
	it('draws the quarterly report from its rows, named by case type, with CSV export', () => {
		const w = widget('TermijnDashboard', 'deadline-report-quarterly')
		expect(w.type).toBe('object-table')
		expect(w.content.endpointSource).toMatchObject({ responsePath: 'rows', params: { period: '@workspace.quarter' } })
		expect(w.content.exportCsv).toBe(true)
		const keys = Object.fromEntries(w.content.columns.map((c) => [c.key, c]))
		expect(keys.caseTypeTitle.label).toBe('Case type')
		expect(keys.withinTermPct.format).toMatchObject({ decimals: 1, suffix: ' %' })
		expect(keys.penaltyTotalEuros.format).toMatchObject({ style: 'currency', currency: 'EUR' })
		expect(keys.averageProcessingDays).toBeTruthy()
	})

	it('shows the annual audit as tiles over the summary keys', () => {
		const fields = { 'deadline-report-annual': 'summary.totalEuros', 'deadline-report-annual-payments': 'summary.count', 'deadline-report-annual-warnings': 'summary.warningCount' }
		for (const [id, field] of Object.entries(fields)) {
			const w = widget('TermijnDashboard', id)
			expect(w.type).toBe('stat')
			expect(w.content.endpointSource.params.year).toBe('@workspace.year')
			expect(w.content.valueField).toBe(field)
			expect(onGrid('TermijnDashboard', id)).toBe(true)
		}
	})

	it('offers the quarter and the year as page filters', () => {
		const types = page('TermijnDashboard').config.pageFilters.map((f) => [f.key, f.type])
		expect(types).toEqual([['quarter', 'quarter'], ['year', 'year']])
	})
})

describe('no dashboard reads a dossiq component any more', () => {
	it('has no slot or registry entry for the retired widgets', () => {
		for (const name of ['DtChartsWidget', 'DtWooWidget', 'DtAtRiskWidget', 'DtBreakdownWidget', 'PmKpiWidget', 'PmDwellChartWidget', 'PmDwellByAssigneeWidget', 'PmThroughputChartWidget', 'PmBottleneckTableWidget', 'TdQuarterlyWidget', 'TdAnnualWidget']) {
			expect(registry).not.toContain(name)
			expect(JSON.stringify(manifest)).not.toContain(name)
		}
		for (const id of ['Doorlooptijd', 'ProcessMiningDashboard', 'TermijnDashboard']) {
			expect(page(id).config.widgets.filter((w) => w.type === 'custom')).toEqual([])
		}
	})
})
