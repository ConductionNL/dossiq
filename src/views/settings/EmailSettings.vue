<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<div class="email-settings">
		<h3>{{ t('procest', 'Email integration') }}</h3>
		<p>{{ t('procest', 'Configure SMTP and IMAP settings for case email integration.') }}</p>

		<div class="email-settings__section">
			<h4>{{ t('procest', 'Outbound (SMTP)') }}</h4>

			<div class="form-group">
				<label>{{ t('procest', 'Transport') }}</label>
				<NcSelect
					v-model="form.email_transport"
					:options="transportOptions"
					label="label"
					track-by="value"
					:input-label="t('procest', 'Transport')" />
			</div>

			<div class="form-group">
				<label>{{ t('procest', 'SMTP host') }}</label>
				<NcTextField
					:value="form.email_smtp_host"
					:label="t('procest', 'SMTP host')"
					:placeholder="t('procest', 'smtp.example.nl')"
					@update:value="v => form.email_smtp_host = v" />
			</div>

			<div class="form-group">
				<label>{{ t('procest', 'SMTP port') }}</label>
				<NcTextField
					:value="form.email_smtp_port"
					:label="t('procest', 'SMTP port')"
					:placeholder="t('procest', '587')"
					@update:value="v => form.email_smtp_port = v" />
			</div>

			<div class="form-group">
				<label>{{ t('procest', 'SMTP user') }}</label>
				<NcTextField
					:value="form.email_smtp_user"
					:label="t('procest', 'SMTP user')"
					@update:value="v => form.email_smtp_user = v" />
			</div>

			<div class="form-group">
				<label>{{ t('procest', 'SMTP password') }}</label>
				<NcPasswordField
					:value="form.email_smtp_password"
					:label="t('procest', 'SMTP password')"
					@update:value="v => form.email_smtp_password = v" />
			</div>

			<div class="form-group">
				<label>{{ t('procest', 'From address') }}</label>
				<NcTextField
					:value="form.email_from_address"
					:label="t('procest', 'From address')"
					:placeholder="t('procest', 'noreply@gemeente.nl')"
					@update:value="v => form.email_from_address = v" />
			</div>

			<div class="form-group">
				<label>{{ t('procest', 'From name') }}</label>
				<NcTextField
					:value="form.email_from_name"
					:label="t('procest', 'From name')"
					:placeholder="t('procest', 'Gemeente Procest')"
					@update:value="v => form.email_from_name = v" />
			</div>
		</div>

		<div class="email-settings__section">
			<h4>{{ t('procest', 'Inbound (IMAP)') }}</h4>

			<div class="form-group">
				<label>{{ t('procest', 'IMAP host') }}</label>
				<NcTextField
					:value="form.email_imap_host"
					:label="t('procest', 'IMAP host')"
					:placeholder="t('procest', 'imap.example.nl')"
					@update:value="v => form.email_imap_host = v" />
			</div>

			<div class="form-group">
				<label>{{ t('procest', 'IMAP port') }}</label>
				<NcTextField
					:value="form.email_imap_port"
					:label="t('procest', 'IMAP port')"
					:placeholder="t('procest', '993')"
					@update:value="v => form.email_imap_port = v" />
			</div>

			<div class="form-group">
				<label>{{ t('procest', 'IMAP user') }}</label>
				<NcTextField
					:value="form.email_imap_user"
					:label="t('procest', 'IMAP user')"
					@update:value="v => form.email_imap_user = v" />
			</div>

			<div class="form-group">
				<label>{{ t('procest', 'IMAP password') }}</label>
				<NcPasswordField
					:value="form.email_imap_password"
					:label="t('procest', 'IMAP password')"
					@update:value="v => form.email_imap_password = v" />
			</div>

			<div class="form-group">
				<label>{{ t('procest', 'Poll interval (seconds)') }}</label>
				<NcTextField
					:value="form.email_poll_interval"
					:label="t('procest', 'Poll interval')"
					:placeholder="t('procest', '300')"
					@update:value="v => form.email_poll_interval = v" />
			</div>

			<div class="form-group">
				<label>{{ t('procest', 'Batch size') }}</label>
				<NcTextField
					:value="form.email_poll_batch_size"
					:label="t('procest', 'Batch size')"
					:placeholder="t('procest', '50')"
					@update:value="v => form.email_poll_batch_size = v" />
			</div>
		</div>

		<div class="email-settings__actions">
			<NcButton type="primary" :loading="saving" @click="save">
				{{ t('procest', 'Save') }}
			</NcButton>
			<NcButton type="secondary" :loading="testing" @click="testConnection">
				{{ t('procest', 'Send test email') }}
			</NcButton>
		</div>

		<div v-if="testResult" class="email-settings__test-result" :class="testResult.success ? 'success' : 'error'">
			{{ testResult.success
				? t('procest', 'Test email sent successfully.')
				: t('procest', 'Test failed: {error}', { error: testResult.error }) }}
		</div>
	</div>
</template>

<script>
import { NcButton, NcTextField, NcPasswordField, NcSelect } from '@nextcloud/vue'
import { t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'EmailSettingsView',
	components: { NcButton, NcTextField, NcPasswordField, NcSelect },
	data() {
		return {
			form: {
				email_smtp_host: '',
				email_smtp_port: '587',
				email_smtp_user: '',
				email_smtp_password: '',
				email_smtp_encryption: 'tls',
				email_imap_host: '',
				email_imap_port: '993',
				email_imap_user: '',
				email_imap_password: '',
				email_transport: null,
				email_from_address: '',
				email_from_name: 'Procest',
				email_poll_interval: '300',
				email_poll_batch_size: '50',
			},
			transportOptions: [
				{ label: t('procest', 'Standalone SMTP/IMAP'), value: 'smtp' },
				{ label: t('procest', 'Nextcloud Mail account'), value: 'nextcloud_mail' },
			],
			saving: false,
			testing: false,
			testResult: null,
		}
	},
	mounted() {
		this.loadSettings()
	},
	methods: {
		async loadSettings() {
			try {
				const url = generateUrl('/apps/procest/api/settings/email')
				const { data } = await axios.get(url)
				Object.assign(this.form, data)
			} catch (e) {
				// eslint-disable-next-line no-console
				console.error('[procest] Failed to load email settings', e)
			}
		},
		async save() {
			this.saving = true
			try {
				const url = generateUrl('/apps/procest/api/settings/email')
				const payload = { ...this.form, email_transport: this.form.email_transport?.value || '' }
				await axios.put(url, payload)
			} catch (e) {
				// eslint-disable-next-line no-console
				console.error('[procest] Failed to save email settings', e)
			} finally {
				this.saving = false
			}
		},
		async testConnection() {
			this.testing = true
			this.testResult = null
			try {
				const url = generateUrl('/apps/procest/api/settings/email/test-smtp')
				const { data } = await axios.post(url, {})
				this.testResult = data
			} catch (e) {
				this.testResult = { success: false, error: e.message }
			} finally {
				this.testing = false
			}
		},
	},
}
</script>

<style scoped>
.email-settings__section {
	margin-bottom: 24px;
}

.form-group {
	margin-bottom: 12px;
}

.form-group label {
	display: block;
	font-weight: 600;
	font-size: 0.875rem;
	margin-bottom: 4px;
}

.email-settings__actions {
	display: flex;
	gap: 8px;
	margin-top: 16px;
}

.email-settings__test-result {
	margin-top: 12px;
	padding: 8px 12px;
	border-radius: var(--border-radius);
}

.email-settings__test-result.success {
	background: var(--color-success-light, #e8f5e9);
	color: var(--color-success, #388e3c);
}

.email-settings__test-result.error {
	background: var(--color-error-light, #ffebee);
	color: var(--color-error);
}
</style>
