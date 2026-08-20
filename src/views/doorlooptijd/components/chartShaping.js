/**
 * SPDX-FileCopyrightText: 2026 Conduction / Procest Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pure data-shaping helpers for the doorlooptijd dashboard sub-components
 * (ComplianceCharts.vue, CaseTypeBreakdown.vue). These functions map the
 * already-aggregated helper output (computeSlaCompliance / computeMonthlyTrend
 * / computeProcessingTimeDistribution / computeWeeklyThroughput /
 * computePerformanceTable) into the exact ApexCharts series arrays and the
 * sorted performance-table rows the components render.
 *
 * They are kept DOM-free and dependency-free so their output can be locked by
 * Vitest in the `node` environment — the chart/table arithmetic is otherwise
 * only ever observed through a rendered dashboard.
 */

/**
 * Within-SLA count per case type that has at least one completed case.
 * Drives the donut chart series (one slice per qualifying case type).
 *
 * @param {object} slaData - computeSlaCompliance() output ({ byType: [...] }).
 * @return {number[]} Within-SLA counts, in case-type order.
 */
export function buildDonutSeries(slaData) {
	const types = (slaData?.byType || []).filter((t) => t.total > 0)
	return types.map((t) => t.withinSla)
}

/**
 * Case types with at least one completed case — the donut chart labels.
 *
 * @param {object} slaData - computeSlaCompliance() output.
 * @return {string[]} Case-type display names, aligned with buildDonutSeries().
 */
export function buildDonutLabels(slaData) {
	const types = (slaData?.byType || []).filter((t) => t.total > 0)
	return types.map((t) => t.name)
}

/**
 * Histogram bar series — case count per processing-time bin. Returns an empty
 * array when every bin is empty so the component can show its empty state.
 *
 * @param {object} distributionData - computeProcessingTimeDistribution() output.
 * @param {string} seriesName - Localised series label.
 * @return {Array} ApexCharts bar series, or [] when there is no data.
 */
export function buildHistogramSeries(distributionData, seriesName) {
	const bins = distributionData?.bins || []
	if (bins.length === 0 || bins.every((b) => b.count === 0)) return []
	return [
		{
			name: seriesName,
			data: bins.map((b) => b.count),
		},
	]
}

/**
 * Index of the histogram bin into which the SLA-target day count falls. Bin
 * labels are `"min-max"` or `"N+"`. Returns the last bin when the target lies
 * beyond all labelled ranges, or -1 when there are no bins.
 *
 * @param {Array} bins - Distribution bins ([{ label, count }]).
 * @param {number} targetDays - The SLA target in days.
 * @return {number} The matching bin index (last bin as fallback).
 */
export function findHistogramTargetBinIndex(bins, targetDays) {
	if (!bins || bins.length === 0) return -1
	let idx = bins.findIndex((b) => {
		const parts = b.label.replace('+', '').split('-')
		const min = parseInt(parts[0], 10)
		const max = parts.length > 1 ? parseInt(parts[1], 10) : Infinity
		return targetDays >= min && targetDays <= max
	})
	if (idx === -1) idx = bins.length - 1
	return idx
}

/**
 * Monthly SLA-compliance trend series.
 *
 * @param {Array} trendData - computeMonthlyTrend() output ([{ month, rate }]).
 * @param {string} seriesName - Localised series label.
 * @return {Array} ApexCharts line series.
 */
export function buildTrendSeries(trendData, seriesName) {
	return [
		{
			name: seriesName,
			data: (trendData || []).map((d) => d.rate),
		},
	]
}

/**
 * Weekly throughput series — cases closed per ISO week.
 *
 * @param {Array} throughputData - computeWeeklyThroughput() output ([{ count }]).
 * @param {string} seriesName - Localised series label.
 * @return {Array} ApexCharts line series.
 */
export function buildThroughputSeries(throughputData, seriesName) {
	return [
		{
			name: seriesName,
			data: (throughputData || []).map((w) => w.count),
		},
	]
}

/**
 * Sort performance-table rows by a column key. Null values always sort last
 * (regardless of direction); strings use locale compare, numbers compare
 * numerically. Returns a new array — the input is not mutated.
 *
 * @param {Array} rows - computePerformanceTable() output.
 * @param {string} column - The column key to sort by.
 * @param {string} direction - 'asc' or 'desc'.
 * @return {Array} A new, sorted array of rows.
 */
export function sortPerformanceRows(rows, column, direction) {
	const data = [...(rows || [])]
	const dir = direction === 'asc' ? 1 : -1

	data.sort((a, b) => {
		const aVal = a[column]
		const bVal = b[column]
		if (aVal === null && bVal === null) return 0
		if (aVal === null) return 1
		if (bVal === null) return -1
		if (typeof aVal === 'string') return aVal.localeCompare(bVal) * dir
		return (aVal - bVal) * dir
	})

	return data
}
