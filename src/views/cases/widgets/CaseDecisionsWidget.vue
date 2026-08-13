<template>
	<div class="case-decisions-widget">
		<div v-if="decisions.length === 0" class="decisions-empty">
			{{ t('procest', 'No decisions recorded') }}
		</div>
		<div v-else class="decisions-list">
			<div
				v-for="decision in decisions"
				:key="decision.id"
				class="decision-row">
				<div class="decision-icon">
					<span
						:class="
							decision.outcome === 'approved'
								? 'icon--approved'
								: 'icon--default'
						">
						{{
							decision.outcome === 'approved' ? '&#10003;' : '&#9679;'
						}}
					</span>
				</div>
				<div class="decision-info">
					<span class="decision-title">{{
						decision.title || decision.name || '---'
					}}</span>
					<span v-if="decision.outcome" class="decision-outcome">
						{{ decision.outcome }}
					</span>
					<span v-if="decision.date" class="decision-date">
						{{ formatDate(decision.date) }}
					</span>
				</div>
			</div>
		</div>

		<!-- Result section for final-status cases -->
		<ResultSection
			v-if="caseResult"
			:result="caseResult"
			:result-types="resultTypes" />
	</div>
</template>

<script>
import { formatDate } from '../../../utils/caseHelpers.js'
import ResultSection from '../components/ResultSection.vue'

export default {
	name: 'CaseDecisionsWidget',
	components: {
		ResultSection,
	},
	props: {
		caseId: {
			type: String,
			default: null,
		},
		decisions: {
			type: Array,
			default: () => [],
		},
		caseResult: {
			type: Object,
			default: null,
		},
		resultTypes: {
			type: Array,
			default: () => [],
		},
	},
	methods: {
		formatDate,
	},
}
</script>

<style scoped>
.case-decisions-widget {
	padding: 12px;
	height: 100%;
	overflow: auto;
}

.decisions-empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
	padding: 24px;
}

.decisions-list {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.decision-row {
	display: flex;
	align-items: flex-start;
	gap: 10px;
	padding: 8px;
	border-radius: var(--border-radius);
}

.decision-row:hover {
	background: var(--color-background-hover);
}

.decision-icon {
	width: 24px;
	height: 24px;
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
	font-size: 14px;
}

.icon--approved {
	color: var(--color-success);
	font-weight: bold;
}

.icon--default {
	color: var(--color-text-maxcontrast);
}

.decision-info {
	flex: 1;
	min-width: 0;
}

.decision-title {
	display: block;
	font-size: 13px;
	font-weight: 500;
}

.decision-outcome {
	display: inline-block;
	padding: 1px 6px;
	border-radius: var(--border-radius-pill);
	font-size: 11px;
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	margin-top: 2px;
}

.decision-date {
	display: block;
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	margin-top: 2px;
}
</style>
