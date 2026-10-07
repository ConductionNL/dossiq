<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Picks the background service account: the Nextcloud account the background
  - jobs (the termijn reminder sweep) write as. It shows the account in use,
  - warns when it is unset, unknown, disabled or outside its group, and lets an
  - admin choose one. The endpoint answers GET and PUT with
  - {userId, usable, reason, group}; both are admin only. The same control as
  - pipelinq's ServiceAccountPicker.
  -
  - @spec openspec/specs/termijn-pause-extension/spec.md
-->
<template>
	<div class="service-account">
		<p class="service-account__help">
			{{
				t(
					'dossiq',
					'Background jobs, such as the reminders on suspended terms, have no signed-in user. They save as this account, which joins the group {group}. Pick an account that nobody signs in with.',
					{ group: account.group },
				)
			}}
		</p>
		<NcNoteCard v-if="!account.usable" type="warning">
			{{ problem }}
		</NcNoteCard>
		<div class="service-account__row">
			<div class="service-account__field">
				<label for="dossiq-background-service-account">{{
					t('dossiq', 'Nextcloud user name')
				}}</label>
				<input
					id="dossiq-background-service-account"
					v-model.trim="input"
					type="text"
					autocomplete="off"
					@keyup.enter="save" />
			</div>
			<div class="service-account__field service-account__field--action">
				<NcButton :disabled="saving || !input" @click="save">
					{{ t('dossiq', 'Use this account') }}
				</NcButton>
			</div>
		</div>
		<NcNoteCard v-if="message" :type="messageType">
			{{ message }}
		</NcNoteCard>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard } from '@nextcloud/vue'

export default {
	name: 'BackgroundServiceAccountSettings',
	components: {
		NcButton,
		NcNoteCard,
	},

	data() {
		return {
			account: {
				userId: '',
				usable: true,
				reason: null,
				group: 'dossiq-background-service',
			},

			input: '',
			saving: false,
			message: '',
			messageType: 'success',
		}
	},

	computed: {
		/**
		 * The admin endpoint that answers GET and PUT.
		 *
		 * @return {string} The URL.
		 *
		 * @spec openspec/specs/termijn-pause-extension/spec.md
		 */
		url() {
			return generateUrl(
				'/apps/dossiq/api/settings/background-service-account',
			)
		},

		/**
		 * Why the account cannot be used, in words.
		 *
		 * @return {string} The problem.
		 *
		 * @spec openspec/specs/termijn-pause-extension/spec.md
		 */
		problem() {
			const reasons = {
				unknown: t('dossiq', 'The chosen account does not exist.'),
				disabled: t('dossiq', 'The chosen account is disabled.'),
				'not-in-group': t(
					'dossiq',
					'The chosen account is not in the group {group}.',
					{ group: this.account.group },
				),
			}
			const why =
				reasons[this.account.reason] || t('dossiq', 'No account is chosen.')
			return (
				why
				+ ' '
				+ t(
					'dossiq',
					'Until you choose one, reminders on suspended terms are not sent and nothing is saved.',
				)
			)
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Load the account in use.
		 *
		 * @spec openspec/specs/termijn-pause-extension/spec.md
		 */
		async load() {
			try {
				const response = await axios.get(this.url)
				this.account = { ...this.account, ...(response.data || {}) }
				this.input = this.account.userId || ''
			} catch {
				this.message = t(
					'dossiq',
					'The background service account could not be loaded.',
				)
				this.messageType = 'error'
			}
		},

		/**
		 * Use the typed account.
		 *
		 * @spec openspec/specs/termijn-pause-extension/spec.md
		 */
		async save() {
			this.saving = true
			this.message = ''
			try {
				const response = await axios.put(this.url, {
					userId: this.input,
				})
				this.account = { ...this.account, ...(response.data || {}) }
				this.message = t('dossiq', 'Background jobs now save as {userId}.', {
					userId: this.account.userId,
				})
				this.messageType = 'success'
			} catch (error) {
				this.message =
					error?.response?.data?.message
					|| t(
						'dossiq',
						'The background service account could not be saved.',
					)
				this.messageType = 'error'
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.service-account {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.service-account__help {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.service-account__row {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	align-items: flex-end;
}

.service-account__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	min-width: 240px;
}

.service-account__field--action {
	min-width: auto;
}
</style>
