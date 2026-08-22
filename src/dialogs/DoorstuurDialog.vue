<!--
 SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Forward verzoek — Doorsturen')"
		:canClose="true"
		@close="$emit('close')">
		<template #default>
			<div class="doorstuur-dialog">
				<p class="doorstuur-dialog__intro">
					{{
						t(
							'dossiq',
							'Forward this vergunningaanvraag to another bevoegd gezag via DSO-LV.',
						)
					}}
				</p>

				<NcTextField
					v-model="doelBevoegdGezag"
					:label="t('dossiq', 'Doel bevoegd gezag (OIN or name)')"
					:required="true"
					:placeholder="t('dossiq', 'e.g. Gemeente Utrecht')" />

				<NcTextArea
					v-model="reason"
					:label="t('dossiq', 'Reason for forwarding')"
					:placeholder="
						t('dossiq', 'Explain why the verzoek is being forwarded...')
					"
					rows="4" />

				<div v-if="error" class="doorstuur-dialog__error">
					{{ error }}
				</div>

				<div v-if="success" class="doorstuur-dialog__success">
					{{
						t(
							'dossiq',
							'Verzoek successfully forwarded to OpenConnector for DSO-LV transmission.',
						)
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
				:disabled="!doelBevoegdGezag || submitting || success"
				@click="submit">
				{{ t('dossiq', 'Forward') }}
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
import NcTextField from '@nextcloud/vue/components/NcTextField'

export default {
	name: 'DoorstuurDialog',
	components: { NcButton, NcDialog, NcTextArea, NcTextField },
	props: {
		caseId: {
			type: String,
			required: true,
		},
	},

	emits: ['close'],
	data() {
		return {
			doelBevoegdGezag: '',
			reason: '',
			submitting: false,
			error: null,
			success: false,
		}
	},

	methods: {
		t,
		/**
		 * Forward the case to another bevoegd gezag.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/dso-omgevingsloket/spec.md#requirement-req-dso-011-multi-tenancy-and-bevoegd-gezag-isolation
		 */
		async submit() {
			if (!this.doelBevoegdGezag) {
				return
			}

			this.submitting = true
			this.error = null
			try {
				await axios.post(
					generateUrl(
						'/apps/dossiq/api/dso/cases/'
							+ encodeURIComponent(this.caseId)
							+ '/doorstuur',
					),
					{
						doelBevoegdGezag: this.doelBevoegdGezag,
						reason: this.reason,
					},
				)
				this.success = true
			} catch {
				this.error = t(
					'dossiq',
					'Could not forward verzoek. Please try again.',
				)
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped>
.doorstuur-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 0;
}

.doorstuur-dialog__intro {
	color: var(--color-text-maxcontrast);
}

.doorstuur-dialog__error {
	color: var(--color-error);
}

.doorstuur-dialog__success {
	color: var(--color-success);
}
</style>
