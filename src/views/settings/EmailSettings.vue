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
			<NcSelect
				v-model="mailAccountOption"
				:inputLabel="t('dossiq', 'Intake mail account')"
				:options="mailAccountOptions"
				:loading="accountsLoading"
				:disabled="!writable || loading || !mailAvailable"
				:placeholder="t('dossiq', 'Pick the mailbox intake reads')"
				data-testid="email-mail-account" />
			<p class="setting-help">
				{{
					t(
						'dossiq',
						'Nextcloud Mail keeps the account and the password. Pick the mailbox dossiq reads. Dossiq stores no password of its own.',
					)
				}}
			</p>
		</div>

		<NcNoteCard v-if="!mailAvailable" type="warning">
			{{
				t(
					'dossiq',
					'The Mail app is not installed, so intake reads nothing. Your messages stay in the mailbox and none are lost. Install Mail to start reading them.',
				)
			}}
		</NcNoteCard>

		<div class="setting-row">
			<label for="email_imap_folder">{{
				t('dossiq', 'Mailbox folder')
			}}</label>
			<NcInputField
				id="email_imap_folder"
				v-model="form.email_imap_folder"
				:disabled="!writable || loading"
				placeholder="INBOX"
				data-testid="email-intake-folder" />
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
			<NcCheckboxRadioSwitch
				v-model="caseMatchingEnabled"
				type="switch"
				:disabled="!writable || loading"
				data-testid="email-case-matching-enabled">
				{{ t('dossiq', 'Link mail to cases by case number') }}
			</NcCheckboxRadioSwitch>
			<p class="setting-help">
				{{
					t(
						'dossiq',
						'Each user still switches it on for their own mailbox. Until then, nothing is linked.',
					)
				}}
			</p>
		</div>

		<div class="setting-row">
			<label for="email_case_matching_pattern">{{
				t('dossiq', 'Case number pattern')
			}}</label>
			<NcInputField
				id="email_case_matching_pattern"
				v-model="matching.email_case_matching_pattern"
				:disabled="!writable || loading"
				:placeholder="defaultCasePattern"
				data-testid="email-case-matching-pattern" />
			<p class="setting-help">
				{{
					t(
						'dossiq',
						'Leave it empty to match numbers like 2026-0042 and [ZAAK-2026-0042]. The first group in the pattern must capture the case number.',
					)
				}}
			</p>
			<p class="setting-help">
				{{
					t(
						'dossiq',
						'Only the subject and the start of each message are read. A case number further down is missed.',
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

		<div class="setting-row">
			<label for="email_intake_role">{{
				t('dossiq', 'Group that runs intake')
			}}</label>
			<NcInputField
				id="email_intake_role"
				v-model="form.email_intake_role"
				:disabled="!writable || loading"
				placeholder="zaakbehandelaars"
				data-testid="email-intake-role" />
			<p class="setting-help">
				{{
					t(
						'dossiq',
						'Only this group and administrators read the intake log. Only they release a held message. The log keeps the original of every message the mailbox received. Leave it empty and only administrators can read it.',
					)
				}}
			</p>
		</div>

		<div class="setting-row">
			<label for="email_intake_blocklist">{{
				t('dossiq', 'Senders that may not open a case')
			}}</label>
			<NcInputField
				id="email_intake_blocklist"
				v-model="form.email_intake_blocklist"
				:disabled="!writable || loading"
				placeholder="spam@voorbeeld.nl, @voorbeeld.nl"
				data-testid="email-intake-blocklist" />
			<p class="setting-help">
				{{
					t(
						'dossiq',
						'List addresses, or whole domains written as @voorbeeld.nl. A blocked sender opens no case by mail. They can still mail a colleague. The allow half of this list is Nextcloud Mail\u2019s trusted senders.',
					)
				}}
			</p>
		</div>

		<div class="setting-row">
			<label for="email_intake_junk_rules">{{
				t('dossiq', 'Junk rules')
			}}</label>
			<NcInputField
				id="email_intake_junk_rules"
				v-model="form.email_intake_junk_rules"
				:disabled="!writable || loading"
				placeholder="X-Spam-Status: Yes"
				data-testid="email-intake-junk-rules" />
			<p class="setting-help">
				{{
					t(
						'dossiq',
						'Write one rule per line: a header name, a colon, the text to find. Plain text on its own is matched against the subject. A message a rule calls junk is held, never deleted. The intake log names the rule that held it.',
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
	NcCheckboxRadioSwitch,
	NcInputField,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import { useObjectStore } from '../../store/modules/object.js'

/**
 * Shared case-email mailbox admin settings.
 *
 * 🔴 THERE IS NO PASSWORD FIELD, AND THAT IS THE CHANGE. This form used to ask
 * for an IMAP host, a username and a password, and dossiq stored all three.
 * Nextcloud Mail owns the account, the credential and the OAuth connection now,
 * so an administrator picks one of its accounts and names a folder. A form that
 * still offered a password would keep writing one into appconfig, which is why
 * the field goes in the same change that deletes the stored value.
 *
 * The Test-connection button went with the host it dialled. It opened a socket
 * to whatever address was stored and reported reachability, which on an
 * instance-wide setting is a port prober. What replaced it is a listing of the
 * accounts Nextcloud Mail holds.
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */
export default {
	name: 'EmailSettings',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcInputField,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
	},

	data() {
		return {
			loading: true,
			saving: false,
			writable: true,
			testResult: null,
			form: {
				email_mail_account_id: '',
				email_imap_folder: 'INBOX',
				email_transport: '',
				email_poll_interval: '300',
				email_poll_batch_size: '50',
				email_fallback_case_type: '',
				email_from_address: '',
				email_from_name: '',
				email_recipient_allowlist: '',
				email_intake_blocklist: '',
				email_intake_junk_rules: '',
				email_intake_role: '',
			},

			// The Nextcloud Mail accounts intake can be pointed at. Empty is a
			// real answer and not an error: an instance without the Mail app
			// has none, and the form says so rather than offering a picker
			// nothing can fill.
			mailAccounts: [],
			mailAvailable: true,
			accountsLoading: false,

			// Email-to-case matching has its own endpoint, which validates the
			// pattern before anything is stored.
			matching: {
				email_case_matching_enabled: 'no',
				email_case_matching_pattern: '',
			},

			// Shown as the placeholder, so an empty field says what it means.
			// Mirrors CaseNumberRecognizer::DEFAULT_PATTERN.
			defaultCasePattern:
				'/(?<![\\w-])(?:\\[)?(?:[A-Z]{2,10}-)?((?:19|20)\\d{2}-\\d{4,6})(?:\\])?(?![\\w-])/u',

			caseTypes: [],
			caseTypesLoading: false,

		}
	},

	computed: {
		/**
		 * The Nextcloud Mail accounts an administrator can point intake at.
		 *
		 * @return {Array<object>} The options.
		 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
		 */
		mailAccountOptions() {
			return this.mailAccounts.map((account) => ({
				id: String(account.id),
				label: account.email
					? `${account.name} (${account.email})`
					: String(account.name || account.id),
			}))
		},

		/**
		 * The account currently picked, if the instance names one.
		 *
		 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
		 */
		mailAccountOption: {
			/**
			 * @return {object|null} The chosen option.
			 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
			 */
			get() {
				return (
					this.mailAccountOptions.find(
						(o) => o.id === String(this.form.email_mail_account_id),
					) || null
				)
			},

			/**
			 * @param {object|null} option The chosen option.
			 * @return {void}
			 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
			 */
			set(option) {
				this.form.email_mail_account_id = option ? option.id : ''
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

		/**
		 * The instance toggle as a switch; stored as `yes` or `no`.
		 *
		 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
		 */
		caseMatchingEnabled: {
			/**
			 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
			 * @return {boolean} Whether matching is on.
			 */
			get() {
				return this.matching.email_case_matching_enabled === 'yes'
			},

			/**
			 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
			 * @param {boolean} value Whether matching is on.
			 * @return {void}
			 */
			set(value) {
				this.matching.email_case_matching_enabled = value ? 'yes' : 'no'
			},
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
		await this.loadMatching()
		await this.loadCaseTypes()
		await this.loadMailAccounts()
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
		 * Read the email-to-case matching switch and pattern.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
		 */
		async loadMatching() {
			try {
				const response = await fetch(
					generateUrl(
						'/apps/dossiq/api/settings/email-case-matching/instance',
					),
					{ headers: { requesttoken: OC.requestToken } },
				)
				if (response.ok) {
					const data = await response.json()
					Object.keys(this.matching).forEach((key) => {
						if (data[key] !== undefined && data[key] !== null) {
							this.matching[key] = String(data[key])
						}
					})
				}
			} catch {
				// Non-fatal: the switch stays off, which is the stored default.
			}
		},

		/**
		 * Save the matching switch and pattern first, so a refused pattern
		 * stops the whole save before anything is written.
		 *
		 * @return {Promise<boolean>} Whether the matching settings were saved.
		 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
		 */
		async saveMatching() {
			const response = await fetch(
				generateUrl(
					'/apps/dossiq/api/settings/email-case-matching/instance',
				),
				{
					method: 'PUT',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify(this.matching),
				},
			)
			if (response.status === 400) {
				this.testResult = {
					type: 'error',
					message: t(
						'dossiq',
						'This pattern cannot find case numbers. Check that it is valid and has a capture group.',
					),
				}
				return false
			}
			if (!response.ok) {
				this.testResult = {
					type: 'error',
					message: t('dossiq', 'Could not save mailbox settings.'),
				}
				return false
			}
			return true
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
				if ((await this.saveMatching()) === false) {
					return
				}
				// Every value here is an id, a folder name or a policy. There
				// is no mask to preserve and no secret to withhold, because
				// dossiq no longer holds one.
				const payload = { ...this.form }
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

		/**
		 * Read the Nextcloud Mail accounts intake can be pointed at.
		 *
		 * An empty list is a real answer. When the Mail app is absent the form
		 * says intake is unavailable rather than offering an empty picker that
		 * looks like a mailbox nobody configured.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
		 */
		async loadMailAccounts() {
			this.accountsLoading = true
			try {
				const response = await fetch(
					generateUrl('/apps/dossiq/api/settings/email/mail-accounts'),
					{ headers: { 'OCS-APIRequest': 'true' } },
				)
				const data = await response.json()
				this.mailAvailable = data?.available !== false
				this.mailAccounts = data?.accounts || []
			} catch {
				// Non-fatal: the picker stays empty and the stored account id
				// keeps working, which is safer than clearing it.
				this.mailAccounts = []
			} finally {
				this.accountsLoading = false
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
