<template>
	<div>
		<!-- Filters bar -->
		<div class="task-list-filters">
			<div class="task-list-filters__search">
				<NcTextField
					:value="searchQuery"
					:placeholder="t('procest', 'Search tasks...')"
					@update:value="v => searchQuery = v" />
			</div>
			<div class="task-list-filters__dropdowns">
				<NcSelect
					v-model="filterStatus"
					:options="statusFilterOptions"
					:placeholder="t('procest', 'Status')"
					:clearable="true"
					@input="onFilterChange" />
				<NcSelect
					v-model="filterPriority"
					:options="priorityFilterOptions"
					:placeholder="t('procest', 'Priority')"
					:clearable="true"
					@input="onFilterChange" />
				<NcSelect
					v-model="filterAssignee"
					:options="assigneeFilterOptions"
					:placeholder="t('procest', 'Assignee')"
					:clearable="true"
					@input="onFilterChange" />
			</div>
		</div>

		<CnIndexPage
			:title="t('procest', 'Tasks')"
			:description="t('procest', 'Track and manage tasks')"
			:schema="schema"
			:objects="filteredObjects"
			:pagination="pagination"
			:loading="loading"
			:sort-key="sortKey"
			:sort-order="sortOrder"
			:row-class="getRowClass"
			:selectable="true"
			:include-columns="visibleColumns"
			@refresh="refresh"
			@sort="onSort"
			@row-click="openTask"
			@page-changed="onPageChange">
		<template #column-case="{ row }">
			<a
				v-if="row.case"
				class="case-link"
				@click.stop="openCase(row.case)">
				{{ getCaseTitle(row.case) }}
			</a>
			<span v-else>&mdash;</span>
		</template>

		<template #column-status="{ row }">
			<span class="status-badge" :class="'status-badge--' + row.status">
				{{ getStatusLabel(row.status) }}
			</span>
		</template>

		<template #column-dueDate="{ row }">
			<span :class="dueDateClass(row)">
				<template v-if="isOverdue(row)">
					{{ getOverdueText(row) }}
				</template>
				<template v-else-if="isDueToday(row)">
					{{ t('procest', 'Due today') }}
				</template>
				<template v-else>
					{{ formatDueDate(row.dueDate) }}
				</template>
			</span>
		</template>

		<template #column-priority="{ row }">
			<span
				v-if="row.priority && row.priority !== 'normal'"
				class="priority-badge"
				:class="'priority-badge--' + row.priority">
				{{ getPriorityLabel(row.priority) }}
			</span>
			<span v-else>&mdash;</span>
		</template>
		</CnIndexPage>
	</div>
</template>

<script>
import { inject } from 'vue'
import { NcTextField, NcSelect } from '@nextcloud/vue'
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'
import { getStatusLabel, TASK_STATUSES } from '../../utils/taskLifecycle.js'
import { isOverdue, isDueToday, getOverdueText, formatDueDate, getPriorityLevels } from '../../utils/taskHelpers.js'

export default {
	name: 'TaskList',
	components: {
		CnIndexPage,
		NcTextField,
		NcSelect,
	},

	setup() {
		const sidebarState = inject('sidebarState', null)
		const objectStore = useObjectStore()
		return useListView('task', {
			sidebarState,
			objectStore,
			defaultSort: { key: 'dueDate', order: 'asc' },
		})
	},

	data() {
		return {
			caseCache: {},
			searchQuery: '',
			filterStatus: null,
			filterPriority: null,
			filterAssignee: null,
		}
	},

	computed: {
		statusFilterOptions() {
			return Object.keys(TASK_STATUSES).map(s => ({
				id: s,
				label: getStatusLabel(s),
			}))
		},
		priorityFilterOptions() {
			return [
				{ id: 'urgent', label: t('procest', 'Urgent') },
				{ id: 'high', label: t('procest', 'High') },
				{ id: 'normal', label: t('procest', 'Normal') },
				{ id: 'low', label: t('procest', 'Low') },
			]
		},
		assigneeFilterOptions() {
			const assignees = new Set()
			if (this.objects) {
				for (const obj of this.objects) {
					if (obj.assignee) assignees.add(obj.assignee)
				}
			}
			return Array.from(assignees).map(a => ({ id: a, label: a }))
		},
		filteredObjects() {
			let result = this.objects || []

			if (this.searchQuery && this.searchQuery.trim()) {
				const query = this.searchQuery.trim().toLowerCase()
				result = result.filter(obj => {
					const title = (obj.title || '').toLowerCase()
					const desc = (obj.description || '').toLowerCase()
					return title.includes(query) || desc.includes(query)
				})
			}

			if (this.filterStatus) {
				const statusId = this.filterStatus.id || this.filterStatus
				result = result.filter(obj => obj.status === statusId)
			}

			if (this.filterPriority) {
				const priorityId = this.filterPriority.id || this.filterPriority
				result = result.filter(obj => obj.priority === priorityId)
			}

			if (this.filterAssignee) {
				const assigneeId = this.filterAssignee.id || this.filterAssignee
				result = result.filter(obj => obj.assignee === assigneeId)
			}

			return result
		},
	},

	methods: {
		isOverdue,
		isDueToday,
		getOverdueText,
		formatDueDate,
		getStatusLabel,

		getPriorityLabel(priority) {
			return getPriorityLevels()[priority]?.label || priority
		},

		dueDateClass(task) {
			if (isOverdue(task)) return 'due-date--overdue'
			if (isDueToday(task)) return 'due-date--today'
			return ''
		},

		getRowClass(row) {
			return isOverdue(row) ? 'row--overdue' : ''
		},

		getCaseTitle(caseId) {
			const cached = this.caseCache[caseId]
			if (cached) return cached.title || cached.identifier || caseId
			this.loadCaseTitle(caseId)
			return caseId
		},

		async loadCaseTitle(caseId) {
			if (this.caseCache[caseId] !== undefined) return
			this.caseCache[caseId] = null
			const objectStore = useObjectStore()
			const caseObj = await objectStore.fetchObject('case', caseId)
			if (caseObj) {
				this.$set(this.caseCache, caseId, caseObj)
			}
		},

		openTask(row) {
			this.$router.push({ name: 'TaskDetail', params: { id: row.id } })
		},

		openCase(caseId) {
			this.$router.push({ name: 'CaseDetail', params: { id: caseId } })
		},

		onFilterChange() {
			// Filtering is reactive via computed property
		},
	},
}
</script>

<style scoped>
.task-list-filters {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 16px;
	flex-wrap: wrap;
}

.task-list-filters__search {
	flex: 1;
	min-width: 200px;
}

.task-list-filters__dropdowns {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}

.task-list-filters__dropdowns .v-select {
	min-width: 130px;
}

.case-link {
	color: var(--color-primary);
	text-decoration: underline;
	cursor: pointer;
}

.case-link:hover {
	color: var(--color-primary-hover);
}

.status-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 12px;
	font-weight: 500;
}

.status-badge--available {
	background: var(--color-background-dark);
	color: var(--color-main-text);
}

.status-badge--active {
	background: var(--color-primary-light);
	color: var(--color-primary-text);
}

.status-badge--completed {
	background: var(--color-success);
	color: white;
}

.status-badge--terminated {
	background: var(--color-error);
	color: white;
}

.status-badge--disabled {
	background: var(--color-text-maxcontrast);
	color: white;
}

.priority-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 12px;
	font-weight: 500;
}

.priority-badge--urgent {
	background: var(--color-error);
	color: white;
}

.priority-badge--high {
	background: var(--color-warning);
	color: var(--color-warning-text);
}

.priority-badge--low {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.due-date--overdue {
	color: var(--color-error);
	font-weight: 500;
}

.due-date--today {
	color: var(--color-warning);
	font-weight: 500;
}
</style>

<style scoped>
/* rowClass applies to CnDataTable's <tr> elements */
:deep(.row--overdue) {
	border-left: 3px solid var(--color-error);
}
</style>
