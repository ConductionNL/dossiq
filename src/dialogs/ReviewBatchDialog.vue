<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Make a review batch: a name, a reviewer, and the documents it holds.

  The documents offered are the open ones of the case, all ticked; untick what
  belongs elsewhere. Or take them by custodian, source system or the rule that
  marked them: the server then selects every document of the case whose
  provenance or review matches all the filled-in fields. The server refuses a
  document that is already in another open batch and names that batch, and
  gives the reviewer a task for the batch.

  @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-batches-are-assigned-to-named-reviewers-before-any-verdict-req-wrt-003
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Make a batch')"
		data-testid="review-batch-dialog"
		@closing="$emit('close')">
		<div class="review-batch">
			<NcTextField
				v-model="name"
				data-testid="review-batch-name"
				:label="t('dossiq', 'Name of the batch')" />
			<NcTextField
				v-model="assignee"
				data-testid="review-batch-assignee"
				:label="t('dossiq', 'User id of the reviewer')" />
			<fieldset class="review-batch__filter">
				<legend>{{ t('dossiq', 'Take the documents by') }}</legend>
				<NcTextField
					v-for="field in filterFields"
					:key="field.key"
					v-model="filter[field.key]"
					:data-testid="'review-batch-filter-' + field.key"
					:label="field.label" />
			</fieldset>
			<fieldset v-if="!byFilter" class="review-batch__documents">
				<legend>{{ t('dossiq', 'Documents') }}</legend>
				<NcCheckboxRadioSwitch
					v-for="ref in documents"
					:key="ref"
					v-model="chosen"
					:value="ref"
					type="checkbox">
					{{ ref }}
				</NcCheckboxRadioSwitch>
			</fieldset>
			<p v-if="error" class="review-batch__error" role="alert" data-testid="review-batch-error">
				{{ error }}
			</p>
		</div>
		<template #actions>
			<NcButton data-testid="review-batch-cancel" @click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="saving || (!byFilter && chosen.length === 0)" data-testid="review-batch-create" @click="create">
				{{ t('dossiq', 'Make the batch') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'

export default {
	name: 'ReviewBatchDialog',

	components: { NcButton, NcCheckboxRadioSwitch, NcDialog, NcTextField },

	props: {
		/** The case uuid. */
		caseId: { type: String, required: true },
		/** The documents that can go in the batch. */
		documents: { type: Array, default: () => [] },
	},

	emits: ['close', 'created'],

	data() {
		return {
			name: '',
			assignee: '',
			chosen: [...this.documents],
			filter: { custodian: '', sourceSystem: '', rule: '' },
			saving: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The fields a batch can be taken by.
		 *
		 * @return {Array<object>} Key and label per field.
		 */
		filterFields() {
			return [
				{ key: 'custodian', label: t('dossiq', 'Custodian') },
				{ key: 'sourceSystem', label: t('dossiq', 'Source system') },
				{ key: 'rule', label: t('dossiq', 'The rule that marked them') },
			]
		},

		/**
		 * The filled-in filter fields.
		 *
		 * @return {object} The filter to send.
		 */
		filled() {
			return Object.fromEntries(
				Object.entries(this.filter)
					.map(([key, value]) => [key, String(value ?? '').trim()])
					.filter(([, value]) => value !== ''),
			)
		},

		/**
		 * Whether the batch is taken by a filter instead of the ticked documents.
		 *
		 * @return {boolean} True when a filter field is filled in.
		 */
		byFilter() {
			return Object.keys(this.filled).length > 0
		},
	},

	methods: {
		t,

		/**
		 * Ask the server to make the batch.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-batches-are-assigned-to-named-reviewers-before-any-verdict-req-wrt-003
		 */
		async create() {
			this.saving = true
			this.error = ''
			try {
				const { data } = await axios.post(
					generateUrl(`/apps/dossiq/api/cases/${encodeURIComponent(this.caseId)}/review/batches`),
					this.byFilter
						? { name: this.name.trim(), assignee: this.assignee.trim(), filter: this.filled }
						: { name: this.name.trim(), assignee: this.assignee.trim(), documents: this.chosen },
				)
				this.$emit('created', data)
			} catch (e) {
				const taken = e?.response?.data?.taken
				const message = e?.response?.data?.message || t('dossiq', 'The Review batch cannot be stored, so nothing was assigned.')
				this.error = taken && typeof taken === 'object'
					? message + ' ' + Object.entries(taken).map(([ref, batch]) => `${ref}: ${batch}`).join(', ')
					: message
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.review-batch {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.review-batch__filter {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.review-batch__documents {
	max-height: 40vh;
	overflow-y: auto;
}

.review-batch__error {
	color: var(--color-error-text);
}
</style>
