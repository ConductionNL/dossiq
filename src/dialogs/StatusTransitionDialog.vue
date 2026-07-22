<!--
  SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
  @spec openspec/changes/dso-omgevingsloket/tasks.md#T08
-->
<template>
	<NcDialog :name="t('procest', 'Change status')" @closing="$emit('close')">
		<div class="dso-transition-form">
			<NcSelect
				v-model="selectedStatus"
				:options="statusOptions"
				:placeholder="t('procest', 'Select new status')"
				:input-label="t('procest', 'New status')"
				label="label"
				track-by="value" />
			<NcTextField
				v-model="besluitdatum"
				:label="t('procest', 'Besluitdatum (optional)')"
				type="date" />
			<NcTextArea
				v-model="toelichting"
				:label="t('procest', 'Toelichting (optional)')" />
			<p v-if="error" class="form-error">
				{{ error }}
			</p>
			<div class="dso-transition-form__actions">
				<NcButton :disabled="!selectedStatus || submitting" @click="submit">
					{{ t('procest', 'Apply') }}
				</NcButton>
				<NcButton type="secondary" :disabled="submitting" @click="$emit('close')">
					{{ t('procest', 'Cancel') }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'

export default {
	name: 'StatusTransitionDialog',
	components: {
		NcDialog,
		NcButton,
		NcSelect,
		NcTextField,
		NcTextArea,
	},
	props: {
		caseId: {
			type: String,
			required: true,
		},
	},
	emits: ['close', 'submitted'],
	data() {
		return {
			selectedStatus: null,
			besluitdatum: '',
			toelichting: '',
			submitting: false,
			error: null,
			statusOptions: [
				{ label: t('procest', 'Ingediend'), value: 'ingediend' },
				{ label: t('procest', 'In behandeling'), value: 'in_behandeling' },
				{ label: t('procest', 'Verleend'), value: 'verleend' },
				{ label: t('procest', 'Geweigerd'), value: 'geweigerd' },
				{ label: t('procest', 'Ingetrokken'), value: 'ingetrokken' },
			],
		}
	},
	methods: {
		t,
		async submit() {
			if (!this.selectedStatus) return
			this.submitting = true
			this.error = null
			try {
				const url = generateUrl('/apps/procest/api/dso/cases/' + encodeURIComponent(this.caseId) + '/transition')
				const res = await axios.post(url, {
					newStatus: this.selectedStatus.value,
					besluitdatum: this.besluitdatum || null,
					toelichting: this.toelichting || null,
				})
				this.$emit('submitted', res.data)
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
.dso-transition-form {
	padding: 16px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.dso-transition-form__actions {
	display: flex;
	gap: 8px;
}

.form-error {
	color: var(--color-error);
}
</style>
