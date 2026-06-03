<!--
SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<NcDialog
		:name="t('procest', 'Beschikking genereren')"
		size="normal"
		@closing="$emit('close')">
		<div class="beschikking-dialog">
			<div class="form-group">
				<label>{{ t('procest', 'Uitkomst') }}</label>
				<NcCheckboxRadioSwitch
					v-model="form.outcome"
					value="verleend"
					type="radio"
					name="outcome">
					{{ t('procest', 'Verleend') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					v-model="form.outcome"
					value="geweigerd"
					type="radio"
					name="outcome">
					{{ t('procest', 'Geweigerd') }}
				</NcCheckboxRadioSwitch>
			</div>

			<div class="form-group">
				<label>{{ t('procest', 'Motivering') }}</label>
				<textarea
					v-model="form.motivation"
					class="beschikking-dialog__textarea"
					:placeholder="outcomeMotivationPlaceholder"
					rows="5" />
			</div>

			<p v-if="error" class="beschikking-dialog__error">
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
				{{ loading ? t('procest', 'Generating…') : t('procest', 'Generate beschikking') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js'
import NcDialog from '@nextcloud/vue/dist/Components/NcDialog.js'

export default {
	name: 'BeschikkingDialog',
	components: { NcButton, NcCheckboxRadioSwitch, NcDialog },

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
				outcome: 'verleend',
				motivation: '',
			},
		}
	},

	computed: {
		canSubmit() {
			return this.form.outcome !== '' && this.form.motivation.trim() !== ''
		},

		outcomeMotivationPlaceholder() {
			return this.form.outcome === 'verleend'
				? t('procest', 'De aanvraag voldoet aan alle criteria van het omgevingsplan…')
				: t('procest', 'De aanvraag is geweigerd wegens strijd met het omgevingsplan…')
		},
	},

	methods: {
		t,

		async submit() {
			if (!this.canSubmit) return
			this.loading = true
			this.error = null
			try {
				const url = generateUrl('/apps/procest/api/dso/cases/' + encodeURIComponent(this.caseId) + '/beschikking')
				await axios.post(url, {
					outcome: this.form.outcome,
					motivation: this.form.motivation,
				})
				this.$emit('done')
			} catch (err) {
				this.error = t('procest', 'Failed to generate beschikking.')
				console.error('BeschikkingDialog: submit error', err)
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.beschikking-dialog { padding: 4px 0; }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; margin-bottom: 4px; font-weight: bold; }
.beschikking-dialog__textarea {
	width: 100%;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	padding: 8px;
	resize: vertical;
}
.beschikking-dialog__error { color: var(--color-error); margin-top: 8px; }
</style>
