<template>
	<div class="case-popup">
		<h4 class="case-popup__title">
			{{ caseData.title || t('procest', 'Untitled case') }}
		</h4>
		<p v-if="caseData.identifier" class="case-popup__id">
			{{ caseData.identifier }}
		</p>
		<div class="case-popup__meta">
			<span v-if="caseData.status" class="case-popup__status" :class="statusClass">
				{{ caseData.statusName || caseData.status }}
			</span>
			<span v-if="caseData.caseTypeName" class="case-popup__type">
				{{ caseData.caseTypeName }}
			</span>
		</div>
		<p v-if="caseData.assignee" class="case-popup__assignee">
			{{ t('procest', 'Handler') }}: {{ caseData.assignee }}
		</p>
		<router-link
			:to="{ name: 'CaseDetail', params: { id: caseData.id } }"
			class="case-popup__link">
			{{ t('procest', 'View case') }}
		</router-link>
	</div>
</template>

<script>
export default {
	name: 'CasePopup',
	props: {
		caseData: {
			type: Object,
			required: true,
		},
	},
	computed: {
		statusClass() {
			const cat = this.caseData.statusCategory || ''
			return `case-popup__status--${cat}`
		},
	},
}
</script>

<style scoped>
.case-popup {
	min-width: 200px;
	padding: 4px;
}

.case-popup__title {
	margin: 0 0 4px;
	font-size: 14px;
	font-weight: 600;
}

.case-popup__id {
	margin: 0 0 4px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.case-popup__meta {
	display: flex;
	gap: 8px;
	margin-bottom: 4px;
}

.case-popup__status {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 500;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
}

.case-popup__status--closed {
	background: #e8f5e9;
	color: #2e7d32;
}

.case-popup__status--overdue {
	background: #ffebee;
	color: #c62828;
}

.case-popup__status--nearDeadline {
	background: #fff3e0;
	color: #e65100;
}

.case-popup__type {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.case-popup__assignee {
	margin: 2px 0;
	font-size: 12px;
}

.case-popup__link {
	display: inline-block;
	margin-top: 4px;
	font-size: 13px;
	color: var(--color-primary-element);
	text-decoration: none;
}

.case-popup__link:hover {
	text-decoration: underline;
}
</style>
