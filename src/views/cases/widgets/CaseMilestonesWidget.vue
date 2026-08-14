<template>
	<div class="case-milestones-widget">
		<!-- Deadline panel reuse -->
		<DeadlinePanel
			v-if="caseTypeData"
			:start-date="caseData.startDate"
			:deadline="caseData.deadline"
			:processing-deadline="caseTypeData.processingDeadline"
			:extension-allowed="
				caseTypeData.extensionAllowed === true
				|| caseTypeData.extensionAllowed === 'true'
			"
			:extension-period="caseTypeData.extensionPeriod"
			:extension-count="caseData.extensionCount || 0"
			:is-final="isFinal"
			@extend="$emit('extend')" />

		<!-- Milestones list -->
		<div v-if="milestones.length > 0" class="milestones-list">
			<div
				v-for="milestone in milestones"
				:key="milestone.id"
				class="milestone-row"
				:class="{ 'milestone-row--completed': milestone.completed }">
				<span class="milestone-check">
					{{ milestone.completed ? '&#10003;' : '&#9675;' }}
				</span>
				<div class="milestone-info">
					<span class="milestone-name">{{
						milestone.title || milestone.name || '---'
					}}</span>
					<span v-if="milestone.dueDate" class="milestone-date">
						{{ formatDate(milestone.dueDate) }}
					</span>
				</div>
			</div>
		</div>

		<div v-else-if="!caseTypeData" class="milestones-empty">
			{{ t('procest', 'No deadline information available') }}
		</div>
	</div>
</template>

<script>
import { formatDate } from '../../../utils/caseHelpers.js'
import DeadlinePanel from '../components/DeadlinePanel.vue'

export default {
	name: 'CaseMilestonesWidget',
	components: {
		DeadlinePanel,
	},
	props: {
		caseData: {
			type: Object,
			default: () => ({}),
		},
		caseTypeData: {
			type: Object,
			default: null,
		},
		milestones: {
			type: Array,
			default: () => [],
		},
		isFinal: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['extend'],
	methods: {
		formatDate,
	},
}
</script>

<style scoped>
.case-milestones-widget {
	padding: 12px;
	height: 100%;
	overflow: auto;
}

/* Reset DeadlinePanel internal margins for widget context */
.case-milestones-widget :deep(.deadline-panel) {
	margin-bottom: 12px;
}

.milestones-list {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.milestone-row {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 8px;
	border-radius: var(--border-radius);
}

.milestone-row:hover {
	background: var(--color-background-hover);
}

.milestone-row--completed {
	opacity: 0.7;
}

.milestone-check {
	font-size: 16px;
	flex-shrink: 0;
	width: 20px;
	text-align: center;
}

.milestone-row--completed .milestone-check {
	color: var(--color-success);
}

.milestone-info {
	flex: 1;
	min-width: 0;
}

.milestone-name {
	display: block;
	font-size: 13px;
	font-weight: 500;
}

.milestone-row--completed .milestone-name {
	text-decoration: line-through;
}

.milestone-date {
	display: block;
	font-size: 11px;
	color: var(--color-text-maxcontrast);
}

.milestones-empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
	padding: 24px;
}
</style>
