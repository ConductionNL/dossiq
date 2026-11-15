<!--
SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<NcDialog
		v-if="isOpen"
		:name="t('dossiq', 'Ask for missing information')"
		size="normal"
		data-testid="aanvulling-vragen-modal"
		@close="$emit('close')">
		<div class="aanvulling-vragen">
			<NcNoteCard type="info" data-testid="aanvulling-vragen-notice">
				{{ t('dossiq', 'The summary and the list of missing items go to the applicant. The term is suspended once the letter has gone out.') }}
			</NcNoteCard>
			<NcTextArea
				:modelValue="form.items"
				:label="t('dossiq', 'Missing items, one per line')"
				:error="refusal === 'items'"
				rows="5"
				data-testid="aanvulling-vragen-items"
				@update:modelValue="(v) => (form.items = v)" />
			<NcTextField
				:modelValue="form.recipient"
				:label="t('dossiq', 'Recipient (email address)')"
				:error="refusal === 'recipient'"
				type="email"
				data-testid="aanvulling-vragen-recipient"
				@update:modelValue="(v) => (form.recipient = v)" />
			<NcTextField
				:modelValue="form.durationDays"
				:label="durationLabel"
				:error="refusal === 'durationDays'"
				type="number"
				min="1"
				:max="maxDays > 0 ? String(maxDays) : undefined"
				data-testid="aanvulling-vragen-days"
				@update:modelValue="(v) => (form.durationDays = String(v))" />
			<NcSelect
				v-if="pauseReasons.length > 0"
				:modelValue="selectedReason"
				:options="pauseReasons"
				label="name"
				:inputLabel="t('dossiq', 'Pause reason')"
				data-testid="aanvulling-vragen-reason"
				@update:modelValue="chooseReason" />
			<NcTextArea
				:modelValue="form.rationale"
				:label="t('dossiq', 'Rationale')"
				:error="refusal === 'rationale'"
				rows="3"
				data-testid="aanvulling-vragen-rationale"
				@update:modelValue="(v) => (form.rationale = v)" />
			<div v-if="error" role="alert">
				<NcNoteCard type="error" data-testid="aanvulling-vragen-error">
					{{ error }}
				</NcNoteCard>
			</div>
			<div class="aanvulling-vragen__actions">
				<NcButton
					variant="primary"
					:disabled="sending"
					data-testid="aanvulling-vragen-send"
					@click="send">
					{{ sending ? t('dossiq', 'Sending…') : t('dossiq', 'Send request') }}
				</NcButton>
				<NcButton @click="$emit('close')">
					{{ t('dossiq', 'Cancel') }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcDialog,
	NcNoteCard,
	NcSelect,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import { requestInformation } from '../services/caseTermsApi.js'
import { resolveCaseId } from '../utils/resolveCaseId.js'

/** The term the applicant gets when nothing else is said, the endpoint's own default. */
const DEFAULT_DAYS = 14

/**
 * The lines of a textarea that say something, trimmed.
 *
 * @param {string} text The textarea value.
 * @return {Array<string>} One entry per non-empty line.
 */
export function missingItemsOf(text) {
	return String(text || '')
		.split('\n')
		.map((line) => line.trim())
		.filter((line) => line !== '')
}

/**
 * The longest suspension a case type declares, 0 when it declares none.
 *
 * @param {object|null} caseType The case type object.
 * @return {number} The maximum in days.
 */
export function maxSuspensionDaysOf(caseType) {
	const days = Number.parseInt(caseType?.maxSuspensionDays ?? 0, 10)
	return Number.isFinite(days) && days > 0 ? days : 0
}

/**
 * The sentence a refused request answers with: the server's own words.
 *
 * @param {object} body The response body.
 * @return {string} The sentence, or a general one when the body names none.
 */
export function refusalSentenceOf(body) {
	const sentence = String(body?.message || body?.error || '').trim()
	return sentence !== ''
		? sentence
		: t('dossiq', 'The request could not be made.')
}

/**
 * The handler asks the applicant for what is missing, from the case page.
 *
 * Asking and suspending the term are ONE act on the server (Awb 4:5): the
 * letter goes out, the statutory term is suspended and the
 * `aanvullingsverzoek` record is written, or none of it happens. This form
 * only collects the ask and shows what came back. A refusal (no running term,
 * a request already open, a letter that did not go out) is the server's own
 * sentence, and the form reports no success of its own: on a sent request it
 * emits `sent` and closes, and the case page shows the suspended term.
 *
 * @spec openspec/changes/handler-asks-the-applicant-for-missing-information/specs/termijn-pause-extension/spec.md#requirement-the-handler-asks-for-missing-information-from-the-case-page-req-avr-05
 */
export default {
	name: 'AanvullingVragenModal',
	components: {
		NcButton,
		NcDialog,
		NcNoteCard,
		NcSelect,
		NcTextArea,
		NcTextField,
	},

	props: {
		/**
		 * The case. May arrive as the unresolved `@objectId` token, since
		 * `open-modal` forwards props verbatim; the route is read then.
		 */
		caseId: { type: String, default: '' },
		/** Whether the dialog is showing. */
		open: { type: Boolean, default: false },
	},

	emits: ['close', 'sent'],

	data() {
		return {
			form: {
				items: '',
				recipient: '',
				durationDays: String(DEFAULT_DAYS),
				pauseReason: '',
				rationale: '',
			},

			pauseReasons: [],
			maxDays: 0,
			sending: false,
			refusal: '',
			error: '',
		}
	},

	computed: {
		/**
		 * The case id, from the prop or else from the route.
		 *
		 * @return {string} The case id, or ''.
		 * @spec openspec/changes/handler-asks-the-applicant-for-missing-information/specs/termijn-pause-extension/spec.md#requirement-the-handler-asks-for-missing-information-from-the-case-page-req-avr-05
		 */
		resolvedCaseId() {
			return resolveCaseId(this.caseId, this.$route)
		},

		/**
		 * Whether the dialog is on screen.
		 *
		 * @return {boolean}
		 */
		isOpen() {
			return this.open === true
		},

		/**
		 * The term field's label, naming the case type's limit when it has one.
		 *
		 * @return {string}
		 */
		durationLabel() {
			if (this.maxDays > 0) {
				return t('dossiq', 'Term for the applicant in days (at most {max})', { max: this.maxDays })
			}
			return t('dossiq', 'Term for the applicant in days')
		},

		/**
		 * The pause reason picked, as its option.
		 *
		 * @return {object|null}
		 */
		selectedReason() {
			return this.pauseReasons.find((reason) => reason.key === this.form.pauseReason) || null
		},
	},

	watch: {
		isOpen: {
			immediate: true,
			/**
			 * Read the pause reasons and the suspension limit when the dialog opens.
			 *
			 * @param {boolean} opened Whether the dialog is showing.
			 * @return {void}
			 */
			handler(opened) {
				if (opened === true) {
					this.loadCaseTypeLimits()
				}
			},
		},
	},

	methods: {
		t,

		/**
		 * Read what the case type allows: its pause reasons and its longest suspension.
		 *
		 * A read that fails leaves the form usable: the server checks the
		 * limit and the reason itself and refuses with its own sentence.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/termijn-pause-extension/spec.md#requirement-the-declared-suspension-and-extension-lengths-are-enforced-req-term-066
		 */
		async loadCaseTypeLimits() {
			if (this.resolvedCaseId === '') {
				return
			}
			let caseTypeId = ''
			try {
				const { data } = await axios.get(
					generateUrl(`/apps/dossiq/api/case/${encodeURIComponent(this.resolvedCaseId)}/lifecycle`),
				)
				this.pauseReasons = Array.isArray(data?.pauseReasons) ? data.pauseReasons : []
				caseTypeId = String(data?.caseType || '')
			} catch {
				this.pauseReasons = []
			}
			if (caseTypeId === '') {
				return
			}
			try {
				const { data } = await axios.get(
					generateUrl(`/apps/openregister/api/objects/dossiq/caseType/${encodeURIComponent(caseTypeId)}`),
				)
				this.maxDays = maxSuspensionDaysOf(data)
			} catch {
				this.maxDays = 0
			}
		},

		/**
		 * Take a pause reason, and its default term when the handler has not changed the term.
		 *
		 * @param {object|null} reason The option picked.
		 * @return {void}
		 */
		chooseReason(reason) {
			this.form.pauseReason = String(reason?.key || '')
			if (Number.isInteger(reason?.defaultDays) && this.form.durationDays === String(DEFAULT_DAYS)) {
				this.form.durationDays = String(reason.defaultDays)
			}
		},

		/**
		 * The request as the endpoint takes it.
		 *
		 * @return {object} The payload.
		 * @spec openspec/changes/handler-asks-the-applicant-for-missing-information/specs/termijn-pause-extension/spec.md#requirement-the-handler-asks-for-missing-information-from-the-case-page-req-avr-05
		 */
		payload() {
			return {
				items: missingItemsOf(this.form.items),
				recipient: this.form.recipient.trim(),
				durationDays: Number.parseInt(this.form.durationDays, 10),
				pauseReason: this.form.pauseReason,
				rationale: this.form.rationale.trim(),
			}
		},

		/**
		 * What stops the form being sent, before it is sent.
		 *
		 * @param {object} payload The request.
		 * @return {{field: string, sentence: string}|null} The field and sentence, or null.
		 */
		formRefusal(payload) {
			if (payload.items.length === 0) {
				return { field: 'items', sentence: t('dossiq', 'Name at least one thing the applicant has to send.') }
			}
			if (payload.recipient === '') {
				return { field: 'recipient', sentence: t('dossiq', 'Name who the request goes to.') }
			}
			if (Number.isInteger(payload.durationDays) === false || payload.durationDays < 1) {
				return { field: 'durationDays', sentence: t('dossiq', 'Give the applicant a term of at least one day.') }
			}
			if (this.maxDays > 0 && payload.durationDays > this.maxDays) {
				return {
					field: 'durationDays',
					sentence: t('dossiq', 'This case type allows a suspension of at most {max} days.', { max: this.maxDays }),
				}
			}
			if (payload.rationale === '') {
				return { field: 'rationale', sentence: t('dossiq', 'Say why the information is needed.') }
			}
			return null
		},

		/**
		 * Send the request; show the server's sentence when it is refused.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/handler-asks-the-applicant-for-missing-information/specs/termijn-pause-extension/spec.md#requirement-the-handler-asks-for-missing-information-from-the-case-page-req-avr-05
		 */
		async send() {
			const payload = this.payload()
			const refused = this.formRefusal(payload)
			this.refusal = refused?.field || ''
			this.error = refused?.sentence || ''
			if (refused !== null) {
				return
			}

			this.sending = true
			const answer = await requestInformation(this.resolvedCaseId, payload)
			this.sending = false

			if (answer.ok !== true) {
				this.error = refusalSentenceOf(answer.body)
				return
			}
			this.$emit('sent', answer.body)
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.aanvulling-vragen {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	padding-bottom: calc(var(--default-grid-baseline) * 2);
}

.aanvulling-vragen__actions {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);
	justify-content: flex-end;
}
</style>
