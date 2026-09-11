<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  A user's own email-to-case matching: which of their Mail accounts is read,
  whether it is read at all, and what the last check did. Everything here is
  the caller's own; the server takes the user from the session and refuses an
  account that is not theirs.

  @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
-->
<template>
	<div class="case-email-match">
		<NcLoadingIcon v-if="loading" :size="32" />

		<template v-else>
			<NcNoteCard
				v-if="!instanceEnabled"
				type="info"
				data-testid="case-email-match-instance-off">
				{{
					t(
						'dossiq',
						'Your administrator has not switched this on yet. Your choice is saved and takes effect once they do.',
					)
				}}
			</NcNoteCard>

			<NcNoteCard
				v-if="accounts.length === 0"
				type="warning"
				data-testid="case-email-match-no-accounts">
				{{ t('dossiq', 'Add a mail account in the Mail app first.') }}
			</NcNoteCard>

			<NcSelect
				v-model="accountOption"
				:inputLabel="t('dossiq', 'Mail account')"
				:options="accountOptions"
				:disabled="saving || accounts.length === 0"
				:clearable="false"
				data-testid="case-email-match-account" />

			<NcCheckboxRadioSwitch
				v-model="enabled"
				type="switch"
				:disabled="saving || account === 0"
				data-testid="case-email-match-enabled">
				{{ t('dossiq', 'Link mail from this account to my cases') }}
			</NcCheckboxRadioSwitch>

			<p class="case-email-match__help">
				{{
					t(
						'dossiq',
						'Only the subject and the start of each message are read. Only cases you work on are linked.',
					)
				}}
			</p>

			<div class="case-email-match__actions">
				<NcButton
					variant="primary"
					:disabled="saving"
					data-testid="case-email-match-save"
					@click="save">
					<template #icon>
						<NcLoadingIcon v-if="saving" :size="20" />
					</template>
					{{ t('dossiq', 'Save') }}
				</NcButton>
			</div>

			<NcNoteCard
				v-if="feedback"
				:type="feedback.type"
				data-testid="case-email-match-feedback">
				{{ feedback.message }}
			</NcNoteCard>

			<p
				class="case-email-match__status"
				data-testid="case-email-match-status">
				{{ statusText }}
			</p>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'

/**
 * The endpoint for the caller's own settings.
 *
 * @type {string}
 */
const SETTINGS_URL = '/apps/dossiq/api/settings/email-case-matching'

/**
 * What each refusal code the matcher records means to the user.
 *
 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
 * @return {object<string, string>} The messages, by code.
 */
export function refusalMessages() {
	return {
		account_not_owned: t(
			'dossiq',
			'The chosen account is not yours, so nothing was read.',
		),
		pattern_invalid: t(
			'dossiq',
			'The case number pattern is broken. Ask your administrator to fix it.',
		),
		register_unconfigured: t(
			'dossiq',
			'Cases are not set up yet. Ask your administrator.',
		),
		openregister_unavailable: t(
			'dossiq',
			'Cases could not be reached. The next check tries again.',
		),
		scope_unavailable: t(
			'dossiq',
			'Your access could not be checked, so nothing was linked.',
		),
		email_leaf_unavailable: t(
			'dossiq',
			'Mail linking is not available here. Ask your administrator.',
		),
		run_failed: t(
			'dossiq',
			'The last check failed. The next check tries again.',
		),
	}
}

export default {
	name: 'CaseEmailMatchSettings',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
	},

	data() {
		return {
			loading: true,
			saving: false,
			instanceEnabled: false,
			enabled: false,
			account: 0,
			accounts: [],
			status: null,
			feedback: null,
		}
	},

	computed: {
		/**
		 * The caller's Mail accounts as select options.
		 *
		 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
		 * @return {Array<object>} The options.
		 */
		accountOptions() {
			return this.accounts.map((a) => ({
				id: a.id,
				label: a.email ? `${a.name} (${a.email})` : a.name,
			}))
		},

		/**
		 * The chosen account as an option, or null.
		 *
		 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
		 */
		accountOption: {
			/**
			 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
			 * @return {object|null} The option.
			 */
			get() {
				return this.accountOptions.find((o) => o.id === this.account) || null
			},

			/**
			 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
			 * @param {object|null} option The option.
			 * @return {void}
			 */
			set(option) {
				this.account = option ? option.id : 0
			},
		},

		/**
		 * One line saying what the last check did, or why it did nothing.
		 *
		 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
		 * @return {string} The line.
		 */
		statusText() {
			if (!this.status || !this.status.lastRunAt) {
				return t(
					'dossiq',
					'Not checked yet. The first check starts at your newest mail, so older mail is not linked.',
				)
			}
			if (this.status.error) {
				return (
					refusalMessages()[this.status.error]
					|| t(
						'dossiq',
						'The last check failed. The next check tries again.',
					)
				)
			}
			return t(
				'dossiq',
				'Last check: {when}. Messages read: {scanned}. Links made: {linked}.',
				{
					when: new Date(this.status.lastRunAt).toLocaleString(),
					scanned: this.status.scanned,
					linked: this.status.linked,
				},
			)
		},
	},

	/**
	 * Read the caller's settings.
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 * @return {Promise<void>}
	 */
	async mounted() {
		await this.load()
	},

	methods: {
		/**
		 * Take the server's answer as the state on screen.
		 *
		 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
		 * @param {object} data The settings payload.
		 * @return {void}
		 */
		apply(data) {
			this.instanceEnabled = data.instanceEnabled === true
			this.enabled = data.enabled === true
			this.account = Number(data.account) || 0
			this.accounts = Array.isArray(data.accounts) ? data.accounts : []
			this.status = data.status || null
		},

		/**
		 * Read the caller's settings, accounts and last run.
		 *
		 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			try {
				const { data } = await axios.get(generateUrl(SETTINGS_URL))
				this.apply(data)
			} catch {
				this.feedback = {
					type: 'error',
					message: t(
						'dossiq',
						'Could not load your settings. Reload the page to try again.',
					),
				}
			} finally {
				this.loading = false
			}
		},

		/**
		 * Save the caller's choice.
		 *
		 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
		 * @return {Promise<void>}
		 */
		async save() {
			this.saving = true
			this.feedback = null
			try {
				const { data } = await axios.put(generateUrl(SETTINGS_URL), {
					enabled: this.enabled,
					account: this.account,
				})
				this.apply(data)
				this.feedback = {
					type: 'success',
					message: t(
						'dossiq',
						'Saved. New mail is checked every five minutes.',
					),
				}
			} catch (error) {
				const status = error?.response?.status
				let message = t('dossiq', 'Could not save. Try again.')
				if (status === 403) {
					message = t('dossiq', 'That mail account is not yours.')
				} else if (status === 400) {
					message = t('dossiq', 'Choose a mail account first.')
				}
				this.feedback = { type: 'error', message }
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.case-email-match {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 600px;
}

.case-email-match__help,
.case-email-match__status {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.case-email-match__actions {
	display: flex;
	gap: 8px;
}
</style>
