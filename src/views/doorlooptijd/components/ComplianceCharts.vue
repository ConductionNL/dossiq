<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Doorlooptijd chart cluster — the four ApexCharts widgets of the
	processing-time dashboard, extracted from the monolithic
	DoorlooptijdDashboard.vue:

	  1. Donut — SLA compliance by case type
	  2. Bar histogram — processing-time distribution (with SLA target line)
	  3. Line — monthly SLA compliance trend
	  4. Line — weekly throughput (cases closed per week)

	The component takes the already-aggregated data arrays as props and owns
	the chart-option shaping (series, axes, tooltips, annotations). The exact
	data shaping is locked by Vitest. Behaviour is identical to the inline
	markup it replaces.

	Spec: openspec/specs/doorlooptijd-dashboard/spec.md
-->
<template>
	<div class="doorlooptijd-charts">
		<div class="doorlooptijd-charts-row">
			<!-- Donut: SLA by case type -->
			<div class="chart-card">
				<h3>{{ t('procest', 'Compliance by Case Type') }}</h3>
				<div v-if="donutSeries.length > 0" class="chart-container">
					<apexchart
						type="donut"
						height="280"
						:options="donutOptions"
						:series="donutSeries" />
				</div>
				<div v-else class="chart-empty">
					{{ t('procest', 'No data available') }}
				</div>
			</div>

			<!-- Histogram: processing time distribution -->
			<div class="chart-card">
				<h3>{{ t('procest', 'Processing Time Distribution') }}</h3>
				<div v-if="histogramSeries.length > 0" class="chart-container">
					<apexchart
						type="bar"
						height="280"
						:options="histogramOptions"
						:series="histogramSeries" />
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
				<apexchart
					type="line"
					height="280"
					:options="trendOptions"
					:series="trendSeries" />
			</div>
			<div v-else class="chart-empty">
				{{ t('procest', 'No trend data available') }}
			</div>
		</div>

		<!-- Throughput chart — cases closed per week -->
		<div class="chart-card chart-card--full">
			<h3>{{ t('procest', 'Throughput (cases closed per week)') }}</h3>
			<div v-if="throughputData.length > 0" class="chart-container">
				<apexchart
					type="line"
					height="280"
					:options="throughputOptions"
					:series="throughputSeries" />
			</div>
			<div v-else class="chart-empty">
				{{ t('procest', 'No completed cases in the selected range') }}
			</div>
		</div>
	</div>
</template>

<script>
// eslint-disable-next-line import/no-unresolved
import VueApexCharts from 'vue-apexcharts'
import {
	buildDonutSeries,
	buildDonutLabels,
	buildHistogramSeries,
	findHistogramTargetBinIndex,
	buildTrendSeries,
	buildThroughputSeries,
} from './chartShaping.js'

export default {
	name: 'ComplianceCharts',
	components: {
		apexchart: VueApexCharts,
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
		 * Donut chart options (labels, colours, legend, tooltip).
		 *
		 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
		 */
		donutOptions() {
			const types = this.slaData.byType.filter(t => t.total > 0)
			return {
				chart: {
					type: 'donut',
					fontFamily: 'inherit',
				},
				labels: buildDonutLabels(this.slaData),
				colors: [
					'var(--color-success)',
					'var(--color-primary)',
					'var(--color-warning)',
					'var(--color-error)',
					'var(--color-primary-element-light)',
					'var(--color-text-maxcontrast)',
				],
				legend: {
					position: 'bottom',
					labels: {
						colors: 'var(--color-main-text)',
					},
				},
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
		 * Histogram chart options with the SLA-target annotation line.
		 *
		 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
		 */
		histogramOptions() {
			const bins = this.distributionData.bins
			const annotations = []

			if (this.distributionData.slaTargetDays !== null) {
				const targetDays = this.distributionData.slaTargetDays
				// Find which bin index the target falls in
				const targetBinIndex = findHistogramTargetBinIndex(bins, targetDays)

				annotations.push({
					x: bins[targetBinIndex]?.label || '',
					borderColor: 'var(--color-error)',
					label: {
						text: t('procest', 'SLA Target: {days}d', { days: targetDays }),
						style: {
							color: 'var(--color-error)',
							background: 'var(--color-background-hover)',
						},
					},
				})
			}

			return {
				chart: {
					type: 'bar',
					fontFamily: 'inherit',
					toolbar: { show: false },
				},
				plotOptions: {
					bar: {
						borderRadius: 4,
						columnWidth: '70%',
					},
				},
				xaxis: {
					categories: bins.map(b => b.label),
					title: { text: t('procest', 'Processing time (days)') },
					labels: {
						style: { colors: 'var(--color-main-text)' },
					},
				},
				yaxis: {
					title: { text: t('procest', 'Number of cases') },
					labels: {
						style: { colors: 'var(--color-main-text)' },
					},
				},
				colors: ['var(--color-primary)'],
				annotations: {
					xaxis: annotations,
				},
				tooltip: {
					y: {
						formatter: (val) => `${val} ${t('procest', 'cases')}`,
					},
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
		 * Trend chart options (0–100% axis, 100%-target annotation, tooltip).
		 *
		 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
		 */
		trendOptions() {
			return {
				chart: {
					type: 'line',
					fontFamily: 'inherit',
					toolbar: { show: false },
				},
				xaxis: {
					categories: this.trendData.map(d => d.month),
					labels: {
						style: { colors: 'var(--color-main-text)' },
					},
				},
				yaxis: {
					min: 0,
					max: 100,
					title: { text: t('procest', 'Compliance %') },
					labels: {
						style: { colors: 'var(--color-main-text)' },
						formatter: (val) => val !== null ? val + '%' : '',
					},
				},
				colors: ['var(--color-primary)'],
				stroke: {
					curve: 'smooth',
					width: 3,
				},
				markers: {
					size: 5,
				},
				annotations: {
					yaxis: [{
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
					}],
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
			return buildThroughputSeries(this.throughputData, t('procest', 'Cases closed'))
		},
		/**
		 * Throughput chart options (integer y-axis, smooth line).
		 *
		 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
		 */
		throughputOptions() {
			return {
				chart: {
					type: 'line',
					fontFamily: 'inherit',
					toolbar: { show: false },
				},
				xaxis: {
					categories: this.throughputData.map(w => w.weekLabel),
					labels: {
						style: { colors: 'var(--color-main-text)' },
					},
				},
				yaxis: {
					min: 0,
					forceNiceScale: true,
					title: { text: t('procest', 'Cases closed') },
					labels: {
						style: { colors: 'var(--color-main-text)' },
						formatter: (val) => Math.round(val),
					},
				},
				colors: ['var(--color-primary)'],
				stroke: {
					curve: 'smooth',
					width: 3,
				},
				markers: {
					size: 4,
				},
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
