<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Record a consent on the case: who gave it, to sharing with whom, for which
  purpose, from and until when.

  It writes one `toestemming` object, the record the share paths already read
  (CaseTransferConsentGate): a share with a party this consent names is let
  through while the consent covers the day, and refused once it lapses or is
  revoked. Revoking is the consents widget's row action, not this dialog.

  It reads the case from the ROUTE when no case id is passed, as the other
  case dialogs do.

  @spec openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md
-->
<template>
	<CnDialog
		:name="t('dossiq', 'Record consent')"
		data-testid="consent-record-dialog"
		@closing="$emit('close')">
		<div class="consent-record">
			<NcTextField
				v-model="grantedByName"
				data-testid="consent-record-name"
				:label="t('dossiq', 'Given by (name)')" />
			<NcTextField
				v-model="grantedByBsn"
				data-testid="consent-record-bsn"
				inputmode="numeric"
				:error="problem === 'bsn' && grantedByBsn !== ''"
				:label="t('dossiq', 'Given by (BSN)')" />
			<NcTextField
				v-model="parties"
				data-testid="consent-record-party"
				:label="t('dossiq', 'Sharing with (party)')"
				:helperText="t('dossiq', 'Separate several parties with a comma.')" />
			<NcTextField
				v-model="purpose"
				data-testid="consent-record-purpose"
				:label="t('dossiq', 'Purpose')" />
			<NcDateTimePicker
				v-model="from"
				data-testid="consent-record-from"
				type="date"
				:label="t('dossiq', 'Valid from')" />
			<NcDateTimePicker
				v-model="until"
				data-testid="consent-record-until"
				type="date"
				:label="t('dossiq', 'Valid until (optional)')" />
			<p
				v-if="problem === 'until'"
				class="consent-record__error"
				role="alert">
				{{ t('dossiq', 'The end date lies before the start date.') }}
			</p>
			<p
				v-if="error"
				class="consent-record__error"
				data-testid="consent-record-error"
				role="alert">
				{{ error }}
			</p>
		</div>

		<template #actions>
			<NcButton data-testid="consent-record-cancel" @click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				data-testid="consent-record-confirm"
				variant="primary"
				:disabled="!canConfirm"
				@click="confirm">
				{{ t('dossiq', 'Record consent') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTimePicker from '@nextcloud/vue/components/NcDateTimePicker'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import {
	CONSENT_REGISTER,
	CONSENT_SCHEMA,
	consentFormProblem,
	consentPayload,
} from '../utils/consentRecord.js'

const PAGE_REFRESH = 'cn:page:refresh'

export default {
	name: 'ConsentRecordDialog',

	components: {
		CnDialog,
		NcButton,
		NcDateTimePicker,
		NcTextField,
	},

	props: {
		/** The case the consent is recorded on; the route answers when empty. */
		caseId: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],

	data() {
		return {
			grantedByName: '',
			grantedByBsn: '',
			parties: '',
			purpose: '',
			from: new Date(),
			until: null,
			busy: false,
			error: '',
		}
	},

	computed: {
		/**
		 * @return {string} The case this dialog records on.
		 * @spec openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md
		 */
		targetCaseId() {
			const passed = this.caseId && !this.caseId.startsWith('@') ? this.caseId : ''
			return passed || String(this.$route?.params?.id ?? '')
		},

		/**
		 * @return {object} The form, in the shape the helpers read.
		 * @spec openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md
		 */
		form() {
			return {
				grantedByName: this.grantedByName,
				grantedByBsn: this.grantedByBsn,
				parties: this.parties,
				purpose: this.purpose,
				from: this.from,
				until: this.until,
			}
		},

		/**
		 * @return {string} What still keeps the consent from being saved, or ''.
		 * @spec openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md
		 */
		problem() {
			return consentFormProblem(this.form)
		},

		/**
		 * @return {boolean} Whether the consent may be saved.
		 * @spec openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md
		 */
		canConfirm() {
			return this.busy === false && this.targetCaseId !== '' && this.problem === ''
		},
	},

	methods: {
		t,

		/**
		 * Save the consent as a `toestemming` object on the case. A refusal is
		 * shown with the server's sentence rather than closing the dialog.
		 *
		 * @return {Promise<void>} Nothing.
		 * @spec openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md
		 */
		async confirm() {
			if (!this.canConfirm) {
				return
			}
			this.busy = true
			this.error = ''
			try {
				await axios.post(
					generateUrl(`/apps/openregister/api/objects/${CONSENT_REGISTER}/${CONSENT_SCHEMA}`),
					consentPayload(this.form, this.targetCaseId),
				)
				emit(PAGE_REFRESH)
				this.$emit('close')
			} catch (e) {
				this.error = e?.response?.data?.message
					|| e?.response?.data?.error
					|| t('dossiq', 'The consent could not be recorded.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.consent-record {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 0;
}

.consent-record__error {
	color: var(--color-error);
	margin: 0;
}
</style>
