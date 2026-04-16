<template>
	<NcDialog
		:name="t('procest', 'Create Sub-case')"
		:open="open"
		@update:open="$emit('update:open', $event)"
		@close="$emit('update:open', false)">
		<template #default>
			<div class="create-deelzaak-dialog">
				<!-- Case type selector — limited to configured subCaseTypes -->
				<div class="create-deelzaak-dialog__field">
					<label for="deelzaak-type-select">
						{{ t('procest', 'Sub-case type') }} *
					</label>
					<NcSelect
						id="deelzaak-type-select"
						v-model="selectedCaseType"
						:options="allowedCaseTypeOptions"
						:placeholder="t('procest', 'Select a sub-case type')"
						label="label"
						track-by="value" />
					<p v-if="allowedCaseTypeOptions.length === 0" class="create-deelzaak-dialog__hint">
						{{ t('procest', 'No sub-case types configured for this case type') }}
					</p>
				</div>

				<!-- Optional title override -->
				<div class="create-deelzaak-dialog__field">
					<label for="deelzaak-title">
						{{ t('procest', 'Title (optional)') }}
					</label>
					<NcInputField
						id="deelzaak-title"
						v-model="title"
						:placeholder="t('procest', 'Leave empty to use case type name')" />
				</div>

				<!-- Optional assignee -->
				<div class="create-deelzaak-dialog__field">
					<label for="deelzaak-assignee">
						{{ t('procest', 'Assignee (optional)') }}
					</label>
					<NcInputField
						id="deelzaak-assignee"
						v-model="assignee"
						:placeholder="t('procest', 'Nextcloud username')" />
				</div>

				<!-- Error display -->
				<div v-if="errorMessage" class="create-deelzaak-dialog__error">
					{{ errorMessage }}
				</div>
			</div>
		</template>

		<template #actions>
			<NcButton @click="$emit('update:open', false)">
				{{ t('procest', 'Cancel') }}
			</NcButton>
			<NcButton
				type="primary"
				:disabled="!selectedCaseType || saving"
				@click="handleCreate">
				<template v-if="saving">
					{{ t('procest', 'Creating…') }}
				</template>
				<template v-else>
					{{ t('procest', 'Create sub-case') }}
				</template>
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcInputField, NcSelect } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Dialog for manually creating a deelzaak under a parent case.
 *
 * Only offers the caseTypes configured in parentCaseType.subCaseTypes.
 * Emits 'created' with the new deelzaak object on success.
 *
 * @spec openspec/changes/deelzaak-support/tasks.md#T05
 */
export default {
	name: 'CreateDeelzaakDialog',

	components: {
		NcButton,
		NcDialog,
		NcInputField,
		NcSelect,
	},

	props: {
		/** Whether the dialog is open. */
		open: {
			type: Boolean,
			required: true,
		},
		/** UUID of the parent case. */
		caseId: {
			type: String,
			required: true,
		},
		/** Allowed subCaseTypes (array of caseType objects from the parent caseType). */
		allowedCaseTypes: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['update:open', 'created'],

	data() {
		return {
			selectedCaseType: null,
			title: '',
			assignee: '',
			saving: false,
			errorMessage: '',
		}
	},

	computed: {
		/**
		 * Build NcSelect option list from the allowed caseTypes.
		 *
		 * @spec openspec/changes/deelzaak-support/tasks.md#T05
		 */
		allowedCaseTypeOptions() {
			return (this.allowedCaseTypes || []).map(ct => ({
				value: ct.id,
				label: ct.title || ct.id,
			}))
		},
	},

	methods: {
		/**
		 * Submit the deelzaak creation request.
		 *
		 * @spec openspec/changes/deelzaak-support/tasks.md#T05
		 */
		async handleCreate() {
			if (!this.selectedCaseType) return

			this.saving = true
			this.errorMessage = ''

			try {
				const url = generateUrl(`/apps/procest/api/procest/deelzaak/${this.caseId}`)
				const payload = {
					caseTypeId: this.selectedCaseType.value,
					title: this.title.trim() || undefined,
					assignee: this.assignee.trim() || undefined,
				}

				const response = await axios.post(url, payload)

				this.$emit('created', response.data)
				this.$emit('update:open', false)
				this.resetForm()
			} catch (err) {
				this.errorMessage = err?.response?.data?.error
					|| t('procest', 'Failed to create sub-case')
			} finally {
				this.saving = false
			}
		},

		/**
		 * Reset form fields to initial state.
		 *
		 * @spec openspec/changes/deelzaak-support/tasks.md#T05
		 */
		resetForm() {
			this.selectedCaseType = null
			this.title = ''
			this.assignee = ''
			this.errorMessage = ''
		},
	},
}
</script>

<style scoped>
.create-deelzaak-dialog {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 8px 0;
}

.create-deelzaak-dialog__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.create-deelzaak-dialog__field label {
	font-weight: 500;
	font-size: 13px;
}

.create-deelzaak-dialog__hint {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	margin: 0;
}

.create-deelzaak-dialog__error {
	color: var(--color-error);
	font-size: 13px;
	padding: 8px;
	background: var(--color-error-bg, #ffeaea);
	border-radius: var(--border-radius);
}
</style>
