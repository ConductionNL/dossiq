<template>
	<div class="email-settings">
		<NcNoteCard type="info">
			{{
				t(
					'dossiq',
					'Configure the shared functional mailbox (e.g. zaken@gemeente.nl) that the inbound poller ingests and auto-links to cases by [ZAAK-YYYY-NNNNNN] subject tag. Per-user mail accounts stay in Nextcloud Mail. The outbound fields below cover only the mail dossiq sends itself. That is workflow actions and the case email screen.',
				)
			}}
		</NcNoteCard>

		<div class="setting-row">
			<label for="email_imap_host">{{ t('dossiq', 'IMAP host') }}</label>
			<NcInputField
				id="email_imap_host"
				v-model="form.email_imap_host"
				:disabled="!writable || loading"
				placeholder="imap.gemeente.nl" />
		</div>

		<div class="setting-row">
			<label for="email_imap_port">{{ t('dossiq', 'IMAP port') }}</label>
			<NcInputField
				id="email_imap_port"
				v-model="form.email_imap_port"
				type="number"
				:disabled="!writable || loading"
				placeholder="993" />
		</div>

		<div class="setting-row">
			<NcSelect
				v-model="encryptionOption"
				:inputLabel="t('dossiq', 'Encryption')"
				:options="encryptionOptions"
				:disabled="!writable || loading"
				:clearable="false" />
		</div>

		<div class="setting-row">
			<label for="email_imap_username">{{ t('dossiq', 'Username') }}</label>
			<NcInputField
				id="email_imap_username"
				v-model="form.email_imap_username"
				:disabled="!writable || loading"
				placeholder="zaken@gemeente.nl" />
		</div>

		<div class="setting-row">
			<label for="email_imap_password">{{ t('dossiq', 'Password') }}</label>
			<NcInputField
				id="email_imap_password"
				v-model="form.email_imap_password"
				type="password"
				:disabled="!writable || loading"
				:placeholder="passwordPlaceholder" />
			<p class="setting-help">
				{{
					t(
						'dossiq',
						'Stored securely (masked in the API and occ config). Leave as *** to keep the saved password.',
					)
				}}
			</p>
		</div>

		<div class="setting-row">
			<label for="email_imap_folder">{{
				t('dossiq', 'Mailbox folder')
			}}</label>
			<NcInputField
				id="email_imap_folder"
				v-model="form.email_imap_folder"
				:disabled="!writable || loading"
				placeholder="INBOX" />
		</div>

		<div class="setting-row">
			<label for="email_transport">{{
				t('dossiq', 'Transport / source mailbox account')
			}}</label>
			<NcInputField
				id="email_transport"
				v-model="form.email_transport"
				:disabled="!writable || loading"
				:placeholder="
					t('dossiq', 'Nextcloud Mail account or functional mailbox id')
				" />
			<p class="setting-help">
				{{
					t(
						'dossiq',
						'Which Nextcloud Mail account or functional mailbox is the case-correspondence source. No per-user SMTP send credentials are configured here.',
					)
				}}
			</p>
		</div>

		<div class="setting-row">
			<label for="email_poll_interval">{{
				t('dossiq', 'Poll interval (seconds)')
			}}</label>
			<NcInputField
				id="email_poll_interval"
				v-model="form.email_poll_interval"
				type="number"
				:disabled="!writable || loading"
				placeholder="300" />
		</div>

		<div class="setting-row">
			<label for="email_poll_batch_size">{{
				t('dossiq', 'Messages per run')
			}}</label>
			<NcInputField
				id="email_poll_batch_size"
				v-model="form.email_poll_batch_size"
				type="number"
				:disabled="!writable || loading"
				placeholder="50" />
		</div>

		<div class="setting-row">
			<NcSelect
				v-model="fallbackCaseTypeOption"
				:inputLabel="t('dossiq', 'Case type for mail nobody claims')"
				:options="caseTypeOptions"
				:loading="caseTypesLoading"
				:disabled="!writable || loading"
				:placeholder="t('dossiq', 'Leave it in the mailbox')"
				data-testid="email-fallback-case-type" />
			<p class="setting-help">
				{{
					t(
						'dossiq',
						'A mail whose subject carries no case number, or one that no longer exists, becomes a case of this type. Leave it empty and the mail stays unread in the mailbox, which is what happens today.',
					)
				}}
			</p>
		</div>

		<div class="setting-row">
			<label for="email_from_address">{{
				t('dossiq', 'Sender address')
			}}</label>
			<NcInputField
				id="email_from_address"
				v-model="form.email_from_address"
				:disabled="!writable || loading"
				placeholder="zaken@gemeente.nl"
				data-testid="email-from-address" />
			<p class="setting-help">
				{{
					t(
						'dossiq',
						'The address dossiq sends from. Leave it empty and dossiq refuses to send at all.',
					)
				}}
			</p>
		</div>

		<div class="setting-row">
			<label for="email_from_name">{{ t('dossiq', 'Sender name') }}</label>
			<NcInputField
				id="email_from_name"
				v-model="form.email_from_name"
				:disabled="!writable || loading"
				placeholder="Gemeente Voorbeeld"
				data-testid="email-from-name" />
		</div>

		<div class="setting-row">
			<label for="email_recipient_allowlist">{{
				t('dossiq', 'Allowed recipients')
			}}</label>
			<NcInputField
				id="email_recipient_allowlist"
				v-model="form.email_recipient_allowlist"
				:disabled="!writable || loading"
				placeholder="@gemeente.nl, team@gemeente.nl"
				data-testid="email-recipient-allowlist" />
			<p class="setting-help">
				{{
					t(
						'dossiq',
						'List the addresses and domains dossiq may send case mail to. Leave it empty and only your own domain is allowed, taken from the sender address. Write * to allow every recipient.',
					)
				}}
			</p>
		</div>

		<div class="email-settings__actions">
			<NcButton
				variant="primary"
				:disabled="!writable || saving || loading"
				@click="save">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="20" />
				</template>
				{{
					saving
						? t('dossiq', 'Saving…')
						: t('dossiq', 'Save mailbox settings')
				}}
			</NcButton>

			<NcButton
				variant="secondary"
				:disabled="testing || loading"
				@click="testConnection">
				<template #icon>
					<NcLoadingIcon v-if="testing" :size="20" />
				</template>
				{{ t('dossiq', 'Test connection') }}
			</NcButton>
		</div>

		<NcNoteCard v-if="testResult" :type="testResult.type">
			{{ testResult.message }}
		</NcNoteCard>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcInputField,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import { useObjectStore } from '../../store/modules/object.js'

/**
 * Shared case-email mailbox admin settings.
 *
 * Renders ONLY shared-mailbox IMAP + transport fields and a Test-connection
 * button. No per-user SMTP send fields. The password is masked (`***`) on
 * load and only sent when explicitly changed.
 *
 * @spec openspec/specs/case-email-integration/spec.md
 */
export default {
	name: 'EmailSettings',
	components: { NcButton, NcInputField, NcLoadingIcon, NcNoteCard, NcSelect },
	data() {
		return {
			loading: true,
			saving: false,
			testing: false,
			writable: true,
			testResult: null,
			form: {
				email_imap_host: '',
				email_imap_port: '993',
				email_imap_encryption: 'ssl',
				email_imap_username: '',
				email_imap_password: '',
				email_imap_folder: 'INBOX',
				email_transport: '',
				email_poll_interval: '300',
				email_poll_batch_size: '50',
				email_fallback_case_type: '',
				email_from_address: '',
				email_from_name: '',
				email_recipient_allowlist: '',
			},

			caseTypes: [],
			caseTypesLoading: false,

			encryptionOptions: [
				{ id: 'ssl', label: 'SSL/TLS' },
				{ id: 'tls', label: 'STARTTLS' },
				{ id: 'none', label: t('dossiq', 'None') },
			],
		}
	},

	computed: {
		/** @spec openspec/specs/case-email-integration/spec.md */
		encryptionOption: {
			get() {
				return (
					this.encryptionOptions.find(
						(o) => o.id === this.form.email_imap_encryption,
					) || this.encryptionOptions[0]
				)
			},

			set(option) {
				this.form.email_imap_encryption = option ? option.id : 'ssl'
			},
		},

		/**
		 * The case types a mail nobody claims can become.
		 *
		 * @return {Array<object>} The options, with an explicit empty one first.
		 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
		 */
		caseTypeOptions() {
			return this.caseTypes.map((ct) => ({
				id: String(ct.id),
				label: ct.title || ct.identifier || String(ct.id),
			}))
		},

		/**
		 * The case type currently chosen, if the instance names one.
		 *
		 * @return {object|null} The option.
		 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
		 */
		fallbackCaseTypeOption: {
			/**
			 * @return {object|null} The chosen option.
			 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
			 */
			get() {
				return (
					this.caseTypeOptions.find(
						(o) => o.id === this.form.email_fallback_case_type,
					) || null
				)
			},

			/**
			 * @param {object|null} option The chosen option.
			 * @return {void}
			 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
			 */
			set(option) {
				this.form.email_fallback_case_type = option ? option.id : ''
			},
		},

		/** @spec openspec/specs/case-email-integration/spec.md */
		passwordPlaceholder() {
			return this.form.email_imap_password === '***'
				? t('dossiq', 'Saved (masked)')
				: t('dossiq', 'Enter password')
		},
	},

	/**
	 * Read the stored settings, then the case types the picker offers.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 */
	async mounted() {
		await this.load()
		await this.loadCaseTypes()
	},

	methods: {
		/** @spec openspec/specs/case-email-integration/spec.md */
		async load() {
			this.loading = true
			try {
				const response = await fetch(
					generateUrl('/apps/dossiq/api/settings/email'),
					{
						headers: { requesttoken: OC.requestToken },
					},
				)
				if (response.ok) {
					const data = await response.json()
					Object.keys(this.form).forEach((key) => {
						if (data[key] !== undefined && data[key] !== null) {
							this.form[key] = String(data[key])
						}
					})
				}
			} catch {
				// Non-fatal: defaults stay in place if the endpoint is unreachable.
			} finally {
				this.loading = false
			}
		},

		/**
		 * Read the published case types a fallback can be chosen from.
		 *
		 * Only published ones: filing mail into a draft case type would create
		 * cases on a definition still being written.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
		 */
		async loadCaseTypes() {
			this.caseTypesLoading = true
			try {
				const results = await useObjectStore().fetchCollection('caseType', {
					_limit: 100,
					isDraft: false,
				})
				this.caseTypes = results || []
			} catch {
				// Non-fatal: the picker stays empty and the setting keeps its
				// stored value, which is safer than clearing it.
			} finally {
				this.caseTypesLoading = false
			}
		},

		/** @spec openspec/specs/case-email-integration/spec.md */
		async save() {
			this.saving = true
			this.testResult = null
			try {
				const payload = { ...this.form }
				// Never resend the mask back as a real password.
				if (payload.email_imap_password === '') {
					delete payload.email_imap_password
				}
				const response = await fetch(
					generateUrl('/apps/dossiq/api/settings/email'),
					{
						method: 'PUT',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
						},
						body: JSON.stringify(payload),
					},
				)
				if (response.ok) {
					this.testResult = {
						type: 'success',
						message: t('dossiq', 'Mailbox settings saved.'),
					}
					await this.load()
				} else {
					this.testResult = {
						type: 'error',
						message: t('dossiq', 'Could not save mailbox settings.'),
					}
				}
			} catch (error) {
				this.testResult = {
					type: 'error',
					message:
						error.message
						|| t('dossiq', 'Could not save mailbox settings.'),
				}
			} finally {
				this.saving = false
			}
		},

		/** @spec openspec/specs/case-email-integration/spec.md */
		async testConnection() {
			this.testing = true
			this.testResult = null
			try {
				const response = await fetch(
					generateUrl('/apps/dossiq/api/settings/email/test-imap'),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
						},
					},
				)
				const data = await response.json()
				if (data && data.ok === true) {
					this.testResult = {
						type: 'success',
						message: t('dossiq', 'Connection successful.'),
					}
				} else {
					const detail =
						data && (data.detail || data.error)
							? data.detail || data.error
							: t('dossiq', 'unknown error')
					this.testResult = {
						type: 'error',
						message: t('dossiq', 'Connection failed: {detail}', {
							detail,
						}),
					}
				}
			} catch (error) {
				this.testResult = {
					type: 'error',
					message: error.message || t('dossiq', 'Connection failed.'),
				}
			} finally {
				this.testing = false
			}
		},
	},
}
</script>

<style scoped>
.email-settings {
	display: flex;
	flex-direction: column;
	gap: 16px;
	max-width: 600px;
}

.setting-row {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.setting-help {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	margin: 0;
}

.email-settings__actions {
	display: flex;
	gap: 8px;
}
</style>
