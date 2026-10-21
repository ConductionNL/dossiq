<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The workflow canvas of one case type on a page of its own, opened from
  "View as flow" and "Add a task" on the case type's Phases and tasks tab.
-->
<template>
	<NcAppContent>
		<div class="case-type-flow">
			<router-link
				class="case-type-flow__back"
				:to="{ name: 'CaseTypeDetail', params: { id: caseTypeId } }"
				data-testid="case-type-flow-back">
				{{ t('dossiq', 'Back to the case type') }}
			</router-link>
			<h2 class="case-type-flow__title">
				{{ t('dossiq', 'Flow') }}
			</h2>
			<WorkflowTab :caseTypeId="caseTypeId" />
		</div>
	</NcAppContent>
</template>

<script>
import { NcAppContent } from '@nextcloud/vue'
import WorkflowTab from './tabs/WorkflowTab.vue'

export default {
	name: 'CaseTypeFlowView',
	components: { NcAppContent, WorkflowTab },

	computed: {
		/**
		 * The case type this page is bound to.
		 *
		 * @return {string} The route's id.
		 * @spec openspec/changes/case-type-page-follows-its-boards/specs/case-types/spec.md
		 */
		caseTypeId() {
			return String(this.$route?.params?.id ?? '')
		},
	},
}
</script>

<style scoped>
.case-type-flow {
	padding: 24px;
}

.case-type-flow__back {
	color: var(--color-primary-element);
	text-decoration: underline;
}

.case-type-flow__title {
	margin: 12px 0 16px;
}
</style>
