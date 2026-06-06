<!--
  SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
  @spec openspec/changes/dso-omgevingsloket/tasks.md#T08
-->
<template>
	<NcModal :name="t('procest', 'Initiate samenwerkverzoek')" @close="$emit('close')">
		<div class="samenwerk-dialog">
			<p>{{ t('procest', 'Request cooperation from another bevoegd gezag for this omgevingsvergunning.') }}</p>

			<div class="form-group">
				<label>{{ t('procest', 'Aangezochte bevoegd gezag') }} *</label>
				<NcSelect
					v-model="aangezochtBevoegdGezag"
					:options="bevoegdGezagOptions"
					:placeholder="t('procest', 'Select or type bevoegd gezag...')"
					:input-label="t('procest', 'Bevoegd gezag')"
					label="label"
					track-by="value"
					:taggable="true"
					:tag-placeholder="t('procest', 'Add custom bevoegd gezag')"
					@tag="onCustomBevoegdGezag" />
			</div>

			<div class="form-group">
				<label>{{ t('procest', 'Rationale') }} *</label>
				<NcTextArea
					v-model="rationale"
					:label="t('procest', 'Reason for samenwerking')"
					:placeholder="t('procest', 'Explain why this bevoegd gezag needs to be involved...')"
					:rows="4" />
			</div>

			<p v-if="error" class="form-error">
				{{ error }}
			</p>

			<div class="samenwerk-dialog__actions">
				<NcButton :disabled="!isValid || submitting" @click="submit">
					<template v-if="submitting">
						<NcLoadingIcon :size="16" />
						{{ t('procest', 'Submitting...') }}
					</template>
					<template v-else>
						{{ t('procest', 'Send samenwerkverzoek') }}
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
	name: 'SamenwerkverzoekDialog',
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
			aangezochtBevoegdGezag: null,
			rationale: '',
			submitting: false,
			error: null,
			bevoegdGezagOptions: [
				{ label: 'Waterschap Amstel, Gooi en Vecht', value: 'Waterschap Amstel, Gooi en Vecht' },
				{ label: 'Waterschap Noorderzijlvest', value: 'Waterschap Noorderzijlvest' },
				{ label: 'Provincie Noord-Holland', value: 'Provincie Noord-Holland' },
				{ label: 'Provincie Zuid-Holland', value: 'Provincie Zuid-Holland' },
				{ label: 'Provincie Groningen', value: 'Provincie Groningen' },
				{ label: 'Provincie Utrecht', value: 'Provincie Utrecht' },
				{ label: 'Rijkswaterstaat', value: 'Rijkswaterstaat' },
			],
		}
	},
	computed: {
		isValid() {
			return this.aangezochtBevoegdGezag && this.rationale.trim().length > 0
		},
	},
	methods: {
		t,
		onCustomBevoegdGezag(tag) {
			const option = { label: tag, value: tag }
			this.bevoegdGezagOptions.push(option)
			this.aangezochtBevoegdGezag = option
		},
		async submit() {
			if (!this.isValid) return
			this.submitting = true
			this.error = null
			try {
				const url = generateUrl('/apps/procest/api/dso/cases/' + encodeURIComponent(this.caseId) + '/samenwerking')
				await axios.post(url, {
					aangezochtBevoegdGezag: this.aangezochtBevoegdGezag.value,
					rationale: this.rationale,
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
.samenwerk-dialog {
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
.samenwerk-dialog__actions {
	display: flex;
	gap: 8px;
}
</style>
