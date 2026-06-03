<!--
SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<NcDialog
		:name="t('procest', 'Doorsturen naar ander bevoegd gezag')"
		size="normal"
		@closing="$emit('close')">
		<div class="doorstuur-dialog">
			<p class="doorstuur-dialog__intro">
				{{ t('procest', 'Forward this vergunningaanvraag to the correct bevoegd gezag.') }}
			</p>

			<div class="form-group">
				<label>{{ t('procest', 'Doel bevoegd gezag') }}</label>
				<NcSelect
					v-model="form.doelBevoegdGezag"
					:label="t('procest', 'Search organization...')"
					:options="gezagOptions" />
			</div>

			<div class="form-group">
				<label>{{ t('procest', 'Reden') }}</label>
				<textarea
					v-model="form.reden"
					class="doorstuur-dialog__textarea"
					:placeholder="t('procest', 'Describe why this case is being forwarded...')"
					rows="3" />
			</div>

			<p v-if="error" class="doorstuur-dialog__error">
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
				{{ loading ? t('procest', 'Forwarding…') : t('procest', 'Forward') }}
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
	name: 'DoorstuurDialog',
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
				doelBevoegdGezag: null,
				reden: '',
			},
			gezagOptions: [
				{ label: 'Gemeente Amsterdam', value: 'Gemeente Amsterdam' },
				{ label: 'Gemeente Rotterdam', value: 'Gemeente Rotterdam' },
				{ label: 'Gemeente Den Haag', value: 'Gemeente Den Haag' },
				{ label: 'Provincie Noord-Holland', value: 'Provincie Noord-Holland' },
				{ label: 'Waterschap Amstel, Gooi en Vecht', value: 'Waterschap Amstel, Gooi en Vecht' },
			],
		}
	},

	computed: {
		canSubmit() {
			return this.form.doelBevoegdGezag?.value
		},
	},

	methods: {
		t,

		async submit() {
			if (!this.canSubmit) return
			this.loading = true
			this.error = null
			try {
				const url = generateUrl('/apps/procest/api/dso/cases/' + encodeURIComponent(this.caseId) + '/doorstuur')
				await axios.post(url, {
					doelBevoegdGezag: this.form.doelBevoegdGezag.value,
					reden: this.form.reden,
				})
				this.$emit('done')
			} catch (err) {
				this.error = t('procest', 'Failed to forward case.')
				console.error('DoorstuurDialog: submit error', err)
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.doorstuur-dialog { padding: 4px 0; }
.doorstuur-dialog__intro { margin-bottom: 16px; }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; margin-bottom: 4px; font-weight: bold; }
.doorstuur-dialog__textarea {
	width: 100%;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	padding: 8px;
	resize: vertical;
}
.doorstuur-dialog__error { color: var(--color-error); margin-top: 8px; }
</style>
