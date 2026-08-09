<template>
	<div class="task-reminders">
		<template v-if="overdue.length === 0 && dueSoon.length === 0">
			<div class="task-reminders__empty">
				<span class="task-reminders__check">&#10003;</span>
				<p>{{ t('procest', 'No task reminders') }}</p>
			</div>
		</template>

		<template v-else>
			<div class="task-reminders__list">
				<!-- Overdue tasks -->
				<div
					v-for="item in overdue"
					:key="'overdue-' + item.id"
					class="task-reminders__row task-reminders__row--overdue"
					role="button"
					tabindex="0"
					@click="$router.push({ name: 'TaskDetail', params: { id: item.id } })"
					@keydown.enter="$router.push({ name: 'TaskDetail', params: { id: item.id } })"
					@keydown.space.prevent="$router.push({ name: 'TaskDetail', params: { id: item.id } })">
					<div class="task-reminders__info">
						<span class="task-reminders__title">{{ item.title }}</span>
						<span class="task-reminders__reference">{{ item.caseReference }}</span>
					</div>
					<div class="task-reminders__meta">
						<span class="task-reminders__days task-reminders__days--overdue">
							{{ t('procest', '{days} days overdue', { days: item.daysOverdue }) }}
						</span>
						<span class="task-reminders__priority"
							:class="'priority--' + item.priority">
							{{ item.priority }}
						</span>
					</div>
				</div>

				<!-- Due soon tasks -->
				<div
					v-for="item in dueSoon"
					:key="'soon-' + item.id"
					class="task-reminders__row task-reminders__row--warning"
					role="button"
					tabindex="0"
					@click="$router.push({ name: 'TaskDetail', params: { id: item.id } })"
					@keydown.enter="$router.push({ name: 'TaskDetail', params: { id: item.id } })"
					@keydown.space.prevent="$router.push({ name: 'TaskDetail', params: { id: item.id } })">
					<div class="task-reminders__info">
						<span class="task-reminders__title">{{ item.title }}</span>
						<span class="task-reminders__reference">{{ item.caseReference }}</span>
					</div>
					<div class="task-reminders__meta">
						<span class="task-reminders__days task-reminders__days--warning">
							<template v-if="item.daysRemaining === 0">
								{{ t('procest', 'Due today') }}
							</template>
							<template v-else>
								{{ t('procest', '{days} days remaining', { days: item.daysRemaining }) }}
							</template>
						</span>
						<span class="task-reminders__priority"
							:class="'priority--' + item.priority">
							{{ item.priority }}
						</span>
					</div>
				</div>
			</div>
		</template>
	</div>
</template>

<script>
export default {
	name: 'TaskDueReminders',
	props: {
		overdue: { type: Array, default: () => [] },
		dueSoon: { type: Array, default: () => [] },
	},
}
</script>

<style scoped>
.task-reminders {
	padding: 4px 0;
}

.task-reminders__list {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.task-reminders__row {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	padding: 10px 12px;
	margin-bottom: 4px;
	border-radius: var(--border-radius);
	cursor: pointer;
	transition: background 0.15s ease;
}

.task-reminders__row:hover {
	background: var(--color-background-hover);
}

.task-reminders__row--overdue {
	border-left: 3px solid var(--color-error);
	background: rgba(var(--color-error-rgb, 229, 57, 53), 0.05);
}

.task-reminders__row--warning {
	border-left: 3px solid var(--color-warning);
	background: rgba(var(--color-warning-rgb, 255, 152, 0), 0.05);
}

.task-reminders__info {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
	flex: 1;
}

.task-reminders__title {
	font-size: 13px;
	font-weight: 600;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.task-reminders__reference {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.task-reminders__meta {
	display: flex;
	flex-direction: column;
	align-items: flex-end;
	gap: 2px;
	flex-shrink: 0;
	margin-left: 12px;
}

.task-reminders__days {
	font-size: 13px;
	font-weight: 600;
	white-space: nowrap;
}

.task-reminders__days--overdue {
	color: var(--color-error);
}

.task-reminders__days--warning {
	color: var(--color-warning-text);
}

.task-reminders__priority {
	font-size: 11px;
	padding: 1px 6px;
	border-radius: 8px;
	text-transform: capitalize;
}

.priority--urgent,
.priority--high {
	background: rgba(var(--color-error-rgb, 229, 57, 53), 0.1);
	color: var(--color-error);
}

.priority--normal {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.priority--low {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.task-reminders__empty {
	text-align: center;
	padding: 20px;
	color: var(--color-success);
}

.task-reminders__check {
	font-size: 24px;
	display: block;
	margin-bottom: 4px;
}

@media (prefers-reduced-motion: reduce) {
	.task-reminders__row {
		transition: none;
	}
}
</style>
