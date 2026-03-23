<template>
	<div class="case-notes-widget">
		<ActivityTimeline
			:activity="activity"
			:is-read-only="isReadOnly"
			@add-note="$emit('add-note', $event)" />
	</div>
</template>

<script>
import ActivityTimeline from '../components/ActivityTimeline.vue'

export default {
	name: 'CaseNotesWidget',
	components: {
		ActivityTimeline,
	},
	props: {
		activity: {
			type: Array,
			default: () => [],
		},
		isReadOnly: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['add-note'],
}
</script>

<style scoped>
.case-notes-widget {
	padding: 12px;
	height: 100%;
	overflow: auto;
}

/* Reset internal margins for widget context */
.case-notes-widget :deep(.activity-timeline) {
	margin-top: 0;
}

.case-notes-widget :deep(.activity-timeline__title) {
	display: none;
}
</style>
