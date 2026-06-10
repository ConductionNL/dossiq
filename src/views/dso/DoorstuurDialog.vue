<!--
  SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
  @spec openspec/changes/dso-omgevingsloket/tasks.md#T08
-->
<template>
	<NcModal :name="t('procest', 'Forward verzoek (doorstuur)')" @close="$emit('close')">
		<div class="doorstuur-dialog">
			<p>{{ t('procest', 'Forward this vergunningaanvraag to the correct bevoegd gezag.') }}</p>

			<div class="form-group">
				<label>{{ t('procest', 'Target bevoegd gezag') }} *</label>
				<NcSelect
					v-model="targetBevoegdGezag"
					:options="bevoegdGezagOptions"
					:placeholder="t('procest', 'Select bevoegd gezag...')"
					:input-label="t('procest', 'Target bevoegd gezag')"
					label="label"
					track-by="value"
					:taggable="true"
					:tag-placeholder="t('procest', 'Add custom bevoegd gezag')"
					@tag="onCustomBevoegdGezag" />
			</div>

			<div class="form-group">
				<label>{{ t('procest', 'Reden (reason)') }} *</label>
				<NcTextArea
					v-model="reden"
					:label="t('procest', 'Reason for forwarding')"
					:placeholder="t('procest', 'Explain why this verzoek is being forwarded...')"
					:rows="4" />
			</div>

			<p v-if="error" class="form-error">
				{{ error }}
			</p>

			<div class="doorstuur-dialog__actions">
				<NcButton :disabled="!isValid || submitting" @click="submit">
					<template v-if="submitting">
						<NcLoadingIcon :size="16" />
						{{ t('procest', 'Forwarding...') }}
					</template>
					<template v-else>
						{{ t('procest', 'Forward') }}
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
	name: 'DoorstuurDialog',
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
			targetBevoegdGezag: null,
			reden: '',
			submitting: false,
			error: null,
			bevoegdGezagOptions: [
				{ label: 'Gemeente Amsterdam', value: 'Gemeente Amsterdam' },
				{ label: 'Gemeente Rotterdam', value: 'Gemeente Rotterdam' },
				{ label: 'Gemeente Den Haag', value: 'Gemeente Den Haag' },
				{ label: 'Gemeente Utrecht', value: 'Gemeente Utrecht' },
				{ label: 'Gemeente Groningen', value: 'Gemeente Groningen' },
				{ label: 'Provincie Noord-Holland', value: 'Provincie Noord-Holland' },
				{ label: 'Rijkswaterstaat', value: 'Rijkswaterstaat' },
			],
		}
	},
	computed: {
		isValid() {
			return this.targetBevoegdGezag && this.reden.trim().length > 0
		},
	},
	methods: {
		t,
		onCustomBevoegdGezag(tag) {
			const option = { label: tag, value: tag }
			this.bevoegdGezagOptions.push(option)
			this.targetBevoegdGezag = option
		},
		async submit() {
			if (!this.isValid) return
			this.submitting = true
			this.error = null
			try {
				const url = generateUrl('/apps/procest/api/dso/cases/' + encodeURIComponent(this.caseId) + '/doorstuur')
				await axios.post(url, {
					targetBevoegdGezag: this.targetBevoegdGezag.value,
					reden: this.reden,
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
.doorstuur-dialog {
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
.doorstuur-dialog__actions {
	display: flex;
	gap: 8px;
}
</style>
