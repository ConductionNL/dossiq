<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  -
  - Notification preferences for the citizen portal (zaakportaal-mijngemeente).
  - The Berichtenbox channel is statutory and rendered as a checked, disabled
  - control. Email and per-event toggles persist via PATCH to the IDOR-safe
  - /api/portaal/notification-preferences endpoint.
-->
<template>
	<div class="zp-notificaties">
		<h1>{{ t('procest', 'Notification preferences') }}</h1>

		<div v-if="loading" class="zp-state">
			<NcLoadingIcon :size="32" />
		</div>

		<form v-else @submit.prevent="save">
			<fieldset class="zp-fieldset">
				<legend>{{ t('procest', 'Channels') }}</legend>

				<NcCheckboxRadioSwitch :checked.sync="prefs.emailActief" type="switch">
					{{ t('procest', 'Receive email notifications') }}
				</NcCheckboxRadioSwitch>

				<NcCheckboxRadioSwitch :checked="true" :disabled="true" type="switch">
					{{ t('procest', 'Receive notifications via Berichtenbox (statutory, cannot be disabled)') }}
				</NcCheckboxRadioSwitch>

				<NcCheckboxRadioSwitch :checked.sync="prefs.smsActief" type="switch">
					{{ t('procest', 'Receive SMS notifications') }}
				</NcCheckboxRadioSwitch>
			</fieldset>

			<fieldset class="zp-fieldset">
				<legend>{{ t('procest', 'Events') }}</legend>

				<NcCheckboxRadioSwitch :checked.sync="prefs.eventStatuswijziging">
					{{ t('procest', 'Status change') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch :checked.sync="prefs.eventDocumentToegevoegd">
					{{ t('procest', 'Document added') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch :checked.sync="prefs.eventBerichtVanBehandelaar">
					{{ t('procest', 'Message from handler') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch :checked.sync="prefs.eventTermijnHerinnering">
					{{ t('procest', 'Deadline reminder') }}
				</NcCheckboxRadioSwitch>
			</fieldset>

			<NcButton type="primary" native-type="submit" :disabled="saving">
				{{ t('procest', 'Save preferences') }}
			</NcButton>

			<p v-if="message" class="zp-message" role="status">{{ message }}</p>
			<p v-if="error" class="zp-message zp-message--error" role="alert">{{ error }}</p>
		</form>
	</div>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

export default {
	name: 'MijnNotificaties',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
	},
	data() {
		return {
			loading: true,
			saving: false,
			message: '',
			error: '',
			prefs: {
				emailActief: false,
				smsActief: false,
				eventStatuswijziging: true,
				eventDocumentToegevoegd: true,
				eventBerichtVanBehandelaar: true,
				eventTermijnHerinnering: true,
			},
		}
	},
	async mounted() {
		await this.load()
	},
	methods: {
		async load() {
			this.loading = true
			try {
				const { data } = await axios.get(generateUrl('/apps/procest/api/portaal/notification-preferences'))
				this.prefs = { ...this.prefs, ...data }
			} catch (e) {
				this.error = this.t('procest', 'Could not load your preferences.')
			} finally {
				this.loading = false
			}
		},
		async save() {
			this.saving = true
			this.message = ''
			this.error = ''
			try {
				await axios.patch(generateUrl('/apps/procest/api/portaal/notification-preferences'), this.prefs)
				this.message = this.t('procest', 'Preference saved.')
			} catch (e) {
				this.error = this.t('procest', 'Could not save your preferences.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.zp-notificaties {
	padding: 24px;
	max-width: 720px;
	margin: 0 auto;
}

.zp-fieldset {
	border: 1px solid var(--color-border, #d0d0d0);
	border-radius: var(--border-radius-large, 8px);
	padding: 16px;
	margin-bottom: 16px;
}

.zp-message {
	margin-top: 12px;
}

.zp-message--error {
	color: var(--color-error, #c4341f);
}

.zp-state {
	display: flex;
	justify-content: center;
	padding: 32px;
}
</style>
