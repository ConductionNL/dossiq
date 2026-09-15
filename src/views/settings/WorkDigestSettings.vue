<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  When your daily digest arrives, and whether it arrives at all.

  It sits in Settings, Personal, beside the reader's other notification
  choices, and not on a dossiq admin page: the choice is the reader's, and an
  administrator switching it off for everybody is not the same feature. The
  routed version of this is OpenRegister's notification routing per group and
  scope, which this instance does not carry yet; when it does, this control
  moves there and the preference goes with it.

  There is no "send a test" button. A digest that says nothing is never sent,
  so a test send would either lie about the empty case or write a real record.

  @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
-->
<template>
	<div class="work-digest">
		<NcCheckboxRadioSwitch
			:modelValue="enabled"
			type="switch"
			data-testid="work-digest-enabled"
			@update:modelValue="setEnabled">
			{{ t('dossiq', 'Send me a daily digest of my open work') }}
		</NcCheckboxRadioSwitch>

		<NcTextField
			v-if="enabled"
			:modelValue="String(hour)"
			type="number"
			min="0"
			max="23"
			:label="t('dossiq', 'Hour of the day')"
			data-testid="work-digest-hour"
			@update:modelValue="setHour" />

		<p class="work-digest__explainer">
			{{ t('dossiq', 'A day with nothing waiting sends nothing.') }}
		</p>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcCheckboxRadioSwitch, NcTextField } from '@nextcloud/vue'
import { fetchDigestSettings, saveDigestSettings } from '../../services/personalQueueApi.js'

export default {
	name: 'WorkDigestSettings',

	components: {
		NcCheckboxRadioSwitch,
		NcTextField,
	},

	data() {
		return {
			enabled: true,
			hour: 8,
		}
	},

	async mounted() {
		const settings = await fetchDigestSettings()
		this.enabled = settings.enabled
		this.hour = settings.hour
	},

	methods: {
		t,

		/**
		 * Switch the digest on or off.
		 *
		 * @param {boolean} enabled Whether the reader wants one.
		 * @return {Promise<void>} When the write has finished.
		 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
		 */
		async setEnabled(enabled) {
			const saved = await saveDigestSettings(enabled, this.hour)
			this.enabled = saved.enabled
			this.hour = saved.hour
		},

		/**
		 * Choose the hour.
		 *
		 * @param {string} hour The hour, as the field gives it.
		 * @return {Promise<void>} When the write has finished.
		 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
		 */
		async setHour(hour) {
			const saved = await saveDigestSettings(this.enabled, Number(hour))
			this.enabled = saved.enabled
			this.hour = saved.hour
		},
	},
}
</script>

<style scoped>
.work-digest {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.work-digest__explainer {
	color: var(--color-text-maxcontrast);
	margin: 0;
}
</style>
