<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<NcDialog
		v-if="open === true"
		:name="t('dossiq', 'Ask a partner')"
		size="normal"
		data-testid="partner-ask-dialog"
		@close="$emit('close')">
		<div class="partner-ask">
			<p class="partner-ask__intro">
				{{ t('dossiq', 'The partner finds this task in their portal inbox and answers it there.') }}
			</p>
			<NcTextField
				:modelValue="form.title"
				:label="t('dossiq', 'What is the partner asked for?')"
				data-testid="partner-ask-title"
				@update:modelValue="(v) => (form.title = v)" />
			<NcTextArea
				:modelValue="form.description"
				:label="t('dossiq', 'Explanation')"
				rows="5"
				data-testid="partner-ask-description"
				@update:modelValue="(v) => (form.description = v)" />
			<NcTextField
				:modelValue="form.dueAt"
				type="date"
				:label="t('dossiq', 'Wanted by')"
				data-testid="partner-ask-due"
				@update:modelValue="(v) => (form.dueAt = v)" />
			<NcTextField
				:modelValue="form.kvk"
				:label="t('dossiq', 'Partner\'s KvK number')"
				inputmode="numeric"
				data-testid="partner-ask-kvk"
				@update:modelValue="(v) => (form.kvk = v)" />
			<NcTextField
				:modelValue="form.email"
				type="email"
				:label="t('dossiq', 'Partner\'s e-mail address')"
				data-testid="partner-ask-email"
				@update:modelValue="(v) => (form.email = v)" />
			<NcTextField
				:modelValue="form.name"
				:label="t('dossiq', 'Partner\'s name')"
				data-testid="partner-ask-name"
				@update:modelValue="(v) => (form.name = v)" />
			<NcCheckboxRadioSwitch
				:modelValue="form.pdfRequired"
				data-testid="partner-ask-pdf"
				@update:modelValue="(v) => (form.pdfRequired = v)">
				{{ t('dossiq', 'The partner must upload a PDF') }}
			</NcCheckboxRadioSwitch>
			<div v-if="error" role="alert">
				<NcNoteCard type="error">
					{{ error }}
				</NcNoteCard>
			</div>
			<div class="partner-ask__actions">
				<NcButton
					variant="primary"
					:disabled="sending"
					data-testid="partner-ask-send"
					@click="send">
					{{ sending ? t('dossiq', 'Sending…') : t('dossiq', 'Send the ask') }}
				</NcButton>
				<NcButton @click="$emit('close')">
					{{ t('dossiq', 'Cancel') }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcNoteCard,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import { askPayload, askRefusal, emptyAsk, sendAsk } from '../services/partnerAsk.js'
import { resolveCaseId } from '../utils/resolveCaseId.js'

/**
 * The handler asks a partner for something from the case: portaliq's
 * `ask-partner` action, offered on the case page. A refusal keeps the dialog
 * open with the reason; `sent` carries portaliq's answer.
 *
 * @spec openspec/specs/partner-tasks-in-the-portal/spec.md (portaliq, REQ-PTP-002)
 */
export default {
	name: 'PartnerAskDialog',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcNoteCard,
		NcTextArea,
		NcTextField,
	},

	props: {
		/** The case; may arrive as the unresolved `@objectId` token, then the route answers. */
		caseId: { type: String, default: '' },
		/** Whether the dialog is showing. */
		open: { type: Boolean, default: false },
	},

	emits: ['close', 'sent'],

	data() {
		return { form: emptyAsk(), sending: false, error: '' }
	},

	computed: {
		/**
		 * The case id, from the prop or else from the route.
		 *
		 * @return {string}
		 */
		resolvedCaseId() {
			return resolveCaseId(this.caseId, this.$route)
		},
	},

	methods: {
		t,

		/**
		 * Check the form and send the ask.
		 *
		 * @return {Promise<void>}
		 */
		async send() {
			this.error = askRefusal(this.form, this.resolvedCaseId)
			if (this.error !== '') {
				return
			}
			this.sending = true
			const outcome = await sendAsk(askPayload(this.form, this.resolvedCaseId))
			this.sending = false
			if (outcome.ok === false) {
				this.error = outcome.error
				return
			}
			this.$emit('sent', outcome.data)
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.partner-ask {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 2);
}

.partner-ask__intro {
	color: var(--color-text-maxcontrast);
}

.partner-ask__actions {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);
	justify-content: flex-end;
}
</style>
