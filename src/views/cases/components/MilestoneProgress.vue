<template>
	<div class="milestone-progress">
		<h4 class="milestone-progress__title">
			{{ t('procest', 'Milestones') }}
			<span class="milestone-progress__count">{{ reached }}/{{ total }}</span>
		</h4>

		<!-- Step indicator -->
		<div class="milestone-progress__steps">
			<div
				v-for="(milestone, index) in milestones"
				:key="milestone.identifier || index"
				class="milestone-progress__step"
				:class="stepClass(milestone, index)"
				:title="milestone.description || milestone.label">
				<!-- Line connector -->
				<div
					v-if="index > 0"
					class="milestone-progress__line"
					:class="{ 'milestone-progress__line--reached': milestone.reached }" />

				<!-- Dot -->
				<div
					class="milestone-progress__dot"
					:class="dotClass(milestone, index)"
					@click="!isReadOnly && onDotClick(milestone, index)">
					<span v-if="milestone.reached" class="milestone-progress__check">&#10003;</span>
					<span v-else class="milestone-progress__number">{{ index + 1 }}</span>
				</div>

				<!-- Label -->
				<div class="milestone-progress__label">
					<span class="milestone-progress__name">{{ milestone.label }}</span>
					<span v-if="milestone.reachedAt" class="milestone-progress__date">
						{{ formatDate(milestone.reachedAt) }}
					</span>
				</div>
			</div>
		</div>

		<!-- Progress bar -->
		<div class="milestone-progress__bar-container">
			<div class="milestone-progress__bar" :style="{ width: percentage + '%' }" />
		</div>
		<span class="milestone-progress__percentage">{{ percentage }}%</span>
	</div>
</template>

<script>
export default {
	name: 'MilestoneProgress',
	props: {
		milestones: {
			type: Array,
			default: () => [],
		},
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
		isReadOnly: {
			type: Boolean,
			default: false,
		},
	},
	methods: {
		stepClass(milestone, index) {
			return {
				'milestone-progress__step--reached': milestone.reached,
				'milestone-progress__step--current': !milestone.reached
					&& index > 0
					&& this.milestones[index - 1]?.reached,
				'milestone-progress__step--future': !milestone.reached
					&& (index === 0 || !this.milestones[index - 1]?.reached),
			}
		},
		dotClass(milestone, index) {
			return {
				'milestone-progress__dot--reached': milestone.reached,
				'milestone-progress__dot--current': !milestone.reached
					&& index > 0
					&& this.milestones[index - 1]?.reached,
				'milestone-progress__dot--future': !milestone.reached
					&& (index === 0 || !this.milestones[index - 1]?.reached),
			}
		},
		formatDate(dateStr) {
			if (!dateStr) return ''
			const date = new Date(dateStr)
			if (isNaN(date.getTime())) return dateStr
			return date.toLocaleDateString('nl-NL', { day: 'numeric', month: 'short' })
		},
		onDotClick(milestone, index) {
			if (milestone.reached) {
				this.$emit('reverse', { milestone, index })
			} else {
				this.$emit('mark', { milestone, index })
			}
		},
	},
}
</script>

<style scoped>
.milestone-progress__title {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 16px;
}

.milestone-progress__count {
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.milestone-progress__steps {
	display: flex;
	justify-content: space-between;
	position: relative;
	margin-bottom: 16px;
}

.milestone-progress__step {
	display: flex;
	flex-direction: column;
	align-items: center;
	flex: 1;
	position: relative;
}

.milestone-progress__line {
	position: absolute;
	top: 14px;
	right: 50%;
	left: -50%;
	height: 2px;
	background: var(--color-border);
}

.milestone-progress__line--reached {
	background: var(--color-success, #2e7d32);
}

.milestone-progress__dot {
	width: 28px;
	height: 28px;
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 1;
	font-size: 0.75rem;
	font-weight: 600;
	cursor: default;
}

.milestone-progress__dot--reached {
	background: var(--color-success, #2e7d32);
	color: white;
}

.milestone-progress__dot--current {
	background: var(--color-primary-element);
	color: white;
	cursor: pointer;
}

.milestone-progress__dot--future {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	border: 2px solid var(--color-border);
}

.milestone-progress__check {
	font-size: 0.875rem;
}

.milestone-progress__label {
	text-align: center;
	margin-top: 6px;
}

.milestone-progress__name {
	display: block;
	font-size: 0.75rem;
	line-height: 1.2;
}

.milestone-progress__date {
	display: block;
	font-size: 0.6875rem;
	color: var(--color-text-maxcontrast);
}

.milestone-progress__bar-container {
	height: 6px;
	background: var(--color-background-dark);
	border-radius: 3px;
	overflow: hidden;
	margin-bottom: 4px;
}

.milestone-progress__bar {
	height: 100%;
	background: var(--color-success, #2e7d32);
	border-radius: 3px;
	transition: width 0.3s ease;
}

.milestone-progress__percentage {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
}
</style>
