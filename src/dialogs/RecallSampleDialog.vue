<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Draw a recall sample from the documents a review set aside or has not marked.

  Two fields: how many, and who judges them. The reviewer defaults to you,
  as a user id typed in (the app's convention for naming a colleague); the
  server refuses a size larger than the null set and says why. The sample is
  drawn on the server with a stored seed, so the same documents can be drawn
  again; nothing about the draw happens here.

  @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-the-recall-is-estimated-from-an-elusion-sample-with-its-uncertainty-req-wrs-002
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Draw a sample')"
		data-testid="recall-sample-dialog"
		@closing="$emit('close')">
		<div class="recall-sample">
			<p>
				{{
					t(
						'dossiq',
						'The sample is drawn from the {count} documents that are not relevant or not marked yet. Judging a sampled document does not change its marking.',
						{ count: nullSetSize },
					)
				}}
			</p>
			<NcTextField
				v-model="size"
				data-testid="recall-sample-size"
				type="number"
				:label="t('dossiq', 'Sample size')" />
			<NcTextField
				v-model="assignee"
				data-testid="recall-sample-assignee"
				:label="t('dossiq', 'User id of the reviewer')" />
			<p
				v-if="error"
				class="recall-sample__error"
				role="alert"
				data-testid="recall-sample-error">
				{{ error }}
			</p>
		</div>
		<template #actions>
			<NcButton data-testid="recall-sample-cancel" @click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="saving"
				data-testid="recall-sample-draw"
				@click="draw">
				{{ t('dossiq', 'Draw the sample') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'

export default {
	name: 'RecallSampleDialog',

	components: { NcButton, NcDialog, NcTextField },

	props: {
		/** The case uuid. */
		caseId: { type: String, required: true },
		/** How many documents are not relevant or unmarked. */
		nullSetSize: { type: Number, default: 0 },
	},

	emits: ['close', 'drawn'],

	data() {
		return {
			size: String(Math.min(200, this.nullSetSize)),
			assignee: getCurrentUser()?.uid ?? '',
			saving: false,
			error: '',
		}
	},

	methods: {
		t,

		/**
		 * Ask the server to draw the sample.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-the-recall-is-estimated-from-an-elusion-sample-with-its-uncertainty-req-wrs-002
		 */
		async draw() {
			this.saving = true
			this.error = ''
			try {
				const { data } = await axios.post(
					generateUrl(
						`/apps/dossiq/api/cases/${encodeURIComponent(this.caseId)}/review/recall/samples`,
					),
					{ size: Number(this.size), assignee: this.assignee.trim() },
				)
				this.$emit('drawn', data)
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| t(
						'dossiq',
						'The Recall sample cannot be stored, so nothing was drawn or judged.',
					)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.recall-sample {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.recall-sample__error {
	color: var(--color-error-text);
}
</style>
