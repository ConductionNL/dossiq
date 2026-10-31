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
			<WorkflowTab v-if="storesReady" :caseTypeId="caseTypeId" />
			<NcLoadingIcon v-else data-testid="case-type-flow-loading" />
		</div>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcLoadingIcon } from '@nextcloud/vue'
import WorkflowTab from './tabs/WorkflowTab.vue'
import { initializeStores } from '../../store/store.js'

export default {
	name: 'CaseTypeFlowView',
	components: { NcAppContent, NcLoadingIcon, WorkflowTab },

	data() {
		return { storesReady: false }
	},

	computed: {
		/**
		 * The case type this page is bound to.
		 *
		 * @return {string} The route's id.
		 * @spec openspec/specs/case-types/spec.md
		 */
		caseTypeId() {
			return String(this.$route?.params?.id ?? '')
		},
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

.case-type-flow__title {
	margin: 12px 0 16px;
}
</style>
