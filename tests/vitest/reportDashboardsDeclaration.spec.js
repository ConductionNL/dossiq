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
			'processing-time-charts': ['bar-list', 'sla'],
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
			expect(onGrid('Doorlooptijd', id)).toBe(false)
		}
	})

	it('groups the four compliance charts as small cards in one card, three to a row (DqDoorlooptijd Naleving)', () => {
		const group = widget('Doorlooptijd', 'processing-time-compliance')
		expect(group.type).toBe('sections')
		expect(group.title).toBe('Compliance')
		expect(group.roles).toEqual(['controllers', 'beheerders', 'admin'])
		expect(group.visibleWhen.field).toBe('visible.processing-time-compliance')
		expect(group.content).toMatchObject({ cards: true, columns: 3 })
		expect(group.content.footnote).toBeTruthy()
		expect(group.content.sections.map((s) => s.widgetId)).toEqual(['processing-time-charts', 'processing-time-charts-distribution', 'processing-time-charts-trend', 'processing-time-charts-throughput'])
		for (const section of group.content.sections) {
			expect(section.title).toBe(widget('Doorlooptijd', section.widgetId).title)
		}
		expect(onGrid('Doorlooptijd', 'processing-time-compliance')).toBe(true)
	})

	it('draws compliance per case type as a bar list of the share within the norm', () => {
		const w = widget('Doorlooptijd', 'processing-time-charts')
		expect(w.content.barMax).toBe(100)
		expect(w.content.valueFormat.suffix).toBe(' %')
		expect(w.content.endpointSource.series[0].path).toBe('rate')
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
	it('fits every card but the monthly plot to its content and packs them upward, as DqDoorlooptijd draws them', () => {
		// Fixed grid rows cut the Throughput card off inside Compliance and
		// stood an empty Woo deadlines table 300px tall. The monthly line is
		// a plot, which takes the height its cell gives it, so it keeps rows.
		const config = page('Doorlooptijd').config
		expect(config.gridFloat).toBe(false)
		for (const cell of config.layout) {
			if (cell.widgetId === 'processing-time-monthly') {
				expect(cell.sizeToContent).toBeUndefined()
			} else {
				expect(cell.sizeToContent, cell.widgetId).toBe(true)
			}
		}
	})

	it('wraps the case and case type names in the two half-width tables, as the board does', () => {
		// DqDoorlooptijd: "Bezwaar Havenstraat" wraps over two lines; without
		// wrapping the first column shrank to two letters.
		const title = widget('Doorlooptijd', 'processing-time-at-risk').content.columns.find((c) => c.key === 'title')
		const name = widget('Doorlooptijd', 'processing-time-breakdown').content.columns.find((c) => c.key === 'name')
		expect(title.cellClass).toContain('cn-cell--wrap')
		expect(name.cellClass).toContain('cn-cell--wrap')
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

	it('draws the dwell time per status as a bar list in whole hours, as DqProcesanalyse does', () => {
		// "Ontvangst ▬ 31 h": a labelled track per status, the hours at its end.
		const dwell = widget('ProcessMiningDashboard', 'pm-dwell')
		expect(dwell.content.chartKind).toBe('bar-list')
		expect(dwell.content.valueFormat).toEqual({ name: 'number', decimals: 0, suffix: ' h' })
		expect(dwell.content.endpointSource.labelsPath).toBe('statusName')
	})

	it('draws Days per phase with the board\'s 240px case-type column', () => {
		// DqProcesanalyse: the case types in a 240px column, the phases sharing the rest.
		const matrix = widget('ProcessMiningDashboard', 'pm-phase-days')
		expect(matrix.content.chartKind).toBe('matrix')
		expect(matrix.content.matrixLabelWidth).toBe(240)
	})

	it('keeps the by-handler table on the page and on the grid, with its hint', () => {
		const w = widget('ProcessMiningDashboard', 'pm-dwell-by-assignee')
		expect(w.type).toBe('object-table')
		expect(w.content.endpointSource.responsePath).toBe('byAssignee')
		expect(w.subtitle).toContain('not recorded')
		expect(onGrid('ProcessMiningDashboard', 'pm-dwell-by-assignee')).toBe(true)
	})
	it('names the period it measures on the description line, as DqProcesanalyse does', () => {
		// "… januari tot en met september 2026 · [Gemiddeld | Mediaan] …": the
		// description reads the period filter's active window (nextcloud-vue
		// description-period-from-filter), each rolling option its months.
		const config = page('ProcessMiningDashboard').config
		expect(config.description).toBe('Case status history {filter.period}')
		const period = config.pageFilters.find((f) => f.key === 'period')
		expect(Object.fromEntries(period.options.map((o) => [o.value, o.months]))).toEqual({ '3m': 3, '6m': 6, '12m': 12, all: undefined })
	})

	it('puts the measure, the period and the case type on the description line, in the board\'s order', () => {
		// DqProcesanalyse: "… · [Gemiddeld | Mediaan] [Laatste 12 maanden]
		// [Alle zaaktypen]", with no filter row under the header.
		const filters = page('ProcessMiningDashboard').config.pageFilters
		expect(filters.map((f) => f.key)).toEqual(['statistic', 'period', 'caseType'])
		for (const filter of filters) {
			expect(filter.placement, filter.key).toBe('description')
		}
		expect(filters.find((f) => f.key === 'caseType').placeholder).toBe('All case types')
	})
})

describe('deadline reports', () => {
	it('draws the quarterly report from its rows, named by case type, exported from its form', () => {
		const w = widget('TermijnDashboard', 'deadline-report-quarterly')
		expect(w.type).toBe('object-table')
		expect(w.content.endpointSource).toMatchObject({ responsePath: 'rows', params: { period: '@workspace.quarter' } })
		// Board DqTermijnen: "CSV exporteren" beside "Rapport laden" above the
		// table, so the form carries the export and the table has no footer button.
		expect(w.content.exportCsv).toBeUndefined()
		expect(w.content.exportId).toBe('deadline-report-quarterly')
		expect(widget('TermijnDashboard', 'deadline-report-quarter-form').content.exportTable).toEqual({ exportId: 'deadline-report-quarterly' })
		const keys = Object.fromEntries(w.content.columns.map((c) => [c.key, c]))
		expect(keys.caseTypeTitle.label).toBe('Case type')
		expect(keys.withinTermPct.format).toMatchObject({ decimals: 1, suffix: ' %' })
		expect(keys.penaltyTotalEuros.format).toMatchObject({ style: 'currency', currency: 'EUR' })
		expect(keys.averageProcessingDays).toBeTruthy()
	})

	it('draws each report card with its own form, as board DqTermijnen does', () => {
		expect(page('TermijnDashboard').config.pageFilters).toBeUndefined()
		const forms = { 'deadline-report-quarter-form': ['quarter', 'Load report'], 'deadline-report-year-form': ['year', 'Load audit'] }
		for (const [id, [type, submit]] of Object.entries(forms)) {
			const w = widget('TermijnDashboard', id)
			expect(w.type).toBe('workspace-filter')
			expect(w.content).toMatchObject({ style: 'input', inputType: type, writes: `@workspace.${type}`, submitLabel: submit })
		}
		const cards = {
			'deadline-report-quarterly-card': ['deadline-report-quarter-form', 'deadline-report-quarterly'],
			'deadline-report-annual-card': ['deadline-report-year-form', 'deadline-report-annual-summary', 'deadline-report-annual-payments-list', 'deadline-report-annual-warnings'],
		}
		for (const [id, children] of Object.entries(cards)) {
			const w = widget('TermijnDashboard', id)
			expect(w.type).toBe('sections')
			expect(w.content.sections.map((s) => s.widgetId)).toEqual(children)
			expect(onGrid('TermijnDashboard', id)).toBe(true)
			for (const child of children) {
				expect(onGrid('TermijnDashboard', child)).toBe(false)
			}
		}
	})

	it('downloads the quarterly report from its card header, as the board does', () => {
		const card = widget('TermijnDashboard', 'deadline-report-quarterly-card')
		expect(card.headerActions[0]).toMatchObject({ label: 'Download quarterly report', action: { type: 'export', exportId: 'deadline-report-quarterly' } })
		expect(widget('TermijnDashboard', 'deadline-report-quarterly').content.exportId).toBe('deadline-report-quarterly')
	})

	it('lists the forfeited payments by case, with the year\'s total above them', () => {
		const list = widget('TermijnDashboard', 'deadline-report-annual-payments-list')
		expect(list.content.endpointSource).toMatchObject({ url: '/apps/dossiq/api/deadlines/reports/penalty-payments', params: { year: '@workspace.year' }, responsePath: 'rows' })
		expect(list.content.columns.map((c) => c.key)).toEqual(['caseIdentifier', 'caseTypeTitle', 'requester', 'forfeitDate', 'amountEuros', 'paymentStatus'])
		expect(list.content).toMatchObject({ rowRoute: 'CaseDetail', rowIdField: 'caseId' })
		const summary = widget('TermijnDashboard', 'deadline-report-annual-summary')
		expect(summary.content.endpointSource.responsePath).toBe('summaryRows')
		expect(summary.content.title).toContain('{totalEuros|currency:EUR}')
		expect(widget('TermijnDashboard', 'deadline-report-annual-warnings').content.valueField).toBe('summary.warningCount')
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

describe('processing time table heads', () => {
	it('lets the half-width tables wrap their figure heads and aligns the figures to the end, as DqDoorlooptijd does', () => {
		// "Doel (dagen)", "Gem. werkelijk (dagen)" and "Termijn gebruikt" wrap
		// over two or three lines in the half-width cards; one-line heads made
		// the Prestaties table wider than its card.
		const breakdown = Object.fromEntries(widget('Doorlooptijd', 'processing-time-breakdown').content.columns.map((c) => [c.key, c]))
		for (const key of ['targetDays', 'avgActualDays', 'complianceRate', 'total']) {
			expect(breakdown[key].class.split(' '), key).toEqual(expect.arrayContaining(['cn-table-col--end', 'cn-cell--wrap']))
		}
		const atRisk = widget('Doorlooptijd', 'processing-time-at-risk').content.columns.find((c) => c.key === 'percentUsedDisplay')
		expect(atRisk.class).toContain('cn-cell--wrap')
	})
})
