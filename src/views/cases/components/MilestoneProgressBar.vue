<template>
	<div
		class="milestone-bar"
		:title="t('procest', '{reached} of {total} milestones reached', { reached, total })">
		<div class="milestone-bar__track">
			<div
				class="milestone-bar__fill"
				:class="fillClass"
				:style="{ width: percentage + '%' }" />
		</div>
		<span class="milestone-bar__label">{{ reached }}/{{ total }}</span>
	</div>
</template>

<script>
export default {
	name: 'MilestoneProgressBar',
	props: {
		reached: {
			type: Number,
			default: 0,
		},
		total: {
			type: Number,
			default: 0,
		},
		percentage: {
			type: Number,
			default: 0,
		},
	},
	computed: {
		/** @spec openspec/changes/retrofit-2026-05-24-milestone-tracking/tasks.md */
		fillClass() {
			if (this.percentage === 100) return 'milestone-bar__fill--complete'
			if (this.percentage >= 50) return 'milestone-bar__fill--progress'
			return 'milestone-bar__fill--start'
		},
	},
}
</script>

<style scoped>
.milestone-bar {
	display: flex;
	align-items: center;
	gap: 8px;
	min-width: 100px;
}

.milestone-bar__track {
	flex: 1;
	height: 6px;
	background: var(--color-background-dark);
	border-radius: 3px;
	overflow: hidden;
}

.milestone-bar__fill {
	height: 100%;
	border-radius: 3px;
	transition: width 0.3s ease;
}

.milestone-bar__fill--start {
	background: var(--color-warning, #e65100);
}

.milestone-bar__fill--progress {
	background: var(--color-primary-element);
}

.milestone-bar__fill--complete {
	background: var(--color-success, #2e7d32);
}

.milestone-bar__label {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}
</style>
