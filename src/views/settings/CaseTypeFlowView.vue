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
			<p v-if="subtitle" class="case-type-flow__subtitle" data-testid="case-type-flow-subtitle">
				{{ subtitle }}
			</p>
			<WorkflowTab v-if="storesReady" :caseTypeId="caseTypeId" />
			<NcLoadingIcon v-else data-testid="case-type-flow-loading" />
		</div>
	</NcAppContent>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcAppContent, NcLoadingIcon } from '@nextcloud/vue'
import WorkflowTab from './tabs/WorkflowTab.vue'
import { useObjectStore } from '../../store/modules/object.js'
import { useWorkflowStore } from '../../store/modules/workflow.js'
import { initializeStores } from '../../store/store.js'

export default {
	name: 'CaseTypeFlowView',
	components: { NcAppContent, NcLoadingIcon, WorkflowTab },

	data() {
		return { storesReady: false, caseTypeName: '' }
	},

	computed: {
		/**
		 * The line under the title: the case type and the version on screen
		 * ("Omgevingsvergunning regulier · versie 5 concept", DqZaaktypeFlow).
		 *
		 * @return {string} The subtitle, or '' before the case type is known.
		 * @spec openspec/changes/case-type-flow-board-look/specs/case-types/spec.md#requirement-the-case-type-flow-page-draws-board-dqzaaktypeflow
		 */
		subtitle() {
			const template = useWorkflowStore().currentTemplate
			if (!this.caseTypeName) {
				return ''
			}
			if (!template) {
				return this.caseTypeName
			}
			return template.isDraft
				? t('dossiq', '{name} · version {version} draft', { name: this.caseTypeName, version: template.version })
				: t('dossiq', '{name} · version {version}', { name: this.caseTypeName, version: template.version })
		},

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

	watch: {
		caseTypeId(id) {
			this.loadCaseTypeName(id)
		},
	},

	/**
	 * Start the object stores before the workflow reads its versions: opened
	 * directly, the page mounts before the app has registered the workflow
	 * template type. The case type's name for the subtitle is read after.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/case-type-flow-direct-load/specs/case-types/spec.md#requirement-the-case-type-flow-page-loads-its-workflow-when-opened-directly
	 */
	async created() {
		try {
			await initializeStores()
		} finally {
			this.storesReady = true
		}
		await this.loadCaseTypeName(this.caseTypeId)
	},

	methods: {
		/**
		 * Read the case type's name for the subtitle.
		 *
		 * @param {string} id The case type id.
		 * @return {Promise<void>}
		 * @spec openspec/changes/case-type-flow-board-look/specs/case-types/spec.md#requirement-the-case-type-flow-page-draws-board-dqzaaktypeflow
		 */
		async loadCaseTypeName(id) {
			this.caseTypeName = ''
			if (!id) {
				return
			}
			try {
				const caseType = await useObjectStore().fetchObject('caseType', id)
				this.caseTypeName = String(caseType?.title || caseType?.name || '')
			} catch {
				this.caseTypeName = ''
			}
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
	margin: 12px 0 4px;
	font-size: 28px;
	font-weight: 700;
}

.case-type-flow__subtitle {
	margin: 0 0 20px;
	color: var(--color-text-maxcontrast);
	font-size: 15px;
}
</style>
