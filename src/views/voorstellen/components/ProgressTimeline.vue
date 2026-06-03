<template>
	<div class="progress-timeline" role="list" :aria-label="t('procest', 'Parafering voortgang')">
		<div
			v-for="step in steps"
			:key="step.order"
			class="progress-timeline__step"
			:class="stepClass(step)"
			role="listitem">
			<div class="progress-timeline__indicator">
				<CheckCircle v-if="isCompleted(step)" :size="24" />
				<ProgressClock v-else-if="isCurrent(step)" :size="24" />
				<CircleOutline v-else :size="24" />
			</div>
			<div class="progress-timeline__content">
				<div class="progress-timeline__label">
					{{ step.label || step.actor || t('procest', 'Stap {n}', { n: step.order }) }}
				</div>
				<div class="progress-timeline__type">
					{{ formatStepType(step.type) }}
				</div>
				<div v-if="isCompleted(step)" class="progress-timeline__meta">
					{{ getCompletionInfo(step) }}
				</div>
				<div v-else-if="isCurrent(step)" class="progress-timeline__meta progress-timeline__meta--waiting">
					{{ t('procest', 'Wachtend') }}
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import ProgressClock from 'vue-material-design-icons/ProgressClock.vue'
import CircleOutline from 'vue-material-design-icons/CircleOutline.vue'

const STEP_TYPE_LABELS = {
	advies: 'Advies',
	parafering: 'Parafering',
	accordering: 'Accordering',
}

export default {
	name: 'ProgressTimeline',
	components: {
		CheckCircle,
		ProgressClock,
		CircleOutline,
	},
	props: {
		steps: {
			type: Array,
			required: true,
		},
		currentStep: {
			type: Number,
			default: 0,
		},
		acties: {
			type: Array,
			default: () => [],
		},
	},
	methods: {
		isCompleted(step) {
			return step.order < this.currentStep
		},
		isCurrent(step) {
			return step.order === this.currentStep
		},
		/**
		 * @param step
		 * @spec openspec/specs/parafering-actions/spec.md
		 */
		stepClass(step) {
			if (this.isCompleted(step)) return 'progress-timeline__step--completed'
			if (this.isCurrent(step)) return 'progress-timeline__step--current'
			return 'progress-timeline__step--pending'
		},
		/**
		 * @param type
		 * @spec openspec/specs/parafering-actions/spec.md
		 */
		formatStepType(type) {
			return STEP_TYPE_LABELS[type] || type || ''
		},
		/**
		 * @param step
		 * @spec openspec/specs/parafering-actions/spec.md
		 */
		getCompletionInfo(step) {
			const actie = this.acties.find(a => a.step === step.order)
			if (!actie) return ''
			const date = actie._self?.created || actie.timestamp
			const formatted = date ? new Date(date).toLocaleDateString('nl-NL') : ''
			const action = actie.action === 'parafered'
				? t('procest', 'Geparafeerd')
				: actie.action === 'advised'
					? t('procest', 'Geadviseerd')
					: actie.action === 'skipped'
						? t('procest', 'Overgeslagen')
						: actie.action
			return `${action} door ${actie.actor} — ${formatted}`
		},
	},
}
</script>

<style scoped>
.progress-timeline {
	display: flex;
	flex-direction: column;
	gap: 0;
	position: relative;
}

.progress-timeline__step {
	display: flex;
	gap: 12px;
	padding: 8px 0;
	position: relative;
}

.progress-timeline__step::before {
	content: '';
	position: absolute;
	left: 11px;
	top: 32px;
	bottom: -8px;
	width: 2px;
	background: var(--color-border);
}

.progress-timeline__step:last-child::before {
	display: none;
}

.progress-timeline__step--completed .progress-timeline__indicator {
	color: var(--color-success, #2e7d32);
}

.progress-timeline__step--completed::before {
	background: var(--color-success, #2e7d32);
}

.progress-timeline__step--current .progress-timeline__indicator {
	color: var(--color-primary-element);
}

.progress-timeline__step--pending .progress-timeline__indicator {
	color: var(--color-text-maxcontrast);
}

.progress-timeline__indicator {
	flex-shrink: 0;
	z-index: 1;
	background: var(--color-main-background);
}

.progress-timeline__content {
	flex: 1;
}

.progress-timeline__label {
	font-weight: 600;
}

.progress-timeline__type {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.progress-timeline__meta {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
	margin-top: 2px;
}

.progress-timeline__meta--waiting {
	color: var(--color-primary-element);
	font-style: italic;
}
</style>
