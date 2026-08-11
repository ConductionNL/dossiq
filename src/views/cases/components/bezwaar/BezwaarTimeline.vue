<template>
	<div class="bezwaar-timeline">
		<h4>{{ t('procest', 'Bezwaar Timeline') }}</h4>

		<div class="bezwaar-timeline__items">
			<div
				v-for="item in timelineItems"
				:key="item.id"
				class="timeline-item"
				:class="{ 'timeline-item--active': item.active, 'timeline-item--completed': item.completed, 'timeline-item--deadline': item.isDeadline }">
				<div class="timeline-item__marker" />
				<div class="timeline-item__content">
					<span class="timeline-item__date">{{ item.date }}</span>
					<span class="timeline-item__label">{{ item.label }}</span>
					<span v-if="item.detail" class="timeline-item__detail">{{ item.detail }}</span>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { useBezwaarStore } from '../../../../store/modules/bezwaar.js'

export default {
	name: 'BezwaarTimeline',
	props: {
		caseData: {
			type: Object,
			default: () => ({}),
		},
		deadlines: {
			type: Object,
			default: () => ({}),
		},
	},
	computed: {
		/** @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md */
		timelineItems() {
			const items = []
			const bezwaarStore = useBezwaarStore()

			// Received date.
			if (bezwaarStore.currentObjection?.receivedDate) {
				items.push({
					id: 'received',
					date: bezwaarStore.currentObjection.receivedDate,
					label: t('procest', 'Bezwaarschrift received'),
					completed: true,
					active: false,
					isDeadline: false,
				})
			}

			// Acknowledgment deadline.
			if (this.deadlines?.ontvangstbevestigingDeadline) {
				items.push({
					id: 'ack-deadline',
					date: this.deadlines.ontvangstbevestigingDeadline,
					label: t('procest', 'Acknowledgment deadline'),
					completed: false,
					active: false,
					isDeadline: true,
				})
			}

			// Hearing date.
			const hearing = bezwaarStore.activeHearing
			if (hearing?.scheduledDate) {
				items.push({
					id: 'hearing',
					date: hearing.scheduledDate.split('T')[0],
					label: t('procest', 'Hearing scheduled'),
					detail: hearing.status === 'uitgevoerd' ? t('procest', 'Completed') : t('procest', hearing.status),
					completed: hearing.status === 'uitgevoerd',
					active: hearing.status === 'gepland' || hearing.status === 'uitgenodigd',
					isDeadline: false,
				})
			}

			// Advisory report date.
			if (bezwaarStore.currentAdvisoryReport?.adviceDate) {
				items.push({
					id: 'advisory',
					date: bezwaarStore.currentAdvisoryReport.adviceDate,
					label: t('procest', 'Advisory report issued'),
					detail: this.getAdviceTypeLabel(bezwaarStore.currentAdvisoryReport.adviceType),
					completed: true,
					active: false,
					isDeadline: false,
				})
			}

			// Decision date.
			if (bezwaarStore.currentAppealDecision?.decisionDate) {
				items.push({
					id: 'decision',
					date: bezwaarStore.currentAppealDecision.decisionDate,
					label: t('procest', 'Decision on objection'),
					detail: this.getDispositionLabel(bezwaarStore.currentAppealDecision.dispositionType),
					completed: true,
					active: false,
					isDeadline: false,
				})
			}

			// Processing deadline.
			if (this.deadlines?.afhandelDeadline) {
				items.push({
					id: 'processing-deadline',
					date: this.deadlines.afhandelDeadline,
					label: t('procest', 'Processing deadline'),
					completed: false,
					active: false,
					isDeadline: true,
				})
			}

			// Sort by date.
			items.sort((a, b) => a.date.localeCompare(b.date))

			return items
		},
	},
	methods: {
		/**
		 * @param type
		 * @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md
		 */
		getAdviceTypeLabel(type) {
			const labels = {
				gegrond: t('procest', 'Upheld'),
				ongegrond: t('procest', 'Rejected'),
				deels_gegrond: t('procest', 'Partially upheld'),
				niet_ontvankelijk: t('procest', 'Inadmissible'),
			}
			return labels[type] || type
		},
		/**
		 * @param type
		 * @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md
		 */
		getDispositionLabel(type) {
			const labels = {
				gegrond: t('procest', 'Upheld'),
				ongegrond: t('procest', 'Rejected'),
				deels_gegrond: t('procest', 'Partially upheld'),
				niet_ontvankelijk: t('procest', 'Inadmissible'),
			}
			return labels[type] || type
		},
	},
}
</script>

<style scoped>
.bezwaar-timeline {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.bezwaar-timeline__items {
	display: flex;
	flex-direction: column;
	gap: 0;
	padding-left: 16px;
	border-left: 2px solid var(--color-border-dark);
}

.timeline-item {
	display: flex;
	align-items: flex-start;
	gap: 12px;
	padding: 8px 0;
	position: relative;
}

.timeline-item__marker {
	width: 12px;
	height: 12px;
	border-radius: 50%;
	background: var(--color-border-dark);
	flex-shrink: 0;
	margin-left: -23px;
	margin-top: 4px;
}

.timeline-item--completed .timeline-item__marker {
	background: var(--color-success);
}

.timeline-item--active .timeline-item__marker {
	background: var(--color-primary);
}

.timeline-item--deadline .timeline-item__marker {
	background: var(--color-warning);
	border: 2px solid var(--color-warning-text);
}

.timeline-item__content {
	display: flex;
	flex-direction: column;
}

.timeline-item__date {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.timeline-item__label {
	font-weight: 500;
}

.timeline-item__detail {
	font-size: 13px;
	color: var(--color-text-lighter);
}
</style>
