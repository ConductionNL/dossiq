<!--
  SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
  @spec openspec/changes/dso-omgevingsloket/tasks.md#T08
-->
<template>
	<NcModal :name="t('procest', 'Generate beschikking')" @close="$emit('close')">
		<div class="beschikking-dialog">
			<p>{{ t('procest', 'Generate a beschikking PDF document for this omgevingsvergunning.') }}</p>

			<div class="form-group">
				<label>{{ t('procest', 'Outcome') }} *</label>
				<NcSelect
					v-model="outcome"
					:options="outcomeOptions"
					:placeholder="t('procest', 'Select outcome...')"
					:input-label="t('procest', 'Outcome')"
					label="label"
					track-by="value" />
			</div>

			<div v-if="outcome" class="form-group">
				<label>{{ t('procest', 'Template preview') }}</label>
				<div class="beschikking-template-preview">
					<span class="beschikking-template-name">
						{{ outcome.value === 'verleend'
							? t('procest', 'Template: Vergunning verleend')
							: t('procest', 'Template: Vergunning geweigerd') }}
					</span>
				</div>
			</div>

			<div class="form-group">
				<label>{{ t('procest', 'Motivation') }} *</label>
				<NcTextArea
					v-model="motivation"
					:label="t('procest', 'Decision motivation')"
					:placeholder="motivationPlaceholder"
					:rows="6" />
			</div>

			<p v-if="error" class="form-error">
				{{ error }}
			</p>

			<div class="beschikking-dialog__actions">
				<NcButton :disabled="!isValid || submitting" @click="submit">
					<template v-if="submitting">
						<NcLoadingIcon :size="16" />
						{{ t('procest', 'Generating...') }}
					</template>
					<template v-else>
						{{ t('procest', 'Generate beschikking') }}
					</template>
				</NcButton>
				<NcButton type="secondary" :disabled="submitting" @click="$emit('close')">
					{{ t('procest', 'Cancel') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import NcTextArea from '@nextcloud/vue/dist/Components/NcTextArea.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'

export default {
	name: 'BeschikkingDialog',
	components: { NcModal, NcButton, NcSelect, NcTextArea, NcLoadingIcon },
	props: {
		caseId: {
			type: String,
			required: true,
		},
	},
	emits: ['close', 'submitted'],
	data() {
		return {
			outcome: null,
			motivation: '',
			submitting: false,
			error: null,
			outcomeOptions: [
				{ label: t('procest', 'Verleend (granted)'), value: 'verleend' },
				{ label: t('procest', 'Geweigerd (refused)'), value: 'geweigerd' },
			],
		}
	},
	computed: {
		isValid() {
			return this.outcome && this.motivation.trim().length > 0
		},
		motivationPlaceholder() {
			if (!this.outcome) return t('procest', 'Select an outcome first...')
			if (this.outcome.value === 'verleend') {
				return t('procest', 'De aanvraag voldoet aan alle vereisten van het omgevingsplan. De vergunning wordt verleend onder de volgende voorschriften...')
			}
			return t('procest', 'De aanvraag is geweigerd wegens strijd met het omgevingsplan, artikel...')
		},
	},
	methods: {
		t,
		async submit() {
			if (!this.isValid) return
			this.submitting = true
			this.error = null
			try {
				const url = generateUrl('/apps/procest/api/dso/cases/' + encodeURIComponent(this.caseId) + '/beschikking')
				await axios.post(url, {
					outcome: this.outcome.value,
					motivation: this.motivation,
				})
				this.$emit('submitted')
			} catch (err) {
				this.error = err?.response?.data?.message || err.message
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped>
.beschikking-dialog {
	padding: 20px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}
.form-group {
	display: flex;
	flex-direction: column;
	gap: 4px;
}
.form-error {
	color: var(--color-error);
}
.beschikking-template-preview {
	padding: 8px 12px;
	background: var(--color-background-dark);
	border-radius: 4px;
	border: 1px solid var(--color-border);
}
.beschikking-template-name {
	font-style: italic;
}
.beschikking-dialog__actions {
	display: flex;
	gap: 8px;
}
</style>
