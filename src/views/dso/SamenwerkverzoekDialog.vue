<!--
SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<NcDialog
		:name="t('procest', 'Initiate samenwerkverzoek')"
		size="normal"
		@closing="$emit('close')">
		<div class="samenwerk-dialog">
			<p class="samenwerk-dialog__intro">
				{{ t('procest', 'Request coordination with another bevoegd gezag for this vergunningaanvraag.') }}
			</p>

			<div class="form-group">
				<label>{{ t('procest', 'Aangezochte bevoegd gezag') }}</label>
				<NcSelect
					v-model="form.aangezochtBevoegdGezag"
					:label="t('procest', 'Search organization...')"
					:options="gezagOptions"
					:user-select="false" />
			</div>

			<div class="form-group">
				<label>{{ t('procest', 'Rationale') }}</label>
				<textarea
					v-model="form.rationale"
					class="samenwerk-dialog__textarea"
					:placeholder="t('procest', 'Describe why samenwerking is needed...')"
					rows="4" />
			</div>

			<p v-if="error" class="samenwerk-dialog__error">
				{{ error }}
			</p>
		</div>

		<template #actions>
			<NcButton type="tertiary" @click="$emit('close')">
				{{ t('procest', 'Cancel') }}
			</NcButton>
			<NcButton
				type="primary"
				:disabled="!canSubmit || loading"
				@click="submit">
				{{ loading ? t('procest', 'Submitting…') : t('procest', 'Submit request') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcDialog from '@nextcloud/vue/dist/Components/NcDialog.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'

export default {
	name: 'SamenwerkverzoekDialog',
	components: { NcButton, NcDialog, NcSelect },

	props: {
		caseId: {
			type: String,
			required: true,
		},
	},

	emits: ['close', 'done'],

	data() {
		return {
			loading: false,
			error: null,
			form: {
				aangezochtBevoegdGezag: null,
				rationale: '',
			},
			gezagOptions: [
				{ label: 'Gemeente Amsterdam', value: 'Gemeente Amsterdam' },
				{ label: 'Gemeente Rotterdam', value: 'Gemeente Rotterdam' },
				{ label: 'Provincie Noord-Holland', value: 'Provincie Noord-Holland' },
				{ label: 'Provincie Zuid-Holland', value: 'Provincie Zuid-Holland' },
				{ label: 'Waterschap Amstel, Gooi en Vecht', value: 'Waterschap Amstel, Gooi en Vecht' },
				{ label: 'Omgevingsdienst NZKG', value: 'Omgevingsdienst NZKG' },
			],
		}
	},

	computed: {
		canSubmit() {
			return this.form.aangezochtBevoegdGezag?.value && this.form.rationale.trim() !== ''
		},
	},

	methods: {
		t,

		async submit() {
			if (!this.canSubmit) return
			this.loading = true
			this.error = null
			try {
				const url = generateUrl('/apps/procest/api/dso/cases/' + encodeURIComponent(this.caseId) + '/samenwerking')
				await axios.post(url, {
					aangezochtBevoegdGezag: this.form.aangezochtBevoegdGezag.value,
					rationale: this.form.rationale,
				})
				this.$emit('done')
			} catch (err) {
				this.error = t('procest', 'Failed to submit samenwerkverzoek.')
				console.error('SamenwerkverzoekDialog: submit error', err)
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.samenwerk-dialog { padding: 4px 0; }
.samenwerk-dialog__intro { margin-bottom: 16px; }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; margin-bottom: 4px; font-weight: bold; }
.samenwerk-dialog__textarea {
	width: 100%;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	padding: 8px;
	resize: vertical;
}
.samenwerk-dialog__error { color: var(--color-error); margin-top: 8px; }
</style>
