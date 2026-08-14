<template>
	<section class="deadline-panel" :class="severityClass">
		<header class="deadline-panel__header">
			<h3>{{ t('procest', 'Deadline') }}</h3>
			<span v-if="warnedAt" class="deadline-panel__warned">
				{{ t('procest', 'Warned at') }} {{ warnedAt }}
			</span>
		</header>

		<p class="deadline-panel__date">
			{{ t('procest', 'Closing date') }}: <strong>{{ deadline }}</strong>
		</p>

		<p class="deadline-panel__remaining">
			<template v-if="daysRemaining > 0">
				{{
					n(
						'procest',
						'%n working day remaining',
						'%n working days remaining',
						daysRemaining,
					)
				}}
			</template>
			<template v-else-if="daysRemaining === 0">
				{{ t('procest', 'Deadline is today!') }}
			</template>
			<template v-else>
				{{
					n(
						'procest',
						'%n working day overdue',
						'%n working days overdue',
						Math.abs(daysRemaining),
					)
				}}
			</template>
		</p>
	</section>
</template>

<script>
/**
 * Compact deadline panel for the complaint detail (Awb working-day math).
 *
 * @spec openspec/changes/complaint-management/tasks.md#task-cm-07
 */
export default {
	name: 'DeadlinePanel',

	props: {
		deadline: {
			type: String,
			required: true,
		},

		warnedAt: {
			type: String,
			default: '',
		},
	},

	computed: {
		/**
		 * Working days remaining to the deadline. Simple weekday math
		 * (server enforces the real Awb working-day algorithm + Dutch holidays).
		 *
		 * @return {number} Days remaining.
		 *
		 * @spec openspec/changes/complaint-management/tasks.md#task-cm-07
		 */
		daysRemaining() {
			const today = new Date()
			const target = new Date(this.deadline)
			if (Number.isNaN(target.getTime())) {
				return 0
			}
			let days = 0
			const cur = new Date(today)
			cur.setHours(0, 0, 0, 0)
			target.setHours(0, 0, 0, 0)
			const step = cur <= target ? 1 : -1
			while (cur.getTime() !== target.getTime()) {
				cur.setDate(cur.getDate() + step)
				const d = cur.getDay()
				if (d !== 0 && d !== 6) {
					days += step
				}
			}
			return days
		},

		/**
		 * CSS class for severity colour.
		 *
		 * @return {string} Class.
		 *
		 * @spec openspec/changes/complaint-management/tasks.md#task-cm-07
		 */
		severityClass() {
			if (this.daysRemaining < 0) {
				return 'deadline-panel--overdue'
			}
			if (this.daysRemaining <= 3) {
				return 'deadline-panel--warning'
			}
			return 'deadline-panel--ok'
		},
	},
}
</script>

<style scoped>
.deadline-panel {
	padding: calc(var(--default-grid-baseline, 8px) * 2);
	border-radius: var(--border-radius);
	margin: var(--default-grid-baseline, 8px) 0;
}

.deadline-panel--ok {
	background: var(--color-success-color-low, #e8f5e9);
}

.deadline-panel--warning {
	background: var(--color-warning-color-low, #fff8e1);
}

.deadline-panel--overdue {
	background: var(--color-error-color-low, #ffebee);
}

.deadline-panel__header {
	display: flex;
	justify-content: space-between;
	align-items: baseline;
}

.deadline-panel__warned {
	color: var(--color-text-maxcontrast);
	font-size: 0.875rem;
}
</style>
