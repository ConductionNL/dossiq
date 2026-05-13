<template>
	<div class="werkvoorraad">
		<div class="werkvoorraad__header">
			<h2>{{ t('procest', 'Work Queue') }}</h2>
			<NcButton
				:disabled="loading"
				:aria-label="t('procest', 'Refresh')"
				@click="loadData">
				<template #icon>
					<Refresh :size="20" :class="{ 'icon-spinning': loading }" />
				</template>
			</NcButton>
		</div>

		<!-- KPI Cards -->
		<div class="werkvoorraad__kpis">
			<div
				class="werkvoorraad__kpi"
				:class="{ 'werkvoorraad__kpi--active': activeFilter === 'all' }"
				@click="setFilter('all')">
				<span class="werkvoorraad__kpi-value">{{ kpis.openCount }}</span>
				<span class="werkvoorraad__kpi-label">{{ t('procest', 'Open Cases') }}</span>
			</div>
			<div
				class="werkvoorraad__kpi werkvoorraad__kpi--error"
				:class="{ 'werkvoorraad__kpi--active': activeFilter === 'overdue' }"
				@click="setFilter('overdue')">
				<span class="werkvoorraad__kpi-value">{{ kpis.overdueCount }}</span>
				<span class="werkvoorraad__kpi-label">{{ t('procest', 'Overdue') }}</span>
			</div>
			<div
				class="werkvoorraad__kpi"
				@click="setFilter('all')">
				<span class="werkvoorraad__kpi-value">{{ kpis.completedCount }}</span>
				<span class="werkvoorraad__kpi-label">{{ t('procest', 'Completed This Week') }}</span>
			</div>
			<div
				class="werkvoorraad__kpi werkvoorraad__kpi--warning"
				:class="{ 'werkvoorraad__kpi--active': activeFilter === 'unassigned' }"
				@click="setFilter('unassigned')">
				<span class="werkvoorraad__kpi-value">{{ kpis.unassignedCount }}</span>
				<span class="werkvoorraad__kpi-label">{{ t('procest', 'Unassigned') }}</span>
			</div>
		</div>

		<!-- Filter tabs -->
		<div class="werkvoorraad__filters">
			<div class="werkvoorraad__tabs">
				<button
					v-for="tab in tabs"
					:key="tab.key"
					class="werkvoorraad__tab"
					:class="{ 'werkvoorraad__tab--active': activeFilter === tab.key }"
					@click="setFilter(tab.key)">
					{{ tab.label }} ({{ tab.count }})
				</button>
			</div>
			<div class="werkvoorraad__dropdowns">
				<NcSelect
					v-model="selectedCaseType"
					:options="caseTypeOptions"
					label="title"
					track-by="id"
					:placeholder="t('procest', 'All case types')"
					:clearable="true"
					class="werkvoorraad__filter-select" />
			</div>
		</div>

		<!-- Loading state -->
		<NcLoadingIcon v-if="loading" />

		<!-- Empty state -->
		<NcEmptyContent
			v-else-if="filteredCases.length === 0"
			:name="t('procest', 'No cases found')"
			:description="t('procest', 'No open cases match the current filters')">
			<template #icon>
				<BriefcaseCheckOutline :size="64" />
			</template>
		</NcEmptyContent>

		<!-- Case table -->
		<table v-else class="werkvoorraad__table">
			<thead>
				<tr>
					<th>{{ t('procest', 'ID') }}</th>
					<th>{{ t('procest', 'Title') }}</th>
					<th>{{ t('procest', 'Case type') }}</th>
					<th>{{ t('procest', 'Status') }}</th>
					<th>{{ t('procest', 'Handler') }}</th>
					<th>{{ t('procest', 'Deadline') }}</th>
					<th>{{ t('procest', 'Priority') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="caseItem in filteredCases"
					:key="caseItem.id"
					class="werkvoorraad__row"
					:class="{
						'werkvoorraad__row--overdue': isCaseItemOverdue(caseItem),
						'werkvoorraad__row--unassigned': !caseItem.assignee,
					}"
					@click="openCase(caseItem)">
					<td class="werkvoorraad__cell-id">
						{{ caseItem.identifier || '—' }}
					</td>
					<td>{{ caseItem.title }}</td>
					<td>{{ getCaseTypeName(caseItem.caseType) }}</td>
					<td>
						<span class="werkvoorraad__status-badge">
							{{ getStatusName(caseItem.status) }}
						</span>
					</td>
					<td>
						<span v-if="caseItem.assignee">{{ caseItem.assignee }}</span>
						<span v-else class="werkvoorraad__unassigned">{{ t('procest', 'Unassigned') }}</span>
					</td>
					<td :class="{ 'werkvoorraad__deadline--overdue': isCaseItemOverdue(caseItem) }">
						{{ formatDeadline(caseItem) }}
					</td>
					<td>
						<span
							v-if="caseItem.priority && caseItem.priority !== 'normal'"
							class="werkvoorraad__priority"
							:class="`werkvoorraad__priority--${caseItem.priority}`">
							{{ caseItem.priority === 'urgent' ? '!!' : '!' }}
						</span>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcEmptyContent, NcSelect } from '@nextcloud/vue'
import { useObjectStore } from '../store/modules/object.js'
import { isCaseOverdue, getDaysRemaining, formatDate } from '../utils/caseHelpers.js'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import BriefcaseCheckOutline from 'vue-material-design-icons/BriefcaseCheckOutline.vue'

export default {
	name: 'Werkvoorraad',
	components: {
		NcButton,
		NcLoadingIcon,
		NcEmptyContent,
		NcSelect,
		Refresh,
		BriefcaseCheckOutline,
	},
	data() {
		return {
			loading: false,
			cases: [],
			caseTypes: [],
			statusTypes: [],
			completedCases: [],
			activeFilter: 'all',
			selectedCaseType: null,
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		kpis() {
			const openCount = this.cases.length
			const overdueCount = this.cases.filter(c => isCaseOverdue(c, false)).length
			const unassignedCount = this.cases.filter(c => !c.assignee).length
			const completedCount = this.completedCases.length
			return { openCount, overdueCount, unassignedCount, completedCount }
		},
		tabs() {
			return [
				{ key: 'all', label: t('procest', 'All'), count: this.cases.length },
				{ key: 'unassigned', label: t('procest', 'Unassigned'), count: this.cases.filter(c => !c.assignee).length },
				{ key: 'overdue', label: t('procest', 'Overdue'), count: this.cases.filter(c => isCaseOverdue(c, false)).length },
			]
		},
		caseTypeOptions() {
			return this.caseTypes
		},
		filteredCases() {
			let result = [...this.cases]

			// Apply tab filter
			if (this.activeFilter === 'unassigned') {
				result = result.filter(c => !c.assignee)
			} else if (this.activeFilter === 'overdue') {
				result = result.filter(c => isCaseOverdue(c, false))
			}

			// Apply case type filter
			if (this.selectedCaseType) {
				result = result.filter(c => c.caseType === this.selectedCaseType.id)
			}

			// Sort by urgency: overdue first, then by deadline proximity
			result.sort((a, b) => {
				const aOverdue = isCaseOverdue(a, false)
				const bOverdue = isCaseOverdue(b, false)
				if (aOverdue && !bOverdue) return -1
				if (!aOverdue && bOverdue) return 1

				// Priority weight
				const priorityWeight = { urgent: 0, high: 1, normal: 2, low: 3 }
				const aPrio = priorityWeight[a.priority] ?? 2
				const bPrio = priorityWeight[b.priority] ?? 2
				if (aPrio !== bPrio) return aPrio - bPrio

				// Deadline proximity (null deadlines last)
				const aDeadline = a.deadline ? new Date(a.deadline).getTime() : Infinity
				const bDeadline = b.deadline ? new Date(b.deadline).getTime() : Infinity
				return aDeadline - bDeadline
			})

			return result
		},
	},
	async mounted() {
		await this.loadData()
	},
	methods: {
		async loadData() {
			this.loading = true

			const [cases, caseTypes, statusTypes] = await Promise.all([
				this.objectStore.fetchCollection('case', { _limit: 500 }),
				this.objectStore.fetchCollection('caseType', { _limit: 100 }),
				this.objectStore.fetchCollection('statusType', { _limit: 200 }),
			])

			this.caseTypes = caseTypes || []
			this.statusTypes = statusTypes || []

			// Identify final status type IDs
			const finalStatusIds = new Set(
				this.statusTypes
					.filter(st => st.isFinal === true || st.isFinal === 'true')
					.map(st => st.id),
			)

			// Split into open and completed
			const allCases = cases || []
			this.cases = allCases.filter(c => !finalStatusIds.has(c.status))

			// Completed this week
			const now = new Date()
			const weekAgo = new Date(now)
			weekAgo.setDate(weekAgo.getDate() - 7)
			this.completedCases = allCases.filter(c => {
				if (!finalStatusIds.has(c.status)) return false
				if (!c.endDate) return false
				return new Date(c.endDate) >= weekAgo
			})

			this.loading = false
		},

		setFilter(filter) {
			this.activeFilter = filter
		},

		getCaseTypeName(caseTypeId) {
			const ct = this.caseTypes.find(t => t.id === caseTypeId)
			return ct?.title || '—'
		},

		getStatusName(statusId) {
			const st = this.statusTypes.find(s => s.id === statusId)
			return st?.name || '—'
		},

		isCaseItemOverdue(caseItem) {
			return isCaseOverdue(caseItem, false)
		},

		formatDeadline(caseItem) {
			if (!caseItem.deadline) return '—'
			const days = getDaysRemaining(caseItem.deadline)
			const dateStr = formatDate(caseItem.deadline)
			if (days < 0) {
				return `${dateStr} (${Math.abs(days)} ${t('procest', 'days overdue')})`
			}
			if (days === 0) return `${dateStr} (${t('procest', 'today')})`
			return `${dateStr} (${days} ${t('procest', 'days')})`
		},

		openCase(caseItem) {
			this.$router.push({ name: 'CaseDetail', params: { id: caseItem.id } })
		},
	},
}
</script>

<style scoped>
.werkvoorraad {
	padding: 20px;
	max-width: 1200px;
}

.werkvoorraad__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 20px;
}

.werkvoorraad__header h2 {
	margin: 0;
}

/* KPI Cards */
.werkvoorraad__kpis {
	display: flex;
	gap: 16px;
	margin-bottom: 20px;
	flex-wrap: wrap;
}

.werkvoorraad__kpi {
	flex: 1;
	min-width: 140px;
	padding: 16px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius-large);
	text-align: center;
	cursor: pointer;
	transition: background-color 0.2s, box-shadow 0.2s;
	border: 2px solid transparent;
}

.werkvoorraad__kpi:hover {
	background: var(--color-background-hover);
}

.werkvoorraad__kpi--active {
	border-color: var(--color-primary-element);
	box-shadow: 0 0 0 1px var(--color-primary-element);
}

.werkvoorraad__kpi--error .werkvoorraad__kpi-value {
	color: var(--color-error);
}

.werkvoorraad__kpi--warning .werkvoorraad__kpi-value {
	color: var(--color-warning);
}

.werkvoorraad__kpi-value {
	display: block;
	font-size: 28px;
	font-weight: 700;
	line-height: 1.2;
}

.werkvoorraad__kpi-label {
	display: block;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	margin-top: 4px;
}

/* Filters */
.werkvoorraad__filters {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 16px;
	gap: 16px;
	flex-wrap: wrap;
}

.werkvoorraad__tabs {
	display: flex;
	gap: 4px;
}

.werkvoorraad__tab {
	padding: 8px 16px;
	border: none;
	background: var(--color-background-dark);
	border-radius: var(--border-radius-pill);
	cursor: pointer;
	font-size: 14px;
	transition: background-color 0.2s;
}

.werkvoorraad__tab:hover {
	background: var(--color-background-hover);
}

.werkvoorraad__tab--active {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.werkvoorraad__dropdowns {
	display: flex;
	gap: 8px;
}

.werkvoorraad__filter-select {
	min-width: 200px;
}

/* Table */
.werkvoorraad__table {
	width: 100%;
	border-collapse: collapse;
	background: var(--color-main-background);
}

.werkvoorraad__table th,
.werkvoorraad__table td {
	padding: 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.werkvoorraad__table th {
	background: var(--color-background-dark);
	font-weight: 500;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.werkvoorraad__row {
	cursor: pointer;
	transition: background-color 0.2s;
}

.werkvoorraad__row:hover {
	background: var(--color-background-hover);
}

.werkvoorraad__row--overdue {
	border-left: 3px solid var(--color-error);
}

.werkvoorraad__row--unassigned {
	background: var(--color-warning-hover, rgba(255, 193, 7, 0.05));
}

.werkvoorraad__cell-id {
	font-family: var(--font-monospace, monospace);
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.werkvoorraad__status-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	background: var(--color-primary-element-light);
	font-size: 12px;
}

.werkvoorraad__unassigned {
	color: var(--color-warning);
	font-style: italic;
}

.werkvoorraad__deadline--overdue {
	color: var(--color-error);
	font-weight: 600;
}

.werkvoorraad__priority {
	font-weight: 700;
}

.werkvoorraad__priority--urgent {
	color: var(--color-error);
}

.werkvoorraad__priority--high {
	color: var(--color-warning);
}

/* Responsive */
@media (max-width: 768px) {
	.werkvoorraad__kpis {
		flex-direction: column;
	}

	.werkvoorraad__filters {
		flex-direction: column;
		align-items: stretch;
	}

	.werkvoorraad__table th:nth-child(3),
	.werkvoorraad__table td:nth-child(3),
	.werkvoorraad__table th:nth-child(7),
	.werkvoorraad__table td:nth-child(7) {
		display: none;
	}
}
</style>
