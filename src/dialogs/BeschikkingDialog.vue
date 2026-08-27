<!--
 SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Generate Beschikking')"
		:canClose="true"
		@close="$emit('close')">
		<template #default>
			<div class="beschikking-dialog">
				<p class="beschikking-dialog__intro">
					{{
						t(
							'dossiq',
							'Generate a beslissing document (beschikking) using the configured Docudesk template.',
						)
					}}
				</p>

				<div class="beschikking-dialog__outcome-selector">
					<label class="beschikking-dialog__label">{{
						t('dossiq', 'Outcome')
					}}</label>
					<div class="beschikking-dialog__outcome-buttons">
						<NcButton
							:type="outcome === 'granted' ? 'primary' : 'secondary'"
							@click="outcome = 'granted'">
							✓ {{ t('dossiq', 'Granted') }}
						</NcButton>
						<NcButton
							:type="outcome === 'refused' ? 'error' : 'secondary'"
							@click="outcome = 'refused'">
							✗ {{ t('dossiq', 'Refused') }}
						</NcButton>
					</div>
				</div>

				<NcTextArea
					v-model="motivation"
					:label="t('dossiq', 'Motivation')"
					:placeholder="motivationPlaceholder"
					rows="6" />

				<div v-if="error" class="beschikking-dialog__error">
					{{ error }}
				</div>

				<div v-if="success" class="beschikking-dialog__success">
					{{
						t('dossiq', 'Beschikking generated and attached as bijlage.')
					}}
				</div>
			</div>
		</template>

		<template #actions>
			<NcButton type="tertiary" @click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				type="primary"
				:disabled="!outcome || submitting || success"
				@click="submit">
				{{ t('dossiq', 'Generate') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'

export default {
	name: 'BeschikkingDialog',
	components: { NcButton, NcDialog, NcTextArea },
	props: {
		caseId: {
			type: String,
			required: true,
		},
	},

	emits: ['close', 'generated'],
	data() {
		return {
			outcome: null,
			motivation: '',
			submitting: false,
			error: null,
			success: false,
		}
	},

	computed: {
		/**
		 * Placeholder text for the motivation field, per chosen outcome.
		 *
		 * @return {string} The translated placeholder.
		 *
		 * @spec openspec/specs/dso-omgevingsloket/spec.md#requirement-req-dso-008-dso-status-lifecycle-for-vergunningaanvragen
		 */
		motivationPlaceholder() {
			if (this.outcome === 'granted') {
				return t(
					'dossiq',
					'The application meets all criteria of the omgevingsplan...',
				)
			}

			if (this.outcome === 'refused') {
				return t(
					'dossiq',
					'The application is refused due to conflict with the omgevingsplan...',
				)
			}

			return t('dossiq', 'Describe the decision motivation...')
		},
	},

	methods: {
		t,
		/**
		 * Record the beschikking on the DSO case.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/dso-omgevingsloket/spec.md#requirement-req-dso-008-dso-status-lifecycle-for-vergunningaanvragen
		 */
		async submit() {
			if (!this.outcome) {
				return
			}

			this.submitting = true
			this.error = null
			try {
				const { data } = await axios.post(
					generateUrl(
						'/apps/dossiq/api/dso/cases/'
							+ encodeURIComponent(this.caseId)
							+ '/beschikking',
					),
					{
						outcome: this.outcome,
						motivation: this.motivation,
					},
				)
				this.success = true
				this.$emit('generated', data)
			} catch {
				this.error = t(
					'dossiq',
					'Could not generate beschikking. Please try again.',
				)
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped>
.beschikking-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 0;
}

.beschikking-dialog__intro {
	color: var(--color-text-maxcontrast);
}

.beschikking-dialog__label {
	display: block;
	font-weight: 600;
	margin-bottom: 6px;
}

.beschikking-dialog__outcome-buttons {
	display: flex;
	gap: 8px;
}

.beschikking-dialog__error {
	color: var(--color-error);
}

.beschikking-dialog__success {
	color: var(--color-success);
}
</style>
