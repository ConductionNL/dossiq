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
			<WorkflowTab v-if="storesReady" :caseTypeId="caseTypeId" boardHeader />
			<NcLoadingIcon v-else data-testid="case-type-flow-loading" />
		</div>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcLoadingIcon } from '@nextcloud/vue'
import WorkflowTab from './tabs/WorkflowTab.vue'
import { caseTypeWidget } from '../../mixins/caseTypeWidget.js'
import { initializeStores } from '../../store/store.js'

export default {
	name: 'CaseTypeFlowView',
	components: { NcAppContent, NcLoadingIcon, WorkflowTab },

	mixins: [caseTypeWidget],

	data() {
		return { storesReady: false }
	},

	/**
	 * Start the object stores before the workflow reads its versions: opened
	 * directly, the page mounts before the app has registered the workflow
	 * template type.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/specs/case-types/spec.md#requirement-the-case-type-flow-page-loads-its-workflow-when-opened-directly
	 */
	async created() {
		try {
			await initializeStores()
		} finally {
			this.storesReady = true
		}
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

.case-type-flow__back + * {
	margin-top: 12px;
}
</style>
