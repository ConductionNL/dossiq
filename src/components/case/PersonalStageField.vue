<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Your own stage on a case, private to you.

  🔴 IT IS NOT THE CASE'S STATUS, AND THE SCREEN HAS TO SAY SO. Two people
  reading two different statuses off one case is the risk this whole design is
  written against, so the label says "only you can see this" every time rather
  than once in a tooltip. The case's own status keeps its place at the top of
  the page; this sits beside it and never replaces it.

  Nothing here is visible to anybody else: the stage is stored as the reader's
  own preference, so there is no endpoint that could return somebody else's and
  no report that could group by it.

  @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
-->
<template>
	<div class="personal-stage" data-testid="personal-stage">
		<NcTextField
			v-model="stage"
			:label="t('dossiq', 'Your own stage')"
			:helperText="t('dossiq', 'Only you can see this. It does not change the case status.')"
			data-testid="personal-stage-input"
			@blur="save" />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcTextField } from '@nextcloud/vue'
import { fetchPersonalStage, savePersonalStage } from '../../services/personalQueueApi.js'

export default {
	name: 'PersonalStageField',

	components: {
		NcTextField,
	},

	props: {
		/** The case this stage is about. */
		caseId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			stage: '',
		}
	},

	async mounted() {
		this.stage = await fetchPersonalStage(this.caseId)
	},

	methods: {
		t,

		/**
		 * Save the stage.
		 *
		 * @return {Promise<void>} When the write has finished.
		 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
		 */
		async save() {
			this.stage = await savePersonalStage(this.caseId, this.stage)
		},
	},
}
</script>

<style scoped>
.personal-stage {
	margin-block: 8px;
}
</style>
