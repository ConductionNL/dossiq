<template>
	<div class="case-tasks-widget">
		<div class="tasks-header">
			<span class="tasks-count">
				{{ completedCount }}/{{ tasks.length }} {{ t('procest', 'completed') }}
			</span>
			<NcButton v-if="!isReadOnly" @click="$router.push({ name: 'TaskNew', query: { caseId } })">
				{{ t('procest', 'New task') }}
			</NcButton>
		</div>

		<div v-if="tasks.length === 0" class="tasks-empty">
			{{ t('procest', 'No tasks yet') }}
		</div>

		<div v-else class="tasks-list">
			<div
				v-for="task in sortedTasks"
				:key="task.id"
				class="task-row"
				:class="{ 'task-row--overdue': isOverdue(task) }"
				role="button"
				tabindex="0"
				@click="$router.push({ name: 'TaskDetail', params: { id: task.id } })"
				@keydown.enter="$router.push({ name: 'TaskDetail', params: { id: task.id } })"
				@keydown.space.prevent="$router.push({ name: 'TaskDetail', params: { id: task.id } })">
				<span class="task-status-dot" :class="'task-status-dot--' + task.status" />
				<span class="task-title">{{ task.title || '---' }}</span>
				<span class="task-assignee">{{ task.assignee || '' }}</span>
				<span class="task-due" :class="dueDateClass(task)">
					<template v-if="isOverdue(task)">{{ getOverdueText(task) }}</template>
					<template v-else-if="isDueToday(task)">{{ t('procest', 'Today') }}</template>
					<template v-else>{{ formatDueDate(task.dueDate) }}</template>
				</span>
				<span
					v-if="task.priority && task.priority !== 'normal'"
					class="priority-badge"
					:class="'priority-badge--' + task.priority">
					{{ task.priority }}
				</span>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { isOverdue, isDueToday, getOverdueText, formatDueDate, sortTasks } from '../../../utils/taskHelpers.js'

export default {
	name: 'CaseTasksWidget',
	components: {
		NcButton,
	},
	props: {
		caseId: {
			type: String,
			default: null,
		},
		tasks: {
			type: Array,
			default: () => [],
		},
		isReadOnly: {
			type: Boolean,
			default: false,
		},
	},
	computed: {
		/** @spec openspec/changes/retrofit-2026-05-24-signalering-widgets/tasks.md */
		sortedTasks() {
			return sortTasks(this.tasks)
		},
		/** @spec openspec/changes/retrofit-2026-05-24-signalering-widgets/tasks.md */
		completedCount() {
			return this.tasks.filter(t => t.status === 'completed').length
		},
	},
	methods: {
		isOverdue,
		isDueToday,
		getOverdueText,
		formatDueDate,
		/**
		 * @param task
		 * @spec openspec/changes/retrofit-2026-05-24-signalering-widgets/tasks.md
		 */
		dueDateClass(task) {
			if (isOverdue(task)) return 'task-due--overdue'
			if (isDueToday(task)) return 'task-due--today'
			return ''
		},
	},
}
</script>

<style scoped>
.case-tasks-widget {
	padding: 12px;
	height: 100%;
	overflow: auto;
}

.tasks-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 8px;
}

.tasks-count {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	font-weight: 500;
}

.tasks-empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
	padding: 24px;
}

.tasks-list {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.task-row {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px;
	border-radius: var(--border-radius);
	cursor: pointer;
}

.task-row:hover {
	background: var(--color-background-hover);
}

.task-row--overdue {
	border-left: 3px solid var(--color-error);
}

.task-status-dot {
	width: 10px;
	height: 10px;
	border-radius: 50%;
	flex-shrink: 0;
	background: var(--color-text-maxcontrast);
}

.task-status-dot--completed {
	background: var(--color-success);
}

.task-status-dot--active {
	background: var(--color-primary);
}

.task-status-dot--available {
	background: var(--color-warning);
}

.task-title {
	flex: 1;
	font-size: 13px;
	font-weight: 500;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.task-assignee {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.task-due {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.task-due--overdue {
	color: var(--color-error);
	font-weight: 600;
}

.task-due--today {
	color: var(--color-warning);
	font-weight: 600;
}

.priority-badge {
	display: inline-block;
	padding: 1px 6px;
	border-radius: var(--border-radius-pill);
	font-size: 10px;
	font-weight: 600;
	flex-shrink: 0;
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
</style>
