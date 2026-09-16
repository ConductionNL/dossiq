<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  When your daily digest arrives, and whether it arrives at all.

  It sits in Settings, Personal, beside the reader's other notification
  choices, and not on a dossiq admin page: the choice is the reader's, and an
  administrator switching it off for everybody is not the same feature.

  The switch is now OpenRegister's notification preference, so a team lead may
  set the team's default and the reader may still decide for themselves. That
  is why the control says which layer decided: a team default that read only as
  "off" would look like the setting was broken.

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

		<p class="work-digest__explainer" data-testid="work-digest-layer">
			{{ decidedBy }}
		</p>

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
			source: 'dossiq',
			scope: 'global',
		}
	},

	computed: {
		/**
		 * Which layer decided this switch, in words.
		 *
		 * A switch whose effect depends on a layer the screen does not show is a
		 * switch people stop trusting. A team default that reads only as "off"
		 * looks like the setting is broken.
		 *
		 * @return {string} The sentence.
		 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-the-daily-digest-switch-is-a-notification-preference-req-urs-06
		 */
		decidedBy() {
			if (this.source === 'user-override') {
				return t('dossiq', 'You set this.')
			}

			if (this.source === 'group-default') {
				return t('dossiq', 'Your team set this. You can decide for yourself.')
			}

			if (this.source === 'schema-default') {
				return t('dossiq', 'Nobody has changed this, so it is on the setting it ships with.')
			}

			return t('dossiq', 'This instance keeps the setting here rather than with your other notifications.')
		},
	},

	/**
	 * Read the reader's own digest settings.
	 *
	 * @return {Promise<void>} When the read has finished.
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	async mounted() {
		this.apply(await fetchDigestSettings())
	},

	methods: {
		t,

		/**
		 * Take the settings, and the layer that decided them.
		 *
		 * @param {object} settings What the server answered.
		 * @return {void}
		 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-the-daily-digest-switch-is-a-notification-preference-req-urs-06
		 */
		apply(settings) {
			this.enabled = settings.enabled
			this.hour = settings.hour
			this.source = (settings.source || 'dossiq')
			this.scope = (settings.scope || 'global')
		},

		/**
		 * Switch the digest on or off.
		 *
		 * @param {boolean} enabled Whether the reader wants one.
		 * @return {Promise<void>} When the write has finished.
		 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
		 */
		async setEnabled(enabled) {
			this.apply(await saveDigestSettings(enabled, this.hour))
		},

		/**
		 * Choose the hour.
		 *
		 * @param {string} hour The hour, as the field gives it.
		 * @return {Promise<void>} When the write has finished.
		 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
		 */
		async setHour(hour) {
			this.apply(await saveDigestSettings(this.enabled, Number(hour)))
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
