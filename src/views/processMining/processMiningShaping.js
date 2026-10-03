/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pure data-shaping helpers for the process-mining bottleneck dashboard
 * (the `/process-mining` dashboard widgets). Map the backend `/api/reports/process-mining`
 * payload (see lib/Service/ProcessMiningService.php) into the exact
 * CnChartWidget series/categories and table row arrays the view renders.
 * Dossiq owns this shaping arithmetic; the chart engine itself is
 *
 * @conduction/nextcloud-vue's CnChartWidget (ADR-Leaf-First — no bespoke
 * chart components here).
 *
 * Kept DOM-free and dependency-free so their output can be locked by Vitest
 * in the `node` environment.
 *
 * @spec openspec/specs/process-mining-bottlenecks/spec.md
 */

/**
 * CnChartWidget bar series for the dwell-time-by-status chart, for a single
 * case type's `dwellTime` stats.
 *
 * @param {Array<object>} dwellTime - `report.caseTypes[n].dwellTime` — [{ statusId, statusName, medianHours, ... }].
 * @param {string} seriesName - Localised series label.
 * @return {Array} ApexCharts bar series, or [] when there is no data.
 */
export function buildDwellSeries(dwellTime, seriesName) {
	const rows = dwellTime || []
	if (rows.length === 0) return []
	return [
		{
			name: seriesName,
			// The headline number, which is working hours when the server
			// measured any and the wall clock when it did not. The series
			// NAME says which, so the bar and its label can never disagree.
			data: rows.map((r) => headlineHours(r)),
		},
	]
}

/**
 * X-axis categories (status names) for the dwell-time chart, aligned with
 * buildDwellSeries().
 *
 * @param {Array<object>} dwellTime - `report.caseTypes[n].dwellTime`.
 * @return {string[]} Status display names.
 */
export function buildDwellCategories(dwellTime) {
	return (dwellTime || []).map((r) => r.statusName)
}

/**
 * CnChartWidget line series for the weekly throughput trend.
 *
 * @param {Array<object>} throughputTrend - `report.throughputTrend` — [{ week, count }].
 * @param {string} seriesName - Localised series label.
 * @return {Array} ApexCharts line series, or [] when there is no data.
 */
export function buildThroughputSeries(throughputTrend, seriesName) {
	const rows = throughputTrend || []
	if (rows.length === 0) return []
	return [
		{
			name: seriesName,
			data: rows.map((r) => r.count),
		},
	]
}

/**
 * X-axis categories (ISO week labels) for the throughput chart, aligned
 * with buildThroughputSeries().
 *
 * @param {Array<object>} throughputTrend - `report.throughputTrend`.
 * @return {string[]} ISO week labels (e.g. `2026-W23`).
 */
export function buildThroughputCategories(throughputTrend) {
	return (throughputTrend || []).map((r) => r.week)
}

/**
 * Flatten every case type's bottleneck ranking into one sorted table,
 * annotated with the owning case type's title, capped to the top N rows.
 *
 * @param {Array<object>} caseTypes - `report.caseTypes` — [{ id, title, bottlenecks: [...] }].
 * @param {number} [limit] - Maximum rows to return.
 * @return {Array<object>} Rows sorted by score descending: { caseTypeTitle, statusName, medianHours, visitCount, score }.
 */
export function buildBottleneckRows(caseTypes, limit = 10) {
	const rows = []
	for (const caseType of caseTypes || []) {
		for (const bottleneck of caseType.bottlenecks || []) {
			rows.push({
				caseTypeTitle: caseType.title,
				statusName: bottleneck.statusName,
				medianHours: bottleneck.medianHours,
				medianWorkingHours: headlineHours(bottleneck),
				visitCount: bottleneck.visitCount,
				score: bottleneck.score,
			})
		}
	}

	rows.sort((a, b) => b.score - a.score)
	return rows.slice(0, limit)
}

/**
 * Headline KPI summary aggregated across every case type in the report:
 * total case volume, transition-weighted overall rework %, the single
 * highest-scoring bottleneck, and how many case types were analysed.
 *
 * @param {object} report - The full `/api/reports/process-mining` payload.
 * @return {{totalCases: number, overallReworkPercent: number, topBottleneck: (object|null), caseTypeCount: number}} KPI summary.
 */
export function buildKpiSummary(report) {
	const caseTypes = report?.caseTypes || []

	const totalCases = caseTypes.reduce((sum, ct) => sum + (ct.caseVolume || 0), 0)

	let reworkWeightedSum = 0
	let transitionTotal = 0
	for (const caseType of caseTypes) {
		const count = caseType.transitionCount || 0
		reworkWeightedSum += (caseType.reworkPercent || 0) * count
		transitionTotal += count
	}
	const overallReworkPercent =
		transitionTotal > 0
			? Math.round((reworkWeightedSum / transitionTotal) * 10) / 10
			: 0

	const bottleneckRows = buildBottleneckRows(caseTypes, 1)
	const topBottleneck = bottleneckRows.length > 0 ? bottleneckRows[0] : null

	return {
		totalCases,
		overallReworkPercent,
		topBottleneck,
		caseTypeCount: caseTypes.length,
	}
}

/**
 * The clocks a duration on this page can be on.
 *
 * Mirrors `WorkingClock`'s constants. One spelling on both sides, because the
 * server sends the string and the page decides what to call it: a mismatch
 * would fall through to the wall-clock label on a report that had really been
 * counted on the calendar, and nothing would look wrong.
 *
 * @type {object}
 */
export const CLOCKS = Object.freeze({
	CALENDAR: 'working-calendar',
	DAYS_TIMES_EIGHT: 'working-days-times-eight',
	WALL: 'wall-clock',
})

/**
 * What to call the headline duration column, given the clock it is on.
 *
 * THE HEADER IS THE DISCLOSURE. There is no other place on the page that can
 * say a number is an approximation, and a working-hours column counted as
 * working days times eight looks exactly like one counted on the calendar:
 * both are hours, both are plausible, and one of them calls a case that sat an
 * hour on Friday and an hour on Monday sixteen hours of work.
 *
 * @param {string} clock - One of the CLOCKS values.
 * @param {(key: string) => string} translate - The bound t(), taking one string.
 * @return {string} The column title.
 */
export function workingHoursLabel(clock, translate) {
	if (clock === CLOCKS.CALENDAR) {
		return translate('Working hours')
	}
	if (clock === CLOCKS.DAYS_TIMES_EIGHT) {
		return translate('Working hours (working days x 8)')
	}
	return translate('Hours, wall clock')
}

/**
 * What to call the second duration column. Always the wall clock.
 *
 * @param {(key: string) => string} translate - The bound t(), taking one string.
 * @return {string} The column title.
 */
export function wallHoursLabel(translate) {
	return translate('Wall-clock hours')
}

/**
 * The value the headline column reads for one aggregated row.
 *
 * Falls back to the wall-clock number when the row carries no working-hours
 * one, which is every row from a server that predates this change. Paired with
 * `workingHoursLabel`, which then says the column is the wall clock, so the
 * fallback is labelled rather than disguised.
 *
 * @param {object} row - One dwell or bottleneck row.
 * @return {number} The headline duration in hours.
 */
export function headlineHours(row) {
	if (typeof row?.medianWorkingHours === 'number') {
		return row.medianWorkingHours
	}
	return row?.medianHours ?? 0
}

/**
 * Rows for the dwell-by-assignee table.
 *
 * Time no status record attributed keeps its row and is named, rather than
 * being dropped: a table that omits it shows less work than was done.
 *
 * @param {Array<object>} dwellByAssignee - `report.caseTypes[n].dwellByAssignee`.
 * @param {(key: string) => string} translate - The bound t(), taking one string.
 * @return {Array<object>} Rows: { actor, actorLabel, workingHours, wallHours, visitCount }.
 */
export function buildAssigneeRows(dwellByAssignee, translate) {
	return (dwellByAssignee || []).map((row) => ({
		actor: row.actor ?? '',
		actorLabel:
			String(row.actor ?? '').trim() === ''
				? translate('Not recorded')
				: String(row.actor),
		workingHours: headlineHours(row),
		wallHours: row.medianHours ?? 0,
		visitCount: row.visitCount ?? 0,
	}))
}
