<template>
	<div class="doorlooptijd-dashboard">
		<!-- Header -->
		<div class="doorlooptijd-header">
			<div class="doorlooptijd-header__title">
				<h2>{{ t('procest', 'Processing Time Analytics') }}</h2>
				<p class="doorlooptijd-subtitle">
					{{ t('procest', 'SLA adherence and processing time analysis') }}
				</p>
			</div>
			<div class="doorlooptijd-header__actions">
				<!-- Date range presets -->
				<NcActions :force-menu="true">
					<template #icon>
						<Calendar :size="20" />
					</template>
					<NcActionButton v-for="preset in datePresets"
						:key="preset.key"
						:class="{ 'active-preset': selectedPreset === preset.key }"
						@click="applyPreset(preset.key)">
						{{ preset.label }}
					</NcActionButton>
				</NcActions>

				<!-- Case type filter -->
				<NcActions :force-menu="true">
					<template #icon>
						<FilterVariant :size="20" />
					</template>
					<NcActionButton :class="{ 'active-preset': !selectedCaseType }"
						@click="selectedCaseType = null">
						{{ t('procest', 'All case types') }}
					</NcActionButton>
					<NcActionButton v-for="ct in caseTypesWithSla"
						:key="ct.id"
						:class="{ 'active-preset': selectedCaseType === ct.id }"
						@click="selectedCaseType = ct.id">
						{{ ct.title || ct.name }}
					</NcActionButton>
				</NcActions>

				<!-- Back to dashboard -->
				<NcButton type="tertiary"
					@click="$router.push({ name: 'Dashboard' })">
					<template #icon>
						<ArrowLeft :size="20" />
					</template>
					{{ t('procest', 'Dashboard') }}
				</NcButton>
			</div>
		</div>

		<!-- Loading skeleton -->
		<div v-if="loading" class="doorlooptijd-skeleton">
			<div class="skeleton-kpi-row">
				<div v-for="n in 3" :key="n" class="skeleton-kpi" />
			</div>
			<div class="skeleton-charts-row">
				<div class="skeleton-chart" />
				<div class="skeleton-chart" />
			</div>
			<div class="skeleton-table" />
		</div>

		<!-- Empty state: no cases -->
		<div v-else-if="showNoCasesState" class="doorlooptijd-empty">
			<p>{{ t('procest', 'No case data available for processing time analysis.') }}</p>
		</div>

		<!-- Empty state: no SLA targets -->
		<div v-else-if="showNoSlaState" class="doorlooptijd-empty">
			<p>{{ t('procest', 'No SLA targets configured. Set processing deadlines on case types in Settings to enable compliance tracking.') }}</p>
			<NcButton type="primary"
				@click="$router.push({ name: 'Settings' })">
				{{ t('procest', 'Go to Settings') }}
			</NcButton>
		</div>

		<!-- Empty state: no data in range -->
		<div v-else-if="showNoDataInRange" class="doorlooptijd-empty">
			<p>{{ t('procest', 'No completed cases in the selected date range.') }}</p>
		</div>

		<!-- Main content -->
		<div v-else class="doorlooptijd-content">
			<!-- KPI row -->
			<div class="doorlooptijd-kpi-row">
				<div class="kpi-card kpi-card--primary">
					<div class="kpi-card__value">
						{{ slaData.overallRate !== null ? slaData.overallRate + '%' : '—' }}
					</div>
					<div class="kpi-card__label">
						{{ t('procest', 'SLA Compliance') }}
					</div>
					<div class="kpi-card__sub">
						{{ slaData.total > 0
							? t('procest', '{within}/{total} within SLA', { within: slaData.withinSla, total: slaData.total })
							: t('procest', 'No data') }}
					</div>
					<div v-if="slaData.excluded > 0" class="kpi-card__note">
						{{ t('procest', '{count} cases excluded — no SLA target', { count: slaData.excluded }) }}
					</div>
				</div>

				<div class="kpi-card">
					<div class="kpi-card__value">
						{{ atRiskCases.length }}
					</div>
					<div class="kpi-card__label">
						{{ t('procest', 'At Risk') }}
					</div>
					<div class="kpi-card__sub">
						{{ t('procest', 'cases near or past deadline') }}
					</div>
				</div>

				<div class="kpi-card">
					<div class="kpi-card__value">
						{{ filteredCompletedCases.length }}
					</div>
					<div class="kpi-card__label">
						{{ t('procest', 'Completed') }}
					</div>
					<div class="kpi-card__sub">
						{{ t('procest', 'in selected period') }}
					</div>
				</div>
			</div>

			<!-- Charts row -->
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

			<!-- Woo statutory-deadline panel -->
			<div class="chart-card chart-card--full">
				<WooDeadlinePanel
					:cases="wooCases"
					:loading="loading"
					@click-case="$router.push({ name: 'CaseDetail', params: { id: $event } })"
					@view-all="$router.push({ name: 'Cases', query: { caseTypeContains: 'woo' } })" />
			</div>

			<!-- At-risk cases panel -->
			<div v-if="atRiskCases.length > 0" class="at-risk-panel">
				<h3>{{ t('procest', 'At-Risk Cases') }}</h3>
				<div class="at-risk-list">
					<div v-for="c in atRiskCases"
						:key="c.id"
						class="at-risk-item"
						@click="$router.push({ name: 'CaseDetail', params: { id: c.id } })">
						<div class="at-risk-item__header">
							<span class="at-risk-item__title">{{ c.title || c.identifier }}</span>
							<span v-if="c.isOverdue" class="at-risk-badge at-risk-badge--overdue">
								{{ t('procest', 'Overdue') }}
							</span>
							<span v-else class="at-risk-badge at-risk-badge--warning">
								{{ t('procest', 'At risk') }}
							</span>
						</div>
						<div class="at-risk-item__meta">
							<span>{{ c.caseTypeName }}</span>
							<span>{{ c.identifier ? '#' + c.identifier : '' }}</span>
							<span :class="{ 'text-error': c.isOverdue }">
								{{ c.remainingDays >= 0
									? t('procest', '{days} days remaining', { days: c.remainingDays })
									: t('procest', '{days} days overdue', { days: Math.abs(c.remainingDays) }) }}
							</span>
						</div>
						<div class="at-risk-item__progress">
							<div class="progress-track">
								<div class="progress-fill"
									:class="{
										'progress-fill--danger': c.isOverdue,
										'progress-fill--warning': !c.isOverdue && c.percentUsed > 0.75,
									}"
									:style="{ width: Math.min(c.percentUsed * 100, 100) + '%' }" />
							</div>
							<span class="progress-label">
								{{ Math.round(c.percentUsed * 100) }}%
							</span>
						</div>
					</div>
				</div>
			</div>

			<!-- Performance table -->
			<div class="performance-table-card">
				<h3>{{ t('procest', 'Performance by Case Type') }}</h3>
				<table class="performance-table">
					<thead>
						<tr>
							<th class="sortable"
								@click="sortTable('name')">
								{{ t('procest', 'Case Type') }}
								<span v-if="sortColumn === 'name'" class="sort-indicator">
									{{ sortDirection === 'asc' ? '\u25B2' : '\u25BC' }}
								</span>
							</th>
							<th class="sortable numeric"
								@click="sortTable('targetDays')">
								{{ t('procest', 'Target (days)') }}
								<span v-if="sortColumn === 'targetDays'" class="sort-indicator">
									{{ sortDirection === 'asc' ? '\u25B2' : '\u25BC' }}
								</span>
							</th>
							<th class="sortable numeric"
								@click="sortTable('avgActualDays')">
								{{ t('procest', 'Avg Actual (days)') }}
								<span v-if="sortColumn === 'avgActualDays'" class="sort-indicator">
									{{ sortDirection === 'asc' ? '\u25B2' : '\u25BC' }}
								</span>
							</th>
							<th class="sortable numeric"
								@click="sortTable('complianceRate')">
								{{ t('procest', 'Compliance %') }}
								<span v-if="sortColumn === 'complianceRate'" class="sort-indicator">
									{{ sortDirection === 'asc' ? '\u25B2' : '\u25BC' }}
								</span>
							</th>
							<th class="sortable numeric"
								@click="sortTable('total')">
								{{ t('procest', 'Cases') }}
								<span v-if="sortColumn === 'total'" class="sort-indicator">
									{{ sortDirection === 'asc' ? '\u25B2' : '\u25BC' }}
								</span>
							</th>
							<th>{{ t('procest', 'Status') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in sortedPerformanceData" :key="row.id">
							<td>{{ row.name }}</td>
							<td class="numeric">
								{{ row.targetDays !== null ? row.targetDays : '—' }}
							</td>
							<td class="numeric">
								{{ row.avgActualDays !== null ? row.avgActualDays : '—' }}
							</td>
							<td class="numeric">
								{{ row.complianceRate !== null ? row.complianceRate + '%' : '—' }}
							</td>
							<td class="numeric">
								{{ row.total }}
							</td>
							<td>
								<span class="status-dot" :class="'status-dot--' + row.status" />
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcActions, NcActionButton } from '@nextcloud/vue'
// eslint-disable-next-line import/no-unresolved
import VueApexCharts from 'vue-apexcharts'
import Calendar from 'vue-material-design-icons/Calendar.vue'
import FilterVariant from 'vue-material-design-icons/FilterVariant.vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import { useObjectStore } from '../store/modules/object.js'
import {
	computeSlaCompliance,
	computeProcessingTimeDistribution,
	computeMonthlyTrend,
	getAtRiskCases,
	computePerformanceTable,
	parseDurationToDays,
} from '../utils/doorlooptijdHelpers.js'
import { computeWeeklyThroughput, getWooCases } from '../utils/dashboardHelpers.js'
import WooDeadlinePanel from './dashboard/WooDeadlinePanel.vue'

export default {
	name: 'DoorlooptijdDashboard',
	components: {
		NcButton,
		NcActions,
		NcActionButton,
		apexchart: VueApexCharts,
		Calendar,
		FilterVariant,
		ArrowLeft,
		WooDeadlinePanel,
	},
	data() {
		return {
			loading: true,
			allCases: [],
			caseTypes: [],
			statusTypes: [],
			selectedPreset: '12m',
			selectedCaseType: null,
			sortColumn: 'complianceRate',
			sortDirection: 'asc',
			// Server-side metrics payload (kpi / compliance / caseTypeBreakdown / cases) — null until loaded.
			serverMetrics: null,
		}
	},
	computed: {
		/** @spec openspec/changes/doorlooptijd-dashboard/tasks.md */
		objectStore() {
			return useObjectStore()
		},
		/** @spec openspec/changes/doorlooptijd-dashboard/tasks.md */
		datePresets() {
			return [
				{ key: '3m', label: t('procest', 'Last 3 months') },
				{ key: '6m', label: t('procest', 'Last 6 months') },
				{ key: '12m', label: t('procest', 'Last 12 months') },
				{ key: 'year', label: t('procest', 'This year') },
				{ key: 'all', label: t('procest', 'All time') },
			]
		},
		/** @spec openspec/changes/doorlooptijd-dashboard/tasks.md */
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
			case '12m':
				from = new Date(now.getFullYear(), now.getMonth() - 12, 1)
				break
			case 'year':
				from = new Date(now.getFullYear(), 0, 1)
				break
			case 'all':
				from = null
				break
			default:
				from = new Date(now.getFullYear(), now.getMonth() - 12, 1)
			}
			return { from, to: now }
		},
		/** @spec openspec/changes/doorlooptijd-dashboard/tasks.md */
		statusTypeMap() {
			const map = new Map()
			for (const st of this.statusTypes) {
				map.set(st.id, st)
			}
			return map
		},
		/** @spec openspec/changes/doorlooptijd-dashboard/tasks.md */
		completedCases() {
			return this.allCases.filter(c => {
				const st = this.statusTypeMap.get(c.status)
				return st?.isFinal && c.endDate
			})
		},
		/** @spec openspec/changes/doorlooptijd-dashboard/tasks.md */
		openCases() {
			return this.allCases.filter(c => {
				const st = this.statusTypeMap.get(c.status)
				return !st?.isFinal
			})
		},
		/**
		 * Open Woo cases with statutory-deadline countdown and severity.
		 *
		 * @spec openspec/changes/dashboard/specs/dashboard/spec.md#REQ-DASH-V1-004
		 */
		wooCases() {
			return getWooCases(this.openCases, this.caseTypes)
		},
		/** @spec openspec/changes/doorlooptijd-dashboard/tasks.md */
		filteredCompletedCases() {
			let cases = this.completedCases

			// Apply date range
			if (this.dateRange.from) {
				const fromStr = this.dateRange.from.toISOString().slice(0, 10)
				cases = cases.filter(c => c.endDate && c.endDate.slice(0, 10) >= fromStr)
			}

			// Apply case type filter
			if (this.selectedCaseType) {
				cases = cases.filter(c => c.caseType === this.selectedCaseType)
			}

			return cases
		},
		/** @spec openspec/changes/doorlooptijd-dashboard/tasks.md */
		filteredOpenCases() {
			if (this.selectedCaseType) {
				return this.openCases.filter(c => c.caseType === this.selectedCaseType)
			}
			return this.openCases
		},
		/** @spec openspec/changes/doorlooptijd-dashboard/tasks.md */
		caseTypesWithSla() {
			return this.caseTypes.filter(ct => ct.processingDeadline && parseDurationToDays(ct.processingDeadline))
		},
		/** @spec openspec/changes/doorlooptijd-dashboard/tasks.md */
		showNoCasesState() {
			return !this.loading && this.allCases.length === 0
		},
		/** @spec openspec/changes/doorlooptijd-dashboard/tasks.md */
		showNoSlaState() {
			return !this.loading && this.allCases.length > 0 && this.caseTypesWithSla.length === 0
		},
		/** @spec openspec/changes/doorlooptijd-dashboard/tasks.md */
		showNoDataInRange() {
			return !this.loading
				&& this.allCases.length > 0
				&& this.caseTypesWithSla.length > 0
				&& this.filteredCompletedCases.length === 0
				&& this.atRiskCases.length === 0
		},
		/** @spec openspec/changes/doorlooptijd-dashboard/tasks.md */
		slaData() {
			return computeSlaCompliance(this.filteredCompletedCases, this.caseTypes)
		},
		/** @spec openspec/changes/doorlooptijd-dashboard/tasks.md */
		distributionData() {
			return computeProcessingTimeDistribution(this.filteredCompletedCases, this.caseTypes)
		},
		/** @spec openspec/changes/doorlooptijd-dashboard/tasks.md */
		trendMonths() {
			switch (this.selectedPreset) {
			case '3m': return 3
			case '6m': return 6
			case 'year': {
				const now = new Date()
				return now.getMonth() + 1
			}
			case 'all': return 24
			default: return 12
			}
		},
		/** @spec openspec/changes/doorlooptijd-dashboard/tasks.md */
		trendData() {
			const casesForTrend = this.selectedCaseType
				? this.completedCases.filter(c => c.caseType === this.selectedCaseType)
				: this.completedCases
			return computeMonthlyTrend(casesForTrend, this.caseTypes, this.trendMonths)
		},
		/** @spec openspec/changes/doorlooptijd-dashboard/tasks.md */
		atRiskCases() {
			return getAtRiskCases(this.filteredOpenCases, this.caseTypes, 0.25)
		},
		/** @spec openspec/changes/doorlooptijd-dashboard/tasks.md */
		performanceData() {
			return computePerformanceTable(this.filteredCompletedCases, this.caseTypes)
		},
		/** @spec openspec/changes/doorlooptijd-dashboard/tasks.md */
		sortedPerformanceData() {
			const data = [...this.performanceData]
			const col = this.sortColumn
			const dir = this.sortDirection === 'asc' ? 1 : -1

			data.sort((a, b) => {
				const aVal = a[col]
				const bVal = b[col]
				if (aVal === null && bVal === null) return 0
				if (aVal === null) return 1
				if (bVal === null) return -1
				if (typeof aVal === 'string') return aVal.localeCompare(bVal) * dir
				return (aVal - bVal) * dir
			})

			return data
		},
		// Chart configurations
		/** @spec openspec/changes/doorlooptijd-dashboard/tasks.md */
		donutSeries() {
			const types = this.slaData.byType.filter(t => t.total > 0)
			return types.map(t => t.withinSla)
		},
		/** @spec openspec/changes/doorlooptijd-dashboard/tasks.md */
		donutOptions() {
			const types = this.slaData.byType.filter(t => t.total > 0)
			return {
				chart: {
					type: 'donut',
					fontFamily: 'inherit',
				},
				labels: types.map(t => t.name),
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
		/** @spec openspec/changes/doorlooptijd-dashboard/tasks.md */
		histogramSeries() {
			const bins = this.distributionData.bins
			if (bins.every(b => b.count === 0)) return []
			return [{
				name: t('procest', 'Cases'),
				data: bins.map(b => b.count),
			}]
		},
		/** @spec openspec/changes/doorlooptijd-dashboard/tasks.md */
		histogramOptions() {
			const bins = this.distributionData.bins
			const annotations = []

			if (this.distributionData.slaTargetDays !== null) {
				const targetDays = this.distributionData.slaTargetDays
				// Find which bin index the target falls in
				let targetBinIndex = bins.findIndex(b => {
					const parts = b.label.replace('+', '').split('-')
					const min = parseInt(parts[0], 10)
					const max = parts.length > 1 ? parseInt(parts[1], 10) : Infinity
					return targetDays >= min && targetDays <= max
				})
				if (targetBinIndex === -1) targetBinIndex = bins.length - 1

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
		/** @spec openspec/changes/doorlooptijd-dashboard/tasks.md */
		trendSeries() {
			return [{
				name: t('procest', 'SLA Compliance %'),
				data: this.trendData.map(d => d.rate),
			}]
		},
		/**
		 * Weekly throughput — completed cases closed per ISO week over the
		 * trailing 12 weeks of the selected range.
		 *
		 * @spec openspec/changes/dashboard/specs/dashboard/spec.md#REQ-DASH-V1-005
		 */
		throughputData() {
			return computeWeeklyThroughput(this.filteredCompletedCases, 12)
		},
		/** @spec openspec/changes/dashboard/specs/dashboard/spec.md#REQ-DASH-V1-005 */
		throughputSeries() {
			return [{
				name: t('procest', 'Cases closed'),
				data: this.throughputData.map(w => w.count),
			}]
		},
		/** @spec openspec/changes/dashboard/specs/dashboard/spec.md#REQ-DASH-V1-005 */
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
		/** @spec openspec/changes/doorlooptijd-dashboard/tasks.md */
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
	},
	async mounted() {
		await this.loadData()
	},
	methods: {
		/** @spec openspec/changes/doorlooptijd-dashboard/tasks.md#T05 */
		async loadData() {
			this.loading = true
			try {
				// Primary path: server-aggregated metrics (kpi/compliance/breakdown/cases).
				try {
					const { fetchMetrics } = await import('../services/doorlooptijdApi.js')
					this.serverMetrics = await fetchMetrics({ caseType: this.selectedCaseType })
				} catch (apiErr) {
					console.warn('Doorlooptijd server metrics unavailable, falling back to client aggregation', apiErr)
					this.serverMetrics = null
				}

				const results = await Promise.allSettled([
					this.objectStore.fetchCollection('case', { _limit: 5000 }),
					this.objectStore.fetchCollection('caseType', { _limit: 100 }),
					this.objectStore.fetchCollection('statusType', { _limit: 500 }),
				])

				this.allCases = results[0].status === 'fulfilled' ? (results[0].value || []) : []
				this.caseTypes = results[1].status === 'fulfilled' ? (results[1].value || []) : []
				this.statusTypes = results[2].status === 'fulfilled' ? (results[2].value || []) : []
			} catch (err) {
				console.error('Doorlooptijd data fetch error:', err)
			} finally {
				this.loading = false
			}
		},
		/**
		 * @param key
		 * @spec openspec/changes/doorlooptijd-dashboard/tasks.md
		 */
		applyPreset(key) {
			this.selectedPreset = key
		},
		/**
		 * @param column
		 * @spec openspec/changes/doorlooptijd-dashboard/tasks.md
		 */
		sortTable(column) {
			if (this.sortColumn === column) {
				this.sortDirection = this.sortDirection === 'asc' ? 'desc' : 'asc'
			} else {
				this.sortColumn = column
				this.sortDirection = 'asc'
			}
		},
	},
}
</script>

<style scoped>
.doorlooptijd-dashboard {
	padding: 20px;
	max-width: 1200px;
	margin: 0 auto;
}

/* Header */
.doorlooptijd-header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	margin-bottom: 24px;
	flex-wrap: wrap;
	gap: 12px;
}

.doorlooptijd-header__title h2 {
	margin: 0 0 4px;
	font-size: 22px;
}

.doorlooptijd-subtitle {
	color: var(--color-text-maxcontrast);
	font-size: 14px;
	margin: 0;
}

.doorlooptijd-header__actions {
	display: flex;
	gap: 8px;
	align-items: center;
}

/* Loading skeleton */
.doorlooptijd-skeleton {
	display: flex;
	flex-direction: column;
	gap: 20px;
}

.skeleton-kpi-row {
	display: flex;
	gap: 16px;
}

.skeleton-kpi {
	flex: 1;
	height: 100px;
	background: var(--color-background-dark);
	border-radius: 8px;
	animation: pulse 1.5s ease-in-out infinite;
}

.skeleton-charts-row {
	display: flex;
	gap: 16px;
}

.skeleton-chart {
	flex: 1;
	height: 320px;
	background: var(--color-background-dark);
	border-radius: 8px;
	animation: pulse 1.5s ease-in-out infinite;
}

.skeleton-table {
	height: 200px;
	background: var(--color-background-dark);
	border-radius: 8px;
	animation: pulse 1.5s ease-in-out infinite;
}

@keyframes pulse {
	0%, 100% { opacity: 1; }
	50% { opacity: 0.5; }
}

/* Empty states */
.doorlooptijd-empty {
	text-align: center;
	padding: 60px 20px;
	color: var(--color-text-maxcontrast);
	font-size: 15px;
}

.doorlooptijd-empty p {
	margin-bottom: 16px;
}

/* KPI row */
.doorlooptijd-kpi-row {
	display: flex;
	gap: 16px;
	margin-bottom: 24px;
}

.kpi-card {
	flex: 1;
	padding: 16px 20px;
	border-radius: 8px;
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
}

.kpi-card--primary {
	background: var(--color-primary-element-light);
	border-color: var(--color-primary-element);
}

.kpi-card__value {
	font-size: 32px;
	font-weight: 700;
	line-height: 1.2;
}

.kpi-card__label {
	font-size: 14px;
	font-weight: 600;
	margin-top: 4px;
}

.kpi-card__sub {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-top: 2px;
}

.kpi-card__note {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	margin-top: 4px;
	font-style: italic;
}

/* Charts */
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

/* At-risk panel */
.at-risk-panel {
	padding: 16px;
	border-radius: 8px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	margin-bottom: 24px;
}

.at-risk-panel h3 {
	margin: 0 0 12px;
	font-size: 15px;
	font-weight: 600;
}

.at-risk-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.at-risk-item {
	padding: 12px;
	border-radius: 6px;
	border: 1px solid var(--color-border);
	cursor: pointer;
	transition: background 0.15s;
}

.at-risk-item:hover {
	background: var(--color-background-hover);
}

.at-risk-item__header {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 4px;
}

.at-risk-item__title {
	font-weight: 600;
	font-size: 14px;
	flex: 1;
}

.at-risk-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 10px;
	font-size: 11px;
	font-weight: 600;
}

.at-risk-badge--overdue {
	background: rgba(233, 50, 45, 0.12);
	color: var(--color-error);
}

.at-risk-badge--warning {
	background: rgba(232, 163, 22, 0.12);
	color: var(--color-warning-text);
}

.at-risk-item__meta {
	display: flex;
	gap: 12px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-bottom: 6px;
}

.at-risk-item__progress {
	display: flex;
	align-items: center;
	gap: 8px;
}

.progress-track {
	flex: 1;
	height: 6px;
	background: var(--color-background-dark);
	border-radius: 3px;
	overflow: hidden;
}

.progress-fill {
	height: 100%;
	border-radius: 3px;
	background: var(--color-warning);
	transition: width 0.3s;
}

.progress-fill--danger {
	background: var(--color-error);
}

.progress-fill--warning {
	background: var(--color-warning);
}

.progress-label {
	font-size: 12px;
	font-weight: 600;
	width: 40px;
	text-align: right;
}

/* Performance table */
.performance-table-card {
	padding: 16px;
	border-radius: 8px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
}

.performance-table-card h3 {
	margin: 0 0 12px;
	font-size: 15px;
	font-weight: 600;
}

.performance-table {
	width: 100%;
	border-collapse: collapse;
}

.performance-table th,
.performance-table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
	font-size: 13px;
}

.performance-table th {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.performance-table th.sortable {
	cursor: pointer;
	user-select: none;
}

.performance-table th.sortable:hover {
	color: var(--color-main-text);
}

.performance-table .numeric {
	text-align: right;
}

.sort-indicator {
	margin-left: 4px;
	font-size: 10px;
}

.status-dot {
	display: inline-block;
	width: 10px;
	height: 10px;
	border-radius: 50%;
	background: var(--color-text-maxcontrast);
}

.status-dot--good {
	background: var(--color-success);
}

.status-dot--warning {
	background: var(--color-warning);
}

.status-dot--critical {
	background: var(--color-error);
}

.status-dot--no-target {
	background: var(--color-text-maxcontrast);
}

.text-error {
	color: var(--color-error);
	font-weight: 600;
}

.active-preset {
	font-weight: 700;
}

/* Responsive */
@media (max-width: 768px) {
	.doorlooptijd-kpi-row,
	.doorlooptijd-charts-row {
		flex-direction: column;
	}
}
</style>
