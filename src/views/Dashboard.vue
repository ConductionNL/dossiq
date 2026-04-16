<template>
	<div>
		<CnDashboardPage
			:title="t('procest', 'Dashboard')"
			:widgets="widgetDefs"
			:layout="dashboardLayout"
			:loading="globalLoading && !hasData"
			:empty-label="t('procest', 'No widgets configured')"
			:unavailable-label="t('procest', 'Widget not available')"
			@layout-change="onLayoutChange">
			<!-- Header actions: quick action buttons -->
			<template #header-actions>
				<NcButton type="tertiary"
					@click="$router.push({ name: 'Doorlooptijd' })">
					<template #icon>
						<ChartTimeline :size="20" />
					</template>
					{{ t('procest', 'Doorlooptijd') }}
				</NcButton>
				<NcButton type="primary" @click="showCreateDialog = true">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('procest', 'New Case') }}
				</NcButton>
				<NcButton @click="showTaskDialog = true">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('procest', 'New Task') }}
				</NcButton>
				<NcButton :disabled="globalLoading"
					:aria-label="t('procest', 'Refresh dashboard')"
					@click="loadDashboardData">
					<template #icon>
						<Refresh :size="20" :class="{ 'icon-spinning': globalLoading }" />
					</template>
				</NcButton>
			</template>

			<!-- Open Cases count widget -->
			<template #widget-count-open-cases>
				<CnStatsBlock
					:title="t('procest', 'Open Cases')"
					:count="kpis.openCount"
					:count-label="t('procest', 'open')"
					:icon="FolderOpen"
					variant="primary"
					horizontal
					:route="{ name: 'Cases', query: { status: 'open' } }" />
			</template>

			<!-- Overdue count widget -->
			<template #widget-count-overdue>
				<CnStatsBlock
					:title="t('procest', 'Overdue')"
					:count="kpis.overdueCount"
					:count-label="t('procest', 'overdue')"
					:icon="AlertCircle"
					:variant="kpis.overdueCount > 0 ? 'error' : 'default'"
					horizontal
					:route="{ name: 'Cases', query: { overdue: 'true' } }" />
			</template>

			<!-- Completed This Month count widget -->
			<template #widget-count-completed>
				<CnStatsBlock
					:title="t('procest', 'Completed This Month')"
					:count="kpis.completedCount"
					:count-label="t('procest', 'completed')"
					:icon="CheckCircle"
					variant="success"
					horizontal
					:route="{ name: 'Cases', query: { status: 'completed' } }" />
			</template>

			<!-- My Tasks count widget -->
			<template #widget-count-my-tasks>
				<CnStatsBlock
					:title="t('procest', 'My Tasks')"
					:count="kpis.taskCount"
					:count-label="t('procest', 'tasks')"
					:icon="ClipboardCheckOutline"
					variant="primary"
					horizontal
					:route="{ name: 'Tasks' }" />
			</template>

			<!-- SLA Compliance count widget -->
			<template #widget-count-sla>
				<CnStatsBlock
					:title="t('procest', 'SLA Compliance')"
					:count="slaComplianceLabel"
					:count-label="slaComplianceSub"
					:icon="ChartTimeline"
					:variant="slaComplianceVariant"
					horizontal
					:route="{ name: 'Doorlooptijd' }" />
			</template>

			<!-- Cases by Status widget -->
			<template #widget-cases-by-status>
				<div class="status-widget-content">
					<div v-if="statusData.length === 0" class="chart-empty">
						{{ t('procest', 'No open cases') }}
					</div>
					<div v-else class="status-chart">
						<div
							v-for="(item, index) in statusData"
							:key="item.name"
							class="status-bar-row">
							<span class="status-bar-label">{{ item.name }}</span>
							<div class="status-bar-track">
								<div
									class="status-bar-fill"
									:style="{ width: barWidth(item.count), background: barColor(index) }" />
							</div>
							<span class="status-bar-count">{{ item.count }}</span>
						</div>
					</div>
				</div>
			</template>

			<!-- My Work widget -->
			<template #widget-my-work>
				<div class="my-work-widget-content">
					<div v-if="myWorkItems.length === 0" class="chart-empty">
						{{ t('procest', 'No items assigned to you') }}
					</div>
					<div v-else class="my-work-list">
						<div
							v-for="item in myWorkItems"
							:key="`${item.type}-${item.id}`"
							class="my-work-item"
							:class="{ 'my-work-item--overdue': item.isOverdue }"
							@click="onWorkItemClick(item.type, item.id)">
							<span class="entity-badge" :class="'badge--' + item.type">
								{{ item.type === 'case' ? 'CASE' : 'TASK' }}
							</span>
							<span class="my-work-title">{{ item.title }}</span>
							<span class="my-work-stage">{{ item.reference }}</span>
							<span v-if="item.daysText" class="my-work-due" :class="{ overdue: item.isOverdue }">
								{{ item.daysText }}
							</span>
						</div>
						<NcButton
							v-if="myWorkItems.length >= 5"
							type="tertiary"
							class="view-all-link"
							@click="$router.push({ name: 'MyWork' })">
							{{ t('procest', 'View all my work') }}
						</NcButton>
					</div>
				</div>
			</template>

			<!-- Case Map widget -->
			<template #widget-case-map>
				<CaseMapWidget />
			</template>

			<!-- Cases by Type widget -->
			<template #widget-cases-by-type>
				<div class="status-widget-content">
					<div v-if="typeData.length === 0" class="chart-empty">
						{{ t('procest', 'No open cases') }}
					</div>
					<div v-else class="status-chart">
						<div
							v-for="(item, index) in typeData"
							:key="item.name"
							class="status-bar-row status-bar-row--clickable"
							@click="$router.push({ name: 'Cases', query: { caseType: item.typeId } })">
							<span class="status-bar-label">{{ item.name }}</span>
							<div class="status-bar-track">
								<div
									class="status-bar-fill"
									:style="{ width: typeBarWidth(item.count), background: barColor(index) }" />
							</div>
							<span class="status-bar-count">{{ item.count }}</span>
						</div>
					</div>
				</div>
			</template>

			<!-- Deadline Alerts widget -->
			<template #widget-deadline-alerts>
				<DeadlineAlerts
					:overdue="deadlineAlerts.overdue"
					:at-risk="deadlineAlerts.atRisk" />
			</template>

			<!-- Task Due Reminders widget -->
			<template #widget-task-due-reminders>
				<TaskDueReminders
					:overdue="taskDueReminders.overdue"
					:due-soon="taskDueReminders.dueSoon" />
			</template>

			<!-- Stalled Cases widget -->
			<template #widget-stalled-cases>
				<StalledCases :stalled-cases="stalledCases" />
			</template>

			<!-- Empty state override with welcome message -->
			<template #empty>
				<div v-if="showEmptyState" class="welcome-message">
					<p v-if="isAdmin">
						{{ t('procest', 'Welcome to Procest! Get started by creating your first case type in Settings.') }}
					</p>
					<p v-else>
						{{ t('procest', 'Welcome to Procest! Get started by creating your first case or task using the buttons above.') }}
					</p>
				</div>
			</template>
		</CnDashboardPage>

		<!-- Error display -->
		<div v-if="error" class="dashboard-error">
			<p>{{ error }}</p>
			<NcButton @click="loadDashboardData">
				{{ t('procest', 'Retry') }}
			</NcButton>
		</div>

		<!-- Case Create Dialog -->
		<CaseCreateDialog
			v-if="showCreateDialog"
			@created="onCaseCreated"
			@close="showCreateDialog = false" />

		<!-- Task Create Dialog -->
		<TaskCreateDialog
			v-if="showTaskDialog"
			@created="onTaskCreated"
			@close="showTaskDialog = false" />
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { CnDashboardPage, CnStatsBlock } from '@conduction/nextcloud-vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import FolderOpen from 'vue-material-design-icons/FolderOpen.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import ClipboardCheckOutline from 'vue-material-design-icons/ClipboardCheckOutline.vue'
import ChartTimeline from 'vue-material-design-icons/ChartTimelineVariant.vue'
import { useObjectStore } from '../store/modules/object.js'
import {
	computeKpis,
	aggregateByStatus,
	aggregateByType,
	getMyWorkItems,
	getDeadlineAlerts,
	getTaskDueReminders,
	getStalledCases,
} from '../utils/dashboardHelpers.js'
import { computeSlaCompliance } from '../utils/doorlooptijdHelpers.js'
import CaseCreateDialog from './cases/CaseCreateDialog.vue'
import TaskCreateDialog from './tasks/TaskCreateDialog.vue'
import DeadlineAlerts from './dashboard/DeadlineAlerts.vue'
import TaskDueReminders from './dashboard/TaskDueReminders.vue'
import StalledCases from './dashboard/StalledCases.vue'

const CaseMapWidget = () => import(/* webpackChunkName: "map" */ './dashboard/CaseMapWidget.vue')

const BAR_COLORS = [
	'var(--color-primary)',
	'var(--color-primary-element-light)',
	'var(--color-warning)',
	'var(--color-success)',
	'var(--color-error)',
	'var(--color-text-maxcontrast)',
]

/**
 * Default dashboard layout — 5 count tiles across the top row,
 * then cases-by-status and my-work share the second row,
 * cases-by-type on the third row, deadline/task/stalled alerts on the fourth row.
 * Grid is 12 columns: tiles use widths 2+3+3+2+2 = 12.
 */
const DEFAULT_LAYOUT = [
	{ id: 1, widgetId: 'count-open-cases', gridX: 0, gridY: 0, gridWidth: 2, gridHeight: 2, showTitle: false },
	{ id: 2, widgetId: 'count-overdue', gridX: 2, gridY: 0, gridWidth: 3, gridHeight: 2, showTitle: false },
	{ id: 3, widgetId: 'count-completed', gridX: 5, gridY: 0, gridWidth: 3, gridHeight: 2, showTitle: false },
	{ id: 4, widgetId: 'count-my-tasks', gridX: 8, gridY: 0, gridWidth: 2, gridHeight: 2, showTitle: false },
	{ id: 5, widgetId: 'count-sla', gridX: 10, gridY: 0, gridWidth: 2, gridHeight: 2, showTitle: false },
	{ id: 6, widgetId: 'cases-by-status', gridX: 0, gridY: 2, gridWidth: 6, gridHeight: 4 },
	{ id: 7, widgetId: 'my-work', gridX: 6, gridY: 2, gridWidth: 6, gridHeight: 4 },
	{ id: 8, widgetId: 'cases-by-type', gridX: 0, gridY: 6, gridWidth: 6, gridHeight: 4 },
	{ id: 9, widgetId: 'deadline-alerts', gridX: 0, gridY: 10, gridWidth: 4, gridHeight: 4 },
	{ id: 10, widgetId: 'task-due-reminders', gridX: 4, gridY: 10, gridWidth: 4, gridHeight: 4 },
	{ id: 11, widgetId: 'stalled-cases', gridX: 8, gridY: 10, gridWidth: 4, gridHeight: 4 },
]

export default {
	name: 'Dashboard',
	components: {
		NcButton,
		CnDashboardPage,
		CnStatsBlock,
		Plus,
		Refresh,
		ChartTimeline,
		CaseCreateDialog,
		TaskCreateDialog,
		CaseMapWidget,
		DeadlineAlerts,
		TaskDueReminders,
		StalledCases,
	},
	emits: ['navigate'],
	data() {
		return {
			// Icon components for CnStatsBlock :icon prop
			FolderOpen,
			AlertCircle,
			CheckCircle,
			ClipboardCheckOutline,
			ChartTimeline,
			slaCompliance: { overallRate: null, withinSla: 0, total: 0 },
			showCreateDialog: false,
			showTaskDialog: false,
			openCases: [],
			completedCases: [],
			myTasks: [],
			caseTypes: [],
			statusTypes: [],
			kpis: { openCount: 0, newToday: 0, overdueCount: 0, completedCount: 0, avgDays: null, taskCount: 0, tasksDueToday: 0 },
			statusData: [],
			typeData: [],
			myWorkItems: [],
			deadlineAlerts: { overdue: [], atRisk: [] },
			taskDueReminders: { overdue: [], dueSoon: [] },
			stalledCases: [],
			globalLoading: false,
			error: null,
			refreshTimer: null,
			dashboardLayout: [...DEFAULT_LAYOUT],
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		isAdmin() {
			return window._oc_isadmin === true
		},
		hasData() {
			return this.openCases.length > 0
				|| this.completedCases.length > 0
				|| this.caseTypes.length > 0
		},
		showEmptyState() {
			return !this.globalLoading
				&& this.openCases.length === 0
				&& this.completedCases.length === 0
				&& this.caseTypes.length === 0
				&& !this.error
		},
		slaComplianceLabel() {
			return this.slaCompliance.overallRate !== null
				? this.slaCompliance.overallRate + '%'
				: '—'
		},
		slaComplianceSub() {
			if (this.slaCompliance.total === 0) return t('procest', 'No SLA targets')
			return `${this.slaCompliance.withinSla}/${this.slaCompliance.total}`
		},
		slaComplianceVariant() {
			if (this.slaCompliance.overallRate === null) return 'default'
			if (this.slaCompliance.overallRate >= 90) return 'success'
			if (this.slaCompliance.overallRate >= 70) return 'warning'
			return 'error'
		},
		widgetDefs() {
			return [
				{ id: 'count-open-cases', title: t('procest', 'Open Cases'), type: 'custom' },
				{ id: 'count-overdue', title: t('procest', 'Overdue'), type: 'custom' },
				{ id: 'count-completed', title: t('procest', 'Completed This Month'), type: 'custom' },
				{ id: 'count-my-tasks', title: t('procest', 'My Tasks'), type: 'custom' },
				{ id: 'count-sla', title: t('procest', 'SLA Compliance'), type: 'custom' },
				{ id: 'cases-by-status', title: t('procest', 'Cases by Status'), type: 'custom' },
				{ id: 'cases-by-type', title: t('procest', 'Cases by Type'), type: 'custom' },
				{ id: 'my-work', title: t('procest', 'My Work'), type: 'custom' },
				{ id: 'case-map', title: t('procest', 'Case Map'), type: 'custom' },
				{ id: 'deadline-alerts', title: t('procest', 'Deadline Alerts'), type: 'custom' },
				{ id: 'task-due-reminders', title: t('procest', 'Task Due Reminders'), type: 'custom' },
				{ id: 'stalled-cases', title: t('procest', 'Stalled Cases'), type: 'custom' },
			]
		},
	},
	async mounted() {
		await this.loadDashboardData()
		this.refreshTimer = setInterval(() => {
			this.loadDashboardData()
		}, 5 * 60 * 1000)
	},
	beforeDestroy() {
		if (this.refreshTimer) {
			clearInterval(this.refreshTimer)
			this.refreshTimer = null
		}
	},
	methods: {
		async loadDashboardData() {
			this.globalLoading = true
			this.error = null

			const currentUser = OC?.currentUser || ''
			const today = new Date()
			const firstOfMonth = new Date(today.getFullYear(), today.getMonth(), 1).toISOString().slice(0, 10)

			try {
				const results = await Promise.allSettled([
					this.objectStore.fetchCollection('case', { _limit: 1000 }),
					this.objectStore.fetchCollection('caseType', { _limit: 100 }),
					this.objectStore.fetchCollection('statusType', { _limit: 500 }),
					this.objectStore.fetchCollection('task', {
						'_filters[assignee]': currentUser,
						_limit: 100,
					}),
				])

				const allCases = results[0].status === 'fulfilled' ? (results[0].value || []) : []
				this.caseTypes = results[1].status === 'fulfilled' ? (results[1].value || []) : []
				this.statusTypes = results[2].status === 'fulfilled' ? (results[2].value || []) : []
				this.myTasks = results[3].status === 'fulfilled' ? (results[3].value || []) : []

				const statusTypeMap = new Map()
				for (const st of this.statusTypes) {
					statusTypeMap.set(st.id, st)
				}

				this.openCases = allCases.filter(c => {
					const st = statusTypeMap.get(c.status)
					return !st?.isFinal
				})
				this.completedCases = allCases.filter(c => {
					const st = statusTypeMap.get(c.status)
					return st?.isFinal && c.endDate && c.endDate.slice(0, 10) >= firstOfMonth
				})

				this.myTasks = this.myTasks.filter(t =>
					t.status === 'available' || t.status === 'active',
				)

				this.kpis = computeKpis(this.openCases, this.completedCases, this.myTasks)
				this.slaCompliance = computeSlaCompliance(this.completedCases, this.caseTypes)
				this.statusData = aggregateByStatus(this.openCases, this.statusTypes)
				this.typeData = aggregateByType(this.openCases, this.caseTypes)

				const myCases = this.openCases.filter(c => c.assignee === currentUser)
				this.myWorkItems = getMyWorkItems(myCases, this.myTasks, 5)

				// Signalering widgets
				this.deadlineAlerts = getDeadlineAlerts(this.openCases, this.caseTypes)
				this.taskDueReminders = getTaskDueReminders(this.myTasks)
				this.stalledCases = getStalledCases(this.openCases, this.caseTypes)
			} catch (err) {
				this.error = err.message || t('procest', 'Failed to load dashboard data')
				console.error('Dashboard fetch error:', err)
			} finally {
				this.globalLoading = false
			}
		},

		onLayoutChange(newLayout) {
			this.dashboardLayout = newLayout
		},

		barWidth(count) {
			const max = Math.max(1, ...this.statusData.map(s => s.count))
			const pct = (count / max) * 100
			return `max(20px, ${pct}%)`
		},

		barColor(index) {
			return BAR_COLORS[index % BAR_COLORS.length]
		},

		/**
		 * Compute bar width percentage for the Cases by Type chart.
		 *
		 * @spec openspec/changes/dashboard/tasks.md#task-1
		 * @param {number} count Number of cases for this type
		 * @return {string} CSS width value
		 */
		typeBarWidth(count) {
			const max = Math.max(1, ...this.typeData.map(t => t.count))
			const pct = (count / max) * 100
			return `max(20px, ${pct}%)`
		},

		onWorkItemClick(type, id) {
			if (type === 'case') {
				this.$router.push({ name: 'CaseDetail', params: { id } })
			} else {
				this.$router.push({ name: 'TaskDetail', params: { id } })
			}
		},

		onCaseCreated(caseId) {
			this.showCreateDialog = false
			this.$router.push({ name: 'CaseDetail', params: { id: caseId } })
		},

		onTaskCreated(taskId) {
			this.showTaskDialog = false
			this.$router.push({ name: 'TaskDetail', params: { id: taskId } })
		},
	},
}
</script>

<style scoped>
/* Status chart widget */
.status-widget-content {
	padding: 12px;
	height: 100%;
}

.chart-empty {
	padding: 24px;
	text-align: center;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}

.status-chart {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.status-bar-row {
	display: flex;
	align-items: center;
	gap: 8px;
}

.status-bar-label {
	width: 110px;
	font-size: 13px;
	text-align: right;
	flex-shrink: 0;
}

.status-bar-track {
	flex: 1;
	height: 22px;
	background: var(--color-background-dark);
	border-radius: 4px;
	overflow: hidden;
}

.status-bar-fill {
	height: 100%;
	border-radius: 4px;
	min-width: 2px;
	transition: width 0.3s ease;
}

.status-bar-count {
	width: 30px;
	font-size: 13px;
	font-weight: 600;
	text-align: right;
	flex-shrink: 0;
}

.status-bar-row--clickable {
	cursor: pointer;
	border-radius: 4px;
}

.status-bar-row--clickable:hover {
	background: var(--color-background-hover);
}

/* My Work widget */
.my-work-widget-content {
	padding: 4px 0;
	height: 100%;
	overflow: auto;
}

.my-work-list {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.my-work-item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 12px;
	cursor: pointer;
}

.my-work-item:hover {
	background: var(--color-background-hover);
}

.my-work-item--overdue {
	background: rgba(233, 50, 45, 0.04);
}

.entity-badge {
	display: inline-block;
	padding: 1px 6px;
	border-radius: 4px;
	font-size: 10px;
	font-weight: 700;
	letter-spacing: 0.5px;
	flex-shrink: 0;
}

.badge--case {
	background: #dbeafe;
	color: #1d4ed8;
	border: 1px solid #93c5fd;
}

.badge--task {
	background: #dcfce7;
	color: #16a34a;
	border: 1px solid #86efac;
}

.my-work-title {
	flex: 1;
	font-size: 13px;
	font-weight: 500;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.my-work-stage {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.my-work-due {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.my-work-due.overdue {
	color: var(--color-error);
	font-weight: 600;
}

.view-all-link {
	margin-top: 4px;
	padding-left: 12px;
}

/* Welcome / empty / error */
.welcome-message {
	text-align: center;
	padding: 40px 20px;
	color: var(--color-text-maxcontrast);
	font-size: 15px;
}

.dashboard-error {
	text-align: center;
	padding: 20px;
	color: var(--color-error);
}

.dashboard-error p {
	margin-bottom: 12px;
}

/* Refresh button spinning animation */
.icon-spinning {
	animation: spin 1s linear infinite;
}

@keyframes spin {
	from { transform: rotate(0deg); }
	to { transform: rotate(360deg); }
}
</style>
