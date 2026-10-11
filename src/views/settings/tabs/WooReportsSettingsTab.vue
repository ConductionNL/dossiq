<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="woo-reports-tab">
		<p class="woo-reports-tab__description">
			{{
				t(
					'dossiq',
					'Both reports are off until you switch them on. The throughput report counts what each reviewer did per day, so only the group you name may read it.',
				)
			}}
		</p>

		<NcNoteCard v-if="refusal" type="error" data-testid="woo-reports-refusal">
			{{ refusal }}
		</NcNoteCard>

		<NcTextField
			v-model="readers"
			:label="t('dossiq', 'Reader group for the throughput report')"
			:helperText="
				t(
					'dossiq',
					'The id of an existing Nextcloud group. Administrators outside it cannot read the report either.',
				)
			"
			data-testid="woo-reports-readers" />

		<NcCheckboxRadioSwitch
			:modelValue="throughput"
			type="switch"
			data-testid="woo-reports-throughput"
			@update:modelValue="saveThroughput">
			{{ t('dossiq', 'Report throughput per reviewer per day') }}
		</NcCheckboxRadioSwitch>

		<NcCheckboxRadioSwitch
			:modelValue="parties"
			type="switch"
			data-testid="woo-reports-parties"
			@update:modelValue="saveParties">
			{{
				t('dossiq', 'Report collected mail by sender, recipient and domain')
			}}
		</NcCheckboxRadioSwitch>

		<NcLoadingIcon v-if="saving" :size="20" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcNoteCard,
	NcTextField,
} from '@nextcloud/vue'
import { useSettingsStore } from '../../../store/modules/settings.js'

/**
 * Whether a stored setting means yes.
 *
 * @param {string|boolean|undefined} value The stored value.
 * @return {boolean} True for 'true', '1', 'yes' or 'on'.
 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-both-reports-are-opt-in-per-organisation-off-by-default-req-wrr-001
 */
export function isSwitchedOn(value) {
	if (typeof value === 'boolean') {
		return value
	}
	return ['1', 'true', 'yes', 'on'].includes(
		String(value ?? '')
			.trim()
			.toLowerCase(),
	)
}

/**
 * The two Woo review report switches and the throughput reader group (decision D9).
 *
 * It saves through the settings route directly rather than the settings store,
 * because the store swallows a refusal. The server refuses the throughput
 * switch without an existing reader group, and the administrator must see
 * that sentence instead of a switch that silently flips back.
 *
 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-both-reports-are-opt-in-per-organisation-off-by-default-req-wrr-001
 */
export default {
	name: 'WooReportsSettingsTab',
	components: {
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
	},

	data() {
		return {
			throughput: false,
			parties: false,
			readers: '',
			saving: false,
			refusal: '',
		}
	},

	/** @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-both-reports-are-opt-in-per-organisation-off-by-default-req-wrr-001 */
	async created() {
		const store = useSettingsStore()
		if (!store.isInitialized) {
			await store.fetchSettings()
		}
		this.apply(store.getConfig || {})
	},

	methods: {
		t,

		/**
		 * Take the stored values.
		 *
		 * @param {object} config The stored settings.
		 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-both-reports-are-opt-in-per-organisation-off-by-default-req-wrr-001
		 */
		apply(config) {
			this.throughput = isSwitchedOn(config.wooReviewerThroughputReport)
			this.parties = isSwitchedOn(config.wooCollectionPartiesReport)
			this.readers = config.wooReviewerThroughputReaders || ''
		},

		/**
		 * Switch the throughput report, saving the reader group with it.
		 *
		 * @param {boolean} on The new state.
		 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-both-reports-are-opt-in-per-organisation-off-by-default-req-wrr-001
		 */
		async saveThroughput(on) {
			await this.persist({
				wooReviewerThroughputReport: on ? 'true' : 'false',
				wooReviewerThroughputReaders: this.readers.trim(),
			})
		},

		/**
		 * Switch the parties report.
		 *
		 * @param {boolean} on The new state.
		 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-both-reports-are-opt-in-per-organisation-off-by-default-req-wrr-001
		 */
		async saveParties(on) {
			await this.persist({ wooCollectionPartiesReport: on ? 'true' : 'false' })
		},

		/**
		 * Save, and show the server's refusal when it refuses.
		 *
		 * @param {object} changes The settings to save.
		 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-both-reports-are-opt-in-per-organisation-off-by-default-req-wrr-001
		 */
		async persist(changes) {
			this.saving = true
			this.refusal = ''
			try {
				const { data } = await axios.post(
					generateUrl('/apps/dossiq/api/settings'),
					changes,
				)
				this.apply(data?.config || {})
			} catch (error) {
				this.refusal =
					error?.response?.data?.message
					|| t('dossiq', 'These settings could not be saved.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.woo-reports-tab {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 600px;
}

.woo-reports-tab__description {
	color: var(--color-text-maxcontrast);
}
</style>
