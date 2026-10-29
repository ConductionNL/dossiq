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
			'processing-time-charts-throughput': ['bar', 'throughput'],
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

	it('names its period, draws the norm and writes the months, as DqDoorlooptijd does', () => {
		// "januari tot en met september 2026 ·": a fixed twelve-month period
		// without a control (nextcloud-vue page-filter-without-control); the
		// dashed "norm 28 dagen" from the answer and "jan", "feb" on the axis
		// (chart-reference-line-from-answer).
		const config = page('Doorlooptijd').config
		expect(config.description).toBe('{filter.period}')
		const period = config.pageFilters.find((f) => f.key === 'period')
		expect(period).toMatchObject({ control: 'none', default: '12m', placement: 'description' })
		expect(period.options).toEqual([{ value: '12m', label: 'Last 12 months', months: 12 }])
		const monthly = widget('Doorlooptijd', 'processing-time-monthly').content
		expect(monthly.categoryFormat).toBe('month')
		expect(monthly.referenceLines).toEqual([{ valuePath: 'summary.normDays', label: 'norm {value} days' }])
		expect(monthly.endpointSource.params.period).toBe('@workspace.period?')
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
		// "Termijn gebruikt" is a figure at the column's end ("93"), as DqDoorlooptijd draws it.
		const used = w.content.columns.find((c) => c.key === 'percentUsedDisplay')
		expect(used.widget).toBeUndefined()
		expect(used.format).toEqual({ style: 'number', decimals: 0 })
		expect(used.class).toContain('cn-table-col--end')
	})

	it('selects one case type for the deadline list by configuration, never by a word in its title', () => {
		const w = widget('Doorlooptijd', 'processing-time-case-type-deadlines')
		expect(w.type).toBe('object-table')
		expect(w.content.endpointSource.params).toMatchObject({ view: 'atRisk', threshold: 0.5 })
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
	it('fits every card to its content and packs them upward, as DqDoorlooptijd draws them', () => {
		// Fixed grid rows cut the Throughput card off inside Compliance and
		// stood an empty Woo deadlines table 300px tall. The monthly plot has
		// its own 250px height, so its card fits it too.
		const config = page('Doorlooptijd').config
		expect(config.gridFloat).toBe(false)
		for (const cell of config.layout) {
			expect(cell.sizeToContent, cell.widgetId).toBe(true)
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

	it('fits every card but the plot to its content and packs them upward, as DqProcesanalyse draws them', () => {
		// The board's figures are 120px tiles and each card ends with its
		// content (Waar zaken blijven hangen shows every row); fixed grid rows
		// cut the stuck list and stood the tiles 215px tall.
		// The throughput line is a plot, which takes the height its cell
		// gives it, so it keeps four rows (296px; the board 294).
		const config = page('ProcessMiningDashboard').config
		expect(config.gridFloat).toBe(false)
		for (const cell of config.layout) {
			if (cell.widgetId === 'pm-throughput') {
				expect(cell.sizeToContent).toBeUndefined()
				expect(cell.gridHeight).toBe(4)
			} else {
				expect(cell.sizeToContent, cell.widgetId).toBe(true)
			}
		}
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

	it('aligns the figures of the quarterly report and the penalty audit to the end, as board DqTermijnen does', () => {
		// DqTermijnen: every count, the average duration ("28,1") and the
		// amounts sit right-aligned under right-aligned headers.
		const quarterly = Object.fromEntries(widget('TermijnDashboard', 'deadline-report-quarterly').content.columns.map((c) => [c.key, c]))
		for (const key of ['total', 'withinTermPct', 'overruns', 'averageProcessingDays', 'extensions', 'noticesOfDefault', 'penaltyTotalEuros']) {
			expect(quarterly[key].class, key).toBe('cn-table-col--end')
		}
		expect(quarterly.caseTypeTitle.class).toBeUndefined()
		expect(quarterly.averageProcessingDays.format).toMatchObject({ style: 'decimal', decimals: 1 })
		const payments = Object.fromEntries(widget('TermijnDashboard', 'deadline-report-annual-payments-list').content.columns.map((c) => [c.key, c]))
		expect(payments.amountEuros.class).toBe('cn-table-col--end')
		// The case number is a link to the case, as DqTermijnen draws it ("2026-0018").
		expect(payments.caseIdentifier).toMatchObject({ widget: 'link', widgetProps: { route: 'CaseDetail', params: { id: 'caseId' } } })
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
			'deadline-report-quarterly-card': ['deadline-report-quarter-form', 'deadline-report-quarterly', 'deadline-report-quarterly-summary'],
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

	it('names the week and the quarter\'s decided terms, as board DqTermijnen does', () => {
		// "Week 41 · alle teams" (nextcloud-vue description-today-week) and
		// "Kwartaal 3 van 2026: 72 zaken beslist, 67 binnen ... (93 %)." under
		// the table, from the report's one summary row.
		expect(page('TermijnDashboard').config.description).toBe('{today|week} · all teams')
		const summary = widget('TermijnDashboard', 'deadline-report-quarterly-summary')
		expect(summary.type).toBe('record-list')
		expect(summary.content.endpointSource).toMatchObject({ url: '/apps/dossiq/api/deadlines/reports/quarterly', params: { period: '@workspace.quarter' }, responsePath: 'summaryRows' })
		for (const token of ['{quarter}', '{year}', '{decided}', '{withinTerm}', '{share}']) {
			expect(summary.content.meta).toContain(token)
		}
		expect(summary.roles).toEqual(widget('TermijnDashboard', 'deadline-report-quarterly').roles)
		// The quarterly report opens on the last complete quarter (decision 25 Oct, DqTermijnen 2026-Q3 in October).
		expect(widget('TermijnDashboard', 'deadline-report-quarter-form').content.default).toBe('previous')
		// A quarter without decided terms has no line: no "Nothing here yet"
		// under the table (nextcloud-vue record-list emptyText "").
		expect(summary.content.emptyText).toBe('')
	})

	it('downloads the quarterly report from its card header, as the board does', () => {
		const card = widget('TermijnDashboard', 'deadline-report-quarterly-card')
		// DqTermijnen: "Kwartaalrapport downloaden" is the card's text link.
		expect(card.headerActions[0]).toMatchObject({ label: 'Download quarterly report', variant: 'link', action: { type: 'export', exportId: 'deadline-report-quarterly' } })
		expect(widget('TermijnDashboard', 'deadline-report-quarterly').content.exportId).toBe('deadline-report-quarterly')
	})

	it('fits every card to its content and packs them upward, as DqTermijnen draws them', () => {
		const config = page('TermijnDashboard').config
		expect(config.gridFloat).toBe(false)
		for (const cell of config.layout) {
			expect(cell.sizeToContent, cell.widgetId).toBe(true)
		}
	})

	it('lists the forfeited payments by case, with the year\'s total above them', () => {
		const list = widget('TermijnDashboard', 'deadline-report-annual-payments-list')
		expect(list.content.endpointSource).toMatchObject({ url: '/apps/dossiq/api/deadlines/reports/penalty-payments', params: { year: '@workspace.year' }, responsePath: 'rows' })
		expect(list.content.columns.map((c) => c.key)).toEqual(['caseIdentifier', 'caseTypeTitle', 'requester', 'forfeitDate', 'amountEuros', 'paymentStatus'])
		expect(list.content).toMatchObject({ rowRoute: 'CaseDetail', rowIdField: 'caseId' })
		const summary = widget('TermijnDashboard', 'deadline-report-annual-summary')
		expect(summary.content.endpointSource.responsePath).toBe('summaryRows')
		expect(summary.content.title).toContain('{totalEuros|currency:EUR}')
		// DqTermijnen: the total and "1 betaling, geen betwist" on one line, the note worded by the server.
		expect(summary.content.meta).toBe('{note}')
		expect(summary.content.metaBeside).toBe(true)
		expect(summary.content.density).toBe('stacked')
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
		// The case type and the remaining time wrap too, so the title column
		// keeps its width in the half-width card.
		// The board draws three cases at risk and four case types; the rest stays
		// reachable: the cases behind View all, the case types on the pager.
		const risk = widget('Doorlooptijd', 'processing-time-at-risk').content
		expect(risk.limit).toBe(3)
		expect(risk.viewAllRoute.name).toBe('Cases')
		expect(widget('Doorlooptijd', 'processing-time-breakdown').content.pageSize).toBe(4)
		// "Kritiek" in the breakdown is the board's amber, as in the risk table.
		const status = widget('Doorlooptijd', 'processing-time-breakdown').content.columns.find((c) => c.key === 'status')
		expect(status.colorMap.critical).toBe('warning')
		const riskCols = Object.fromEntries(widget('Doorlooptijd', 'processing-time-at-risk').content.columns.map((c) => [c.key, c]))
		for (const key of ['title', 'caseTypeName', 'remainingLabel']) {
			expect(riskCols[key].cellClass, key).toBe('cn-cell--wrap')
		}
	})
})

describe('process mining figures', () => {
	it('draws the dwell time per status with the value at each bar\'s end, as DqProcesanalyse does', () => {
		const dwell = widget('ProcessMiningDashboard', 'pm-dwell').content
		expect(dwell.chartKind).toBe('bar-list')
		expect(dwell.barValueAtEnd).toBe(true)
	})

	it('ranks the four worst bottlenecks, as DqProcesanalyse draws Knelpuntenranglijst', () => {
		expect(widget('ProcessMiningDashboard', 'pm-bottlenecks').content.limit).toBe(4)
	})

	it('aligns the figures to the end and rules above the first stuck phase, as DqProcesanalyse draws them', () => {
		const cols = (id) => Object.fromEntries(widget('ProcessMiningDashboard', id).content.columns.map((c) => [c.key, c]))
		const ranking = cols('pm-bottlenecks')
		for (const key of ['medianWorkingHours', 'medianHours', 'visitCount', 'score']) {
			expect(ranking[key].class, key).toBe('cn-table-col--end')
		}
		expect(ranking.caseTypeTitle.class).toBeUndefined()
		const byHandler = cols('pm-dwell-by-assignee')
		for (const key of ['workingHours', 'wallHours', 'visitCount']) {
			expect(byHandler[key].class, key).toBe('cn-table-col--end')
		}
		// "Waar zaken blijven hangen": a hairline under the title, above the first row.
		expect(widget('ProcessMiningDashboard', 'pm-stuck').content.ruleAbove).toBe(true)
	})

	it('writes the ranking and handler figures as whole numbers, as DqProcesanalyse does', () => {
		// "9.936", "432": the endpoints answer hours like 993.5, which read raw.
		const whole = { style: 'number', decimals: 0 }
		const cols = (id) => Object.fromEntries(widget('ProcessMiningDashboard', id).content.columns.map((c) => [c.key, c]))
		for (const key of ['medianWorkingHours', 'medianHours', 'visitCount', 'score']) {
			expect(cols('pm-bottlenecks')[key].format, key).toEqual(whole)
		}
		for (const key of ['workingHours', 'wallHours', 'visitCount']) {
			expect(cols('pm-dwell-by-assignee')[key].format, key).toEqual(whole)
		}
	})
})

describe('the small compliance charts', () => {
	it('draw flat labels and no y labels in their narrow cards, the SLA trend by month with the red target line, as DqDoorlooptijd does', () => {
		const distribution = widget('Doorlooptijd', 'processing-time-charts-distribution').content
		expect(distribution.options.xaxis.labels.rotate).toBe(0)
		expect(distribution.options.yaxis.show).toBe(false)
		const trend = widget('Doorlooptijd', 'processing-time-charts-trend').content
		expect(trend.categoryFormat).toBe('month')
		expect(trend.options.xaxis.labels.rotate).toBe(0)
		expect(trend.options.yaxis.labels.show).toBe(false)
		expect(trend.options.markers.size).toBe(4)
		expect(trend.referenceLines[0].color).toContain('--color-error')
	})
})

describe('throughput per week', () => {
	it('keeps DqProcesanalyse\'s weekly line readable over a year: ten flat week labels, a straight line, no y labels', () => {
		const options = widget('ProcessMiningDashboard', 'pm-throughput').content.options
		expect(options.xaxis.tickAmount).toBe(10)
		expect(options.xaxis.labels.rotate).toBe(0)
		expect(options.stroke.curve).toBe('straight')
		expect(options.yaxis.labels.show).toBe(false)
	})

	it('names the weeks "w40", as DqProcesanalyse and DqDoorlooptijd draw them', () => {
		// The endpoints key a week as ISO 2026-W40; nextcloud-vue
		// chart-week-categories writes it as "w40".
		const mining = widget('ProcessMiningDashboard', 'pm-throughput').content
		expect(mining.categoryFormat).toBe('week')
		expect(mining.endpointSource.labelsPath).toBe('week')
		const processing = widget('Doorlooptijd', 'processing-time-charts-throughput').content
		expect(processing.categoryFormat).toBe('week')
		expect(processing.endpointSource.labelsPath).toBe('week')
	})

	it('draws each page as its board does: a line on DqProcesanalyse, teal bars with their values on DqDoorlooptijd', () => {
		// Coordinator 24 Oct: each page follows its own board.
		expect(widget('ProcessMiningDashboard', 'pm-throughput').content.chartKind).toBe('line')
		const processing = widget('Doorlooptijd', 'processing-time-charts-throughput').content
		expect(processing.chartKind).toBe('bar')
		expect(processing.colors[0]).toContain('--nl-color-teal-text')
		expect(processing.options.dataLabels.enabled).toBe(true)
		expect(processing.options.plotOptions.bar.dataLabels.position).toBe('top')
		expect(processing.options.yaxis.show).toBe(false)
	})
})
