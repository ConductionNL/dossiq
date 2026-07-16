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
			<DeadlineKpiRow
				:sla-data="slaData"
				:at-risk-count="atRiskCases.length"
				:completed-count="filteredCompletedCases.length" />

			<!-- Charts (donut / histogram / trend / throughput) -->
			<ComplianceCharts
				:sla-data="slaData"
				:distribution-data="distributionData"
				:trend-data="trendData"
				:throughput-data="throughputData" />

			<!-- Woo statutory-deadline panel -->
			<div class="chart-card chart-card--full">
				<WooDeadlinePanel
					:cases="wooCases"
					:loading="loading"
					@click-case="$router.push({ name: 'CaseDetail', params: { id: $event } })"
					@view-all="$router.push({ name: 'Cases', query: { caseTypeContains: 'woo' } })" />
			</div>

			<!-- At-risk cases panel -->
			<DeadlineCaseTable
				:cases="atRiskCases"
				@select-case="$router.push({ name: 'CaseDetail', params: { id: $event } })" />

			<!-- Performance breakdown table -->
			<CaseTypeBreakdown :performance-data="performanceData" />
		</div>
	</div>
</template>

<script>
import { NcButton, NcActions, NcActionButton } from '@nextcloud/vue'
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
import DeadlineKpiRow from './doorlooptijd/components/DeadlineKpiRow.vue'
import ComplianceCharts from './doorlooptijd/components/ComplianceCharts.vue'
import DeadlineCaseTable from './doorlooptijd/components/DeadlineCaseTable.vue'
import CaseTypeBreakdown from './doorlooptijd/components/CaseTypeBreakdown.vue'

export default {
	name: 'DoorlooptijdDashboard',
	components: {
		NcButton,
		NcActions,
		NcActionButton,
		Calendar,
		FilterVariant,
		ArrowLeft,
		WooDeadlinePanel,
		DeadlineKpiRow,
		ComplianceCharts,
		DeadlineCaseTable,
		CaseTypeBreakdown,
	},
	data() {
		return {
			loading: true,
			allCases: [],
			caseTypes: [],
			statusTypes: [],
			selectedPreset: '12m',
			selectedCaseType: null,
			// Server-side metrics payload (kpi / compliance / caseTypeBreakdown / cases) — null until loaded.
			serverMetrics: null,
		}
	},
	computed: {
		/** @spec openspec/specs/doorlooptijd-dashboard/spec.md */
		objectStore() {
			return useObjectStore()
		},
		/** @spec openspec/specs/doorlooptijd-dashboard/spec.md */
		datePresets() {
			return [
				{ key: '3m', label: t('procest', 'Last 3 months') },
				{ key: '6m', label: t('procest', 'Last 6 months') },
				{ key: '12m', label: t('procest', 'Last 12 months') },
				{ key: 'year', label: t('procest', 'This year') },
				{ key: 'all', label: t('procest', 'All time') },
			]
		},
		/** @spec openspec/specs/doorlooptijd-dashboard/spec.md */
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
		/** @spec openspec/specs/doorlooptijd-dashboard/spec.md */
		statusTypeMap() {
			const map = new Map()
			for (const st of this.statusTypes) {
				map.set(st.id, st)
			}
			return map
		},
		/** @spec openspec/specs/doorlooptijd-dashboard/spec.md */
		completedCases() {
			return this.allCases.filter(c => {
				const st = this.statusTypeMap.get(c.status)
				return st?.isFinal && c.endDate
			})
		},
		/** @spec openspec/specs/doorlooptijd-dashboard/spec.md */
		openCases() {
			return this.allCases.filter(c => {
				const st = this.statusTypeMap.get(c.status)
				return !st?.isFinal
			})
		},
		/**
		 * Open Woo cases with statutory-deadline countdown and severity.
		 *
		 * @spec openspec/specs/dashboard/spec.md
		 */
		wooCases() {
			return getWooCases(this.openCases, this.caseTypes)
		},
		/** @spec openspec/specs/doorlooptijd-dashboard/spec.md */
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
		/** @spec openspec/specs/doorlooptijd-dashboard/spec.md */
		filteredOpenCases() {
			if (this.selectedCaseType) {
				return this.openCases.filter(c => c.caseType === this.selectedCaseType)
			}
			return this.openCases
		},
		/** @spec openspec/specs/doorlooptijd-dashboard/spec.md */
		caseTypesWithSla() {
			return this.caseTypes.filter(ct => ct.processingDeadline && parseDurationToDays(ct.processingDeadline))
		},
		/** @spec openspec/specs/doorlooptijd-dashboard/spec.md */
		showNoCasesState() {
			return !this.loading && this.allCases.length === 0
		},
		/** @spec openspec/specs/doorlooptijd-dashboard/spec.md */
		showNoSlaState() {
			return !this.loading && this.allCases.length > 0 && this.caseTypesWithSla.length === 0
		},
		/** @spec openspec/specs/doorlooptijd-dashboard/spec.md */
		showNoDataInRange() {
			return !this.loading
				&& this.allCases.length > 0
				&& this.caseTypesWithSla.length > 0
				&& this.filteredCompletedCases.length === 0
				&& this.atRiskCases.length === 0
		},
		/** @spec openspec/specs/doorlooptijd-dashboard/spec.md */
		slaData() {
			return computeSlaCompliance(this.filteredCompletedCases, this.caseTypes)
		},
		/** @spec openspec/specs/doorlooptijd-dashboard/spec.md */
		distributionData() {
			return computeProcessingTimeDistribution(this.filteredCompletedCases, this.caseTypes)
		},
		/** @spec openspec/specs/doorlooptijd-dashboard/spec.md */
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
		/** @spec openspec/specs/doorlooptijd-dashboard/spec.md */
		trendData() {
			const casesForTrend = this.selectedCaseType
				? this.completedCases.filter(c => c.caseType === this.selectedCaseType)
				: this.completedCases
			return computeMonthlyTrend(casesForTrend, this.caseTypes, this.trendMonths)
		},
		/** @spec openspec/specs/doorlooptijd-dashboard/spec.md */
		atRiskCases() {
			return getAtRiskCases(this.filteredOpenCases, this.caseTypes, 0.25)
		},
		/** @spec openspec/specs/doorlooptijd-dashboard/spec.md */
		performanceData() {
			return computePerformanceTable(this.filteredCompletedCases, this.caseTypes)
		},
		/**
		 * Weekly throughput — completed cases closed per ISO week over the
		 * trailing 12 weeks of the selected range.
		 *
		 * @spec openspec/specs/dashboard/spec.md
		 */
		throughputData() {
			return computeWeeklyThroughput(this.filteredCompletedCases, 12)
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
		 * Apply a date-range preset (e.g. '3m', '12m', 'year', 'all').
		 *
		 * @param {string} key - The preset key to activate.
		 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
		 */
		applyPreset(key) {
			this.selectedPreset = key
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

/* Full-width chart card wrapper (hosts the Woo deadline panel) */
.chart-card {
	padding: 16px;
	border-radius: 8px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
}

.chart-card--full {
	margin-bottom: 24px;
}

.active-preset {
	font-weight: 700;
}
</style>
