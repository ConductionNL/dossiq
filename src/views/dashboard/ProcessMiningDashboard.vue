<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Process mining bottleneck dashboard.

	Procest ships the DATA PROVIDER (ProcessMiningService + ProcessMiningController)
	and this page config only — every visualisation is an existing nc-vue leaf
	(ADR-Leaf-First, mirrors the doorlooptijd dashboard's ComplianceCharts.vue):

	  - CnKpiGrid + CnStatsBlock — headline KPI tiles
	  - CnChartWidget            — dwell-time-by-status bar chart + weekly
	                                throughput line chart
	  - a plain table            — bottleneck ranking (no nc-vue table leaf
	                                fits an ad-hoc computed row shape; same
	                                pattern as TermijnDashboard.vue's report
	                                tables)

	No bespoke chart component is built here.

	Spec: openspec/specs/process-mining-bottlenecks/spec.md
-->
<template>
	<div class="process-mining-dashboard">
		<div class="process-mining-dashboard__header">
			<div>
				<h2>{{ t('procest', 'Process Mining') }}</h2>
				<p class="process-mining-dashboard__subtitle">
					{{
						t(
							'procest',
							'Bottleneck analysis from recorded case status history',
						)
					}}
				</p>
			</div>
			<div class="process-mining-dashboard__controls">
				<NcActions :forceMenu="true">
					<template #icon>
						<Calendar :size="20" />
					</template>
					<NcActionButton
						v-for="preset in datePresets"
						:key="preset.key"
						:class="{ 'active-preset': selectedPreset === preset.key }"
						@click="applyPreset(preset.key)">
						{{ preset.label }}
					</NcActionButton>
				</NcActions>
				<NcSelect
					:modelValue="caseTypeFilter"
					:options="caseTypeOptions"
					:inputLabel="t('procest', 'Filter by case type')"
					:placeholder="t('procest', 'All case types')"
					@update:modelValue="onCaseTypeChange" />
				<NcButton variant="secondary" @click="load">
					<template #icon>
						<Refresh :size="18" />
					</template>
					{{ t('procest', 'Refresh') }}
				</NcButton>
			</div>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<div
			v-else-if="!loading && kpiSummary.totalCases === 0"
			class="process-mining-dashboard__empty">
			<p>{{ t('procest', 'No status history in the selected period.') }}</p>
		</div>

		<div v-else-if="!loading" class="process-mining-dashboard__content">
			<CnKpiGrid :columns="4">
				<CnStatsBlock
					:title="t('procest', 'Cases analysed')"
					:count="kpiSummary.totalCases"
					variant="primary" />
				<CnStatsBlock
					:title="t('procest', 'Case types')"
					:count="kpiSummary.caseTypeCount"
					variant="default" />
				<CnStatsBlock
					:title="t('procest', 'Overall rework rate')"
					variant="warning">
					<template #value>
						{{ kpiSummary.overallReworkPercent }}%
					</template>
				</CnStatsBlock>
				<CnStatsBlock
					:title="t('procest', 'Top bottleneck')"
					variant="error">
					<template #value>
						{{ topBottleneckLabel }}
					</template>
				</CnStatsBlock>
			</CnKpiGrid>

			<NcNoteCard v-if="kpiSummary.overallReworkPercent >= 20" type="warning">
				{{
					t(
						'procest',
						'{percent}% of recorded transitions revisit a status the case had already left — a high rework rate usually means guard conditions or handler routing need a closer look.',
						{ percent: kpiSummary.overallReworkPercent },
					)
				}}
			</NcNoteCard>

			<div class="process-mining-dashboard__charts">
				<div class="chart-card">
					<h3>
						{{ t('procest', 'Dwell time by status (median hours)') }}
					</h3>
					<div v-if="dwellSeries.length > 0" class="chart-container">
						<CnChartWidget
							type="bar"
							:height="280"
							:series="dwellSeries"
							:categories="dwellCategories"
							:options="dwellOptions" />
					</div>
					<div v-else class="chart-empty">
						{{ t('procest', 'No dwell-time data available') }}
					</div>
				</div>

				<div class="chart-card">
					<h3>{{ t('procest', 'Throughput (cases closed per week)') }}</h3>
					<div v-if="throughputSeries.length > 0" class="chart-container">
						<CnChartWidget
							type="line"
							:height="280"
							:series="throughputSeries"
							:categories="throughputCategories"
							:options="throughputOptions" />
					</div>
					<div v-else class="chart-empty">
						{{
							t('procest', 'No completed cases in the selected range')
						}}
					</div>
				</div>
			</div>

			<div class="process-mining-dashboard__table-section">
				<h3>{{ t('procest', 'Bottleneck ranking') }}</h3>
				<p class="process-mining-dashboard__table-hint">
					{{
						t(
							'procest',
							'Ranked by median dwell time × case volume — the statuses most worth investigating first.',
						)
					}}
				</p>
				<table
					v-if="bottleneckRows.length > 0"
					class="process-mining-dashboard__table">
					<thead>
						<tr>
							<th scope="col">{{ t('procest', 'Case type') }}</th>
							<th scope="col">{{ t('procest', 'Status') }}</th>
							<th scope="col">{{ t('procest', 'Median hours') }}</th>
							<th scope="col">{{ t('procest', 'Visits') }}</th>
							<th scope="col">{{ t('procest', 'Score') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="(row, idx) in bottleneckRows" :key="idx">
							<td>{{ row.caseTypeTitle }}</td>
							<td>{{ row.statusName }}</td>
							<td>{{ row.medianHours }}</td>
							<td>{{ row.visitCount }}</td>
							<td>{{ row.score }}</td>
						</tr>
					</tbody>
				</table>
				<p v-else class="process-mining-dashboard__empty-hint">
					{{ t('procest', 'No bottleneck data for the selected period.') }}
				</p>
			</div>
		</div>
	</div>
</template>

<script>
import { CnChartWidget, CnKpiGrid, CnStatsBlock } from '@conduction/nextcloud-vue'
import {
	NcActionButton,
	NcActions,
	NcButton,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import Calendar from 'vue-material-design-icons/Calendar.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import { fetchProcessMiningReport } from '../../services/processMiningApi.js'
import { useObjectStore } from '../../store/modules/object.js'
import {
	buildBottleneckRows,
	buildDwellCategories,
	buildDwellSeries,
	buildKpiSummary,
	buildThroughputCategories,
	buildThroughputSeries,
} from '../processMining/processMiningShaping.js'

export default {
	name: 'ProcessMiningDashboard',
	components: {
		NcActions,
		NcActionButton,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		CnChartWidget,
		CnKpiGrid,
		CnStatsBlock,
		Calendar,
		Refresh,
	},

	data() {
		return {
			loading: false,
			error: null,
			report: null,
			caseTypes: [],
			selectedPreset: '12m',
			caseTypeFilter: null,
		}
	},

	computed: {
		/** @spec openspec/specs/process-mining-bottlenecks/spec.md */
		objectStore() {
			return useObjectStore()
		},

		/** @spec openspec/specs/process-mining-bottlenecks/spec.md */
		datePresets() {
			return [
				{ key: '3m', label: t('procest', 'Last 3 months') },
				{ key: '6m', label: t('procest', 'Last 6 months') },
				{ key: '12m', label: t('procest', 'Last 12 months') },
				{ key: 'all', label: t('procest', 'All time') },
			]
		},

		/** @spec openspec/specs/process-mining-bottlenecks/spec.md */
		caseTypeOptions() {
			return this.caseTypes.map((ct) => ({
				id: ct.id,
				label: ct.title || ct.name,
			}))
		},

		/** @spec openspec/specs/process-mining-bottlenecks/spec.md */
		caseTypesList() {
			return this.report?.caseTypes || []
		},

		/** @spec openspec/specs/process-mining-bottlenecks/spec.md */
		kpiSummary() {
			return buildKpiSummary(this.report)
		},

		/** @spec openspec/specs/process-mining-bottlenecks/spec.md */
		topBottleneckLabel() {
			const top = this.kpiSummary.topBottleneck
			if (!top) return '—'
			return `${top.statusName} (${top.medianHours}h)`
		},

		/** Dwell chart scopes to the filtered case type, or the busiest one otherwise. */
		primaryCaseType() {
			if (this.caseTypeFilter) {
				return (
					this.caseTypesList.find((ct) => ct.id === this.caseTypeFilter)
					|| null
				)
			}
			return this.caseTypesList[0] || null
		},

		/** @spec openspec/specs/process-mining-bottlenecks/spec.md */
		dwellSeries() {
			return buildDwellSeries(
				this.primaryCaseType?.dwellTime,
				t('procest', 'Median hours'),
			)
		},

		/** @spec openspec/specs/process-mining-bottlenecks/spec.md */
		dwellCategories() {
			return buildDwellCategories(this.primaryCaseType?.dwellTime)
		},

		/** @spec openspec/specs/process-mining-bottlenecks/spec.md */
		dwellOptions() {
			return {
				plotOptions: { bar: { borderRadius: 4, columnWidth: '60%' } },
				xaxis: { title: { text: t('procest', 'Status') } },
				yaxis: { title: { text: t('procest', 'Median hours') } },
				colors: ['var(--color-warning)'],
			}
		},

		/** @spec openspec/specs/process-mining-bottlenecks/spec.md */
		throughputSeries() {
			return buildThroughputSeries(
				this.report?.throughputTrend,
				t('procest', 'Cases closed'),
			)
		},

		/** @spec openspec/specs/process-mining-bottlenecks/spec.md */
		throughputCategories() {
			return buildThroughputCategories(this.report?.throughputTrend)
		},

		/** @spec openspec/specs/process-mining-bottlenecks/spec.md */
		throughputOptions() {
			return {
				yaxis: {
					min: 0,
					forceNiceScale: true,
					labels: { formatter: (val) => Math.round(val) },
				},

				colors: ['var(--color-primary)'],
				stroke: { curve: 'smooth', width: 3 },
				markers: { size: 4 },
			}
		},

		/** @spec openspec/specs/process-mining-bottlenecks/spec.md */
		bottleneckRows() {
			return buildBottleneckRows(this.caseTypesList, 10)
		},

		/** @spec openspec/specs/process-mining-bottlenecks/spec.md */
		dateRange() {
			const now = new Date()
			let from = null
			switch (this.selectedPreset) {
				case '3m':
					from = new Date(now.getFullYear(), now.getMonth() - 3, 1)
					break
				case '6m':
					from = new Date(now.getFullYear(), now.getMonth() - 6, 1)
					break
				case 'all':
					from = null
					break
				default:
					from = new Date(now.getFullYear(), now.getMonth() - 12, 1)
			}
			return { from, to: now }
		},
	},

	async mounted() {
		await this.loadCaseTypes()
		await this.load()
	},

	methods: {
		t,
		/**
		 * @param key
		 * @spec openspec/specs/process-mining-bottlenecks/spec.md
		 */
		applyPreset(key) {
			this.selectedPreset = key
			this.load()
		},

		/**
		 * @param {object|null} opt Selected NcSelect option, or null to clear the filter.
		 * @spec openspec/specs/process-mining-bottlenecks/spec.md
		 */
		onCaseTypeChange(opt) {
			this.caseTypeFilter = opt ? opt.id : null
			this.load()
		},

		/** @spec openspec/specs/process-mining-bottlenecks/spec.md */
		async loadCaseTypes() {
			try {
				this.caseTypes =
					(await this.objectStore.fetchCollection('caseType', {
						_limit: 100,
					})) || []
			} catch (err) {
				// Non-fatal — the case-type filter simply stays empty.
				// eslint-disable-next-line no-console
				console.warn('Process mining: could not load case types', err)
			}
		},

		/** @spec openspec/specs/process-mining-bottlenecks/spec.md */
		async load() {
			this.loading = true
			this.error = null
			try {
				const { from, to } = this.dateRange
				this.report = await fetchProcessMiningReport({
					from: from ? from.toISOString().slice(0, 10) : null,
					to: to.toISOString().slice(0, 10),
					caseType: this.caseTypeFilter,
				})
			} catch (err) {
				this.error =
					err?.response?.data?.message
					|| err.message
					|| t('procest', 'Failed to load process-mining report')
				this.report = null
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.process-mining-dashboard {
	padding: 20px;
	max-width: 1200px;
	margin: 0 auto;
}

.process-mining-dashboard__header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	margin-bottom: 24px;
	flex-wrap: wrap;
	gap: 12px;
}

.process-mining-dashboard__subtitle {
	color: var(--color-text-maxcontrast);
	font-size: 14px;
	margin: 4px 0 0;
}

.process-mining-dashboard__controls {
	display: flex;
	gap: 8px;
	align-items: center;
	flex-wrap: wrap;
}

.process-mining-dashboard__empty,
.process-mining-dashboard__empty-hint {
	text-align: center;
	padding: 40px 20px;
	color: var(--color-text-maxcontrast);
}

.process-mining-dashboard__charts {
	display: flex;
	gap: 16px;
	margin: 24px 0;
	flex-wrap: wrap;
}

.chart-card {
	flex: 1;
	min-width: 320px;
	padding: 16px;
	border-radius: 8px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
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

.process-mining-dashboard__table-section {
	padding: 16px;
	border-radius: 8px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
}

.process-mining-dashboard__table-hint {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin: 0 0 12px;
}

.process-mining-dashboard__table {
	width: 100%;
	border-collapse: collapse;
}

.process-mining-dashboard__table th,
.process-mining-dashboard__table td {
	text-align: left;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
}

.active-preset {
	font-weight: 700;
}
</style>
