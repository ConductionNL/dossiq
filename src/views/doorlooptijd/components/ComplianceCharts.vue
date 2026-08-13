<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Doorlooptijd chart cluster — the four processing-time charts of the SLA
	dashboard:

	  1. Donut — SLA compliance by case type
	  2. Bar histogram — processing-time distribution (with SLA target line)
	  3. Line — monthly SLA compliance trend
	  4. Line — weekly throughput (cases closed per week)

	MIGRATED to the OpenRegister analytics-series leaf (ADR-022):

	- Procest OWNS the SLA maths: the parent computes compliance / distribution
	  / trend / throughput (computeSlaCompliance etc.), and chartShaping.js maps
	  those into labels + datasets. That zaak-domain calc STAYS in-app.
	- Each computed series is REGISTERED with OR's page-level analytics-series
	  surface (POST /api/integrations/analytics/series) so OR owns persistence +
	  the chart-ready render contract + the page-widget declaration.
	- The chart itself is drawn by `@conduction/nextcloud-vue`'s declarative
	  `CnChartWidget` (which owns the chart engine). Procest embeds NO chart
	  library of its own — the bespoke `vue-apexcharts` import + per-chart
	  ApexCharts option objects were removed.

	The series-shaping arithmetic is still locked by Vitest (chartShaping.js).

	Spec: openspec/specs/doorlooptijd-dashboard/spec.md
	      openspec/changes/migrate-sla-dashboard-to-analytics-leaf/specs/sla-charts-via-analytics-leaf/spec.md
-->
<template>
	<div class="doorlooptijd-charts">
		<div class="doorlooptijd-charts-row">
			<!-- Donut: SLA by case type -->
			<div class="chart-card">
				<h3>{{ t('procest', 'Compliance by Case Type') }}</h3>
				<div v-if="donutSeries.length > 0" class="chart-container">
					<CnChartWidget
						type="donut"
						:height="280"
						:series="donutSeries"
						:labels="donutLabels"
						:options="donutOptions" />
				</div>
				<div v-else class="chart-empty">
					{{ t('procest', 'No data available') }}
				</div>
			</div>

			<!-- Histogram: processing time distribution -->
			<div class="chart-card">
				<h3>{{ t('procest', 'Processing Time Distribution') }}</h3>
				<div v-if="histogramSeries.length > 0" class="chart-container">
					<CnChartWidget
						type="bar"
						:height="280"
						:series="histogramSeries"
						:categories="histogramCategories"
						:options="histogramOptions" />
				</div>
				<div v-else class="chart-empty">
					{{ t('procest', 'No data available') }}
				</div>
			</div>
		</div>

		<!-- Trend chart -->
		<div class="chart-card chart-card--full">
			<h3>{{ t('procest', 'Monthly SLA Trend') }}</h3>
			<div v-if="trendData.length > 0" class="chart-container">
				<CnChartWidget
					type="line"
					:height="280"
					:series="trendSeries"
					:categories="trendCategories"
					:options="trendOptions" />
			</div>
			<div v-else class="chart-empty">
				{{ t('procest', 'No trend data available') }}
			</div>
		</div>

		<!-- Throughput chart — cases closed per week -->
		<div class="chart-card chart-card--full">
			<h3>{{ t('procest', 'Throughput (cases closed per week)') }}</h3>
			<div v-if="throughputData.length > 0" class="chart-container">
				<CnChartWidget
					type="line"
					:height="280"
					:series="throughputSeries"
					:categories="throughputCategories"
					:options="throughputOptions" />
			</div>
			<div v-else class="chart-empty">
				{{ t('procest', 'No completed cases in the selected range') }}
			</div>
		</div>
	</div>
</template>

<script>
import { CnChartWidget } from '@conduction/nextcloud-vue'
import {
	buildDonutSeries,
	buildDonutLabels,
	buildHistogramSeries,
	findHistogramTargetBinIndex,
	buildTrendSeries,
	buildThroughputSeries,
} from './chartShaping.js'
import { registerSeries } from '../../../services/analyticsSeriesApi.js'

export default {
	name: 'ComplianceCharts',
	components: {
		CnChartWidget,
	},
	props: {
		/** computeSlaCompliance() output: { byType: [{ name, total, withinSla, rate }], ... }. */
		slaData: {
			type: Object,
			required: true,
		},
		/** computeProcessingTimeDistribution() output: { bins: [{ label, count }], slaTargetDays }. */
		distributionData: {
			type: Object,
			required: true,
		},
		/** computeMonthlyTrend() output: [{ month, rate, withinSla, total }]. */
		trendData: {
			type: Array,
			default: () => [],
		},
		/** computeWeeklyThroughput() output: [{ weekLabel, count }]. */
		throughputData: {
			type: Array,
			default: () => [],
		},
	},
	computed: {
		/**
		 * Donut series — within-SLA count per case type that has at least one case.
		 *
		 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
		 */
		donutSeries() {
			return buildDonutSeries(this.slaData)
		},
		/**
		 * Donut slice labels (one per qualifying case type).
		 *
		 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
		 */
		donutLabels() {
			return buildDonutLabels(this.slaData)
		},
		/**
		 * Donut render options handed to the analytics-leaf chart widget
		 * (colours, legend, tooltip). The widget owns the chart engine.
		 *
		 * @spec openspec/changes/migrate-sla-dashboard-to-analytics-leaf/tasks.md#P1.1
		 */
		donutOptions() {
			const types = this.slaData.byType.filter((t) => t.total > 0)
			return {
				colors: [
					'var(--color-success)',
					'var(--color-primary)',
					'var(--color-warning)',
					'var(--color-error)',
					'var(--color-primary-element-light)',
					'var(--color-text-maxcontrast)',
				],
				legend: { position: 'bottom' },
				plotOptions: {
					pie: {
						donut: {
							labels: {
								show: true,
								total: {
									show: true,
									label: t('procest', 'Within SLA'),
								},
							},
						},
					},
				},
				tooltip: {
					y: {
						formatter: (val, opts) => {
							const typeData = types[opts.seriesIndex]
							if (!typeData) return val
							return `${val}/${typeData.total} (${typeData.rate}%)`
						},
					},
				},
			}
		},
		/**
		 * Histogram series — case count per processing-time bin.
		 *
		 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
		 */
		histogramSeries() {
			return buildHistogramSeries(this.distributionData, t('procest', 'Cases'))
		},
		/**
		 * Histogram x-axis categories (the processing-time bins).
		 *
		 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
		 */
		histogramCategories() {
			return (this.distributionData.bins || []).map((b) => b.label)
		},
		/**
		 * Histogram render options with the SLA-target annotation line.
		 *
		 * @spec openspec/changes/migrate-sla-dashboard-to-analytics-leaf/tasks.md#P1.1
		 */
		histogramOptions() {
			const bins = this.distributionData.bins
			const annotations = []

			if (this.distributionData.slaTargetDays !== null) {
				const targetDays = this.distributionData.slaTargetDays
				const targetBinIndex = findHistogramTargetBinIndex(bins, targetDays)
				annotations.push({
					x: bins[targetBinIndex]?.label || '',
					borderColor: 'var(--color-error)',
					label: {
						text: t('procest', 'SLA Target: {days}d', {
							days: targetDays,
						}),
						style: {
							color: 'var(--color-error)',
							background: 'var(--color-background-hover)',
						},
					},
				})
			}

			return {
				plotOptions: {
					bar: { borderRadius: 4, columnWidth: '70%' },
				},
				xaxis: {
					title: { text: t('procest', 'Processing time (days)') },
				},
				yaxis: {
					title: { text: t('procest', 'Number of cases') },
				},
				colors: ['var(--color-primary)'],
				annotations: { xaxis: annotations },
				tooltip: {
					y: { formatter: (val) => `${val} ${t('procest', 'cases')}` },
				},
			}
		},
		/**
		 * Trend series — monthly SLA compliance rate.
		 *
		 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
		 */
		trendSeries() {
			return buildTrendSeries(this.trendData, t('procest', 'SLA Compliance %'))
		},
		/**
		 * Trend x-axis categories (months).
		 *
		 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
		 */
		trendCategories() {
			return this.trendData.map((d) => d.month)
		},
		/**
		 * Trend render options (0–100% axis, 100%-target annotation, tooltip).
		 *
		 * @spec openspec/changes/migrate-sla-dashboard-to-analytics-leaf/tasks.md#P1.1
		 */
		trendOptions() {
			return {
				yaxis: {
					min: 0,
					max: 100,
					title: { text: t('procest', 'Compliance %') },
					labels: { formatter: (val) => (val !== null ? val + '%' : '') },
				},
				colors: ['var(--color-primary)'],
				stroke: { curve: 'smooth', width: 3 },
				markers: { size: 5 },
				annotations: {
					yaxis: [
						{
							y: 100,
							borderColor: 'var(--color-success)',
							strokeDashArray: 4,
							label: {
								text: t('procest', '100% target'),
								style: {
									color: 'var(--color-success)',
									background: 'var(--color-background-hover)',
								},
							},
						},
					],
				},
				tooltip: {
					y: {
						formatter: (val, opts) => {
							if (val === null) return t('procest', 'No data')
							const dataPoint = this.trendData[opts.dataPointIndex]
							return `${val}% (${dataPoint.withinSla}/${dataPoint.total})`
						},
					},
				},
			}
		},
		/**
		 * Throughput series — cases closed per ISO week.
		 *
		 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
		 */
		throughputSeries() {
			return buildThroughputSeries(
				this.throughputData,
				t('procest', 'Cases closed'),
			)
		},
		/**
		 * Throughput x-axis categories (ISO week labels).
		 *
		 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
		 */
		throughputCategories() {
			return this.throughputData.map((w) => w.weekLabel)
		},
		/**
		 * Throughput render options (integer y-axis, smooth line).
		 *
		 * @spec openspec/changes/migrate-sla-dashboard-to-analytics-leaf/tasks.md#P1.1
		 */
		throughputOptions() {
			return {
				yaxis: {
					min: 0,
					forceNiceScale: true,
					title: { text: t('procest', 'Cases closed') },
					labels: { formatter: (val) => Math.round(val) },
				},
				colors: ['var(--color-primary)'],
				stroke: { curve: 'smooth', width: 3 },
				markers: { size: 4 },
			}
		},
	},
	watch: {
		slaData: { handler: 'publishSeries', immediate: false },
		distributionData: { handler: 'publishSeries' },
		trendData: { handler: 'publishSeries' },
		throughputData: { handler: 'publishSeries' },
	},
	mounted() {
		this.publishSeries()
	},
	methods: {
		/**
		 * Register the four computed SLA series with OpenRegister's page-level
		 * analytics-series surface so OR owns persistence + the render contract
		 * + the page-widget declaration (ADR-022). Procest computes the maths;
		 * OR is the analytics leaf. Fire-and-forget: registration failures never
		 * block the dashboard (it still renders the same series via CnChartWidget).
		 *
		 * @spec openspec/changes/migrate-sla-dashboard-to-analytics-leaf/tasks.md#P1.1
		 */
		publishSeries() {
			if (this.donutSeries.length > 0) {
				registerSeries({
					seriesKey: 'procest-sla-compliance-by-type',
					title: t('procest', 'Compliance by Case Type'),
					chartType: 'doughnut',
					labels: this.donutLabels,
					datasets: this.donutSeries,
				})
			}
			if (this.histogramSeries.length > 0) {
				registerSeries({
					seriesKey: 'procest-sla-processing-time-distribution',
					title: t('procest', 'Processing Time Distribution'),
					chartType: 'bar',
					labels: this.histogramCategories,
					datasets: this.histogramSeries,
				})
			}
			if (this.trendData.length > 0) {
				registerSeries({
					seriesKey: 'procest-sla-monthly-trend',
					title: t('procest', 'Monthly SLA Trend'),
					chartType: 'line',
					labels: this.trendCategories,
					datasets: this.trendSeries,
				})
			}
			if (this.throughputData.length > 0) {
				registerSeries({
					seriesKey: 'procest-sla-weekly-throughput',
					title: t('procest', 'Throughput (cases closed per week)'),
					chartType: 'line',
					labels: this.throughputCategories,
					datasets: this.throughputSeries,
				})
			}
		},
	},
}
</script>

<style scoped>
.doorlooptijd-charts-row {
	display: flex;
	gap: 16px;
	margin-bottom: 24px;
}

.chart-card {
	flex: 1;
	padding: 16px;
	border-radius: 8px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
}

.chart-card--full {
	margin-bottom: 24px;
}

.chart-card h3 {
	margin: 0 0 12px;
	font-size: 15px;
	font-weight: 600;
}

.chart-container {
	min-height: 280px;
}

.chart-empty {
	padding: 40px;
	text-align: center;
	color: var(--color-text-maxcontrast);
}

@media (max-width: 768px) {
	.doorlooptijd-charts-row {
		flex-direction: column;
	}
}
</style>
