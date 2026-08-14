<template>
	<div class="case-roles-widget">
		<ParticipantsSection
			:caseId="caseId"
			:isReadOnly="isReadOnly"
			@handlerChanged="$emit('handler-changed', $event)" />
	</div>
</template>

<script>
import ParticipantsSection from '../components/ParticipantsSection.vue'

export default {
	name: 'CaseRolesWidget',
	components: {
		ParticipantsSection,
	},

	props: {
		caseId: {
			type: String,
			required: true,
		},

		isReadOnly: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['handler-changed'],
}
</script>

<style scoped>
.case-roles-widget {
	padding: 12px;
	height: 100%;
	overflow: auto;
}

/* Reset the ParticipantsSection top border/margin since it is in its own widget now */
.case-roles-widget :deep(.participants-section) {
	margin-top: 0;
	border-top: none;
	padding-top: 0;
}
</style>
