<template>
	<div class="deadline-indicator" :class="indicatorClass">
		<div class="deadline-indicator__icon">
			<span v-if="status.isSuspended">&#x23F8;</span>
			<span v-else-if="status.isOverdue">&#x26A0;</span>
			<span v-else-if="status.isAtRisk">&#x23F0;</span>
			<span v-else>&#x2713;</span>
		</div>
		<div class="deadline-indicator__content">
			<span class="deadline-indicator__label">{{ label }}</span>
			<span class="deadline-indicator__value">{{ status.label }}</span>
			<span class="deadline-indicator__date"
				>{{ t('procest', 'Deadline:') }} {{ deadline }}</span
			>
		</div>
	</div>
</template>

<script>
import { useBezwaarStore } from '../../../../store/modules/bezwaar.js'

export default {
	name: 'DeadlineIndicator',
	props: {
		deadline: {
			type: String,
			required: true,
		},
		label: {
			type: String,
			default: '',
		},
		isSuspended: {
			type: Boolean,
			default: false,
		},
	},
	computed: {
		/** @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md */
		status() {
			const bezwaarStore = useBezwaarStore()
			return bezwaarStore.getDeadlineStatus(this.deadline, this.isSuspended)
		},
		/** @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md */
		indicatorClass() {
			if (this.status.isSuspended) return 'deadline-indicator--suspended'
			if (this.status.isOverdue) return 'deadline-indicator--overdue'
			if (this.status.isAtRisk) return 'deadline-indicator--at-risk'
			return 'deadline-indicator--on-track'
		},
	},
}
</script>

<style scoped>
.deadline-indicator {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 8px 12px;
	border-radius: var(--border-radius-large);
	border-left: 4px solid;
}

.deadline-indicator--on-track {
	border-color: var(--color-success);
	background: var(--color-success-hover);
}

.deadline-indicator--at-risk {
	border-color: var(--color-warning);
	background: var(--color-warning-hover);
}

.deadline-indicator--overdue {
	border-color: var(--color-error);
	background: var(--color-error-hover);
}

.deadline-indicator--suspended {
	border-color: var(--color-text-maxcontrast);
	background: var(--color-background-dark);
}

.deadline-indicator__icon {
	font-size: 20px;
}

.deadline-indicator__content {
	display: flex;
	flex-direction: column;
}

.deadline-indicator__label {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.deadline-indicator__value {
	font-weight: bold;
}

.deadline-indicator__date {
	font-size: 12px;
}
</style>
