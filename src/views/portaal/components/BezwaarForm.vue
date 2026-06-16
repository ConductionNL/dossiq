<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  -
  - Bezwaar (objection) intake form for the "Mijn gemeente" portal
  - (zaakportaal-mijngemeente, REQ-POR-008). On mount it probes the statutory
  - 6-week Awb deadline via POST /api/portaal/objections/validate-deadline; when
  - the deadline has passed the form is replaced by an explanatory notice and a
  - link to messaging. Submission posts to POST /api/portaal/objections, where
  - the deadline and ownership are re-validated server-side (authoritative).
  - Validation + body shaping is delegated to utils/portaalForms.js.
-->
<template>
	<section class="zp-bezwaar" data-testid="portaal-bezwaar-form">
		<h3>{{ t('procest', 'File an objection') }}</h3>

		<div v-if="probing" class="zp-bezwaar__state">
			<NcLoadingIcon :size="24" />
		</div>

		<div v-else-if="deadlinePassed"
			class="zp-bezwaar__notice"
			role="alert"
			data-testid="portaal-bezwaar-expired">
			<p>
				{{ t('procest', 'The deadline for objection (until {deadline}) has passed. Please contact the municipality for more information.', { deadline: deadline }) }}
			</p>
			<NcButton type="secondary" @click="$emit('request-message')">
				{{ t('procest', 'Ask for an explanation') }}
			</NcButton>
		</div>

		<form v-else
			class="zp-bezwaar__body"
			data-testid="portaal-bezwaar-fields"
			@submit.prevent="onSubmit">
			<p class="zp-bezwaar__meta">
				{{ t('procest', 'Objection against: {subject}', { subject: decisionTitle || caseReference }) }}
			</p>
			<p v-if="deadline" class="zp-bezwaar__meta">
				{{ t('procest', 'Deadline: {deadline} ({days} days remaining)', { deadline: deadline, days: daysRemaining }) }}
			</p>

			<label for="zp-bezwaar-motivering" class="zp-bezwaar__label">
				{{ t('procest', 'Grounds for objection') }}
			</label>
			<textarea id="zp-bezwaar-motivering"
				v-model="motivering"
				rows="6"
				class="zp-bezwaar__textarea"
				data-testid="portaal-bezwaar-motivering"
				:placeholder="t('procest', 'Explain why you disagree with the decision…')" />

			<NcCheckboxRadioSwitch :checked.sync="consent" data-testid="portaal-bezwaar-consent">
				{{ t('procest', 'I agree that my data may be used for this procedure') }}
			</NcCheckboxRadioSwitch>

			<p v-if="fieldError"
				class="zp-bezwaar__field-error"
				role="alert"
				data-testid="portaal-bezwaar-validation">
				{{ fieldError }}
			</p>

			<div class="zp-bezwaar__actions">
				<NcButton type="primary"
					native-type="submit"
					:disabled="submitting"
					data-testid="portaal-bezwaar-submit">
					{{ submitting ? t('procest', 'Submitting…') : t('procest', 'Submit objection') }}
				</NcButton>
				<p v-if="successMessage"
					class="zp-bezwaar__status"
					role="status"
					data-testid="portaal-bezwaar-success">
					{{ successMessage }}
				</p>
			</div>
		</form>
	</section>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { validateBezwaar, buildBezwaarPayload } from '../../../utils/portaalForms.js'

export default {
	name: 'BezwaarForm',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
	},
	props: {
		/** The case id the objection is filed against. */
		caseId: {
			type: String,
			required: true,
		},
		/** The human-readable case reference. */
		caseReference: {
			type: String,
			default: '',
		},
		/** The decision date (ISO yyyy-mm-dd) the 6-week termijn starts from. */
		decisionDate: {
			type: String,
			default: '',
		},
		/** Optional decision id and title. */
		decisionId: {
			type: String,
			default: '',
		},
		decisionTitle: {
			type: String,
			default: '',
		},
	},
	data() {
		return {
			probing: true,
			deadline: '',
			daysRemaining: 0,
			binnenTermijn: null,
			motivering: '',
			consent: false,
			fieldError: '',
			submitting: false,
			successMessage: '',
		}
	},
	computed: {
		deadlinePassed() {
			return this.binnenTermijn === false
		},
	},
	mounted() {
		this.probeDeadline()
	},
	methods: {
		/**
		 * Probe the statutory bezwaar deadline (REQ-POR-008).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/zaakportaal-mijngemeente/spec.md
		 */
		async probeDeadline() {
			this.probing = true
			try {
				const url = generateUrl('/apps/procest/api/portaal/objections/validate-deadline')
				const { data } = await axios.post(url, { decisionDate: this.decisionDate })
				this.deadline = (data && data.deadline) || ''
				this.daysRemaining = (data && data.dagenResterend) || 0
				this.binnenTermijn = !!(data && data.binnenTermijn)
			} catch (e) {
				// On probe failure leave the form open; the authoritative check
				// runs again on submit and will reject a late filing.
				this.binnenTermijn = null
			} finally {
				this.probing = false
			}
		},
		/**
		 * Validate and submit the bezwaarschrift (REQ-POR-008).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/zaakportaal-mijngemeente/spec.md
		 */
		async onSubmit() {
			this.fieldError = ''
			this.successMessage = ''
			const { valid, errors } = validateBezwaar({
				tegenZaakId: this.caseId,
				decisionDate: this.decisionDate,
				motivering: this.motivering,
				consent: this.consent,
				binnenTermijn: this.binnenTermijn,
			})
			if (!valid) {
				this.fieldError = this.t('procest', errors.motivering || errors.consent || errors.deadline || errors.decisionDate || errors.tegenZaakId)
				return
			}
			this.submitting = true
			try {
				const url = generateUrl('/apps/procest/api/portaal/objections')
				const payload = buildBezwaarPayload({
					tegenZaakId: this.caseId,
					tegenBeschikkingId: this.decisionId,
					decisionDate: this.decisionDate,
					onderwerp: this.decisionTitle,
					motivering: this.motivering,
				})
				const { data } = await axios.post(url, payload)
				const ref = (data && data.referentie) || ''
				this.successMessage = ref
					? this.t('procest', 'Your objection has been received (reference {ref}).', { ref })
					: this.t('procest', 'Your objection has been received.')
				this.motivering = ''
				this.consent = false
				this.$emit('submitted', data)
			} catch (e) {
				this.fieldError = (e && e.response && e.response.data && e.response.data.error)
					? String(e.response.data.error)
					: this.t('procest', 'Could not submit your objection. Please try again.')
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped>
.zp-bezwaar {
	margin-top: 24px;
}

.zp-bezwaar__state {
	padding: 24px;
	text-align: center;
}

.zp-bezwaar__notice {
	padding: 16px;
	border: 1px solid var(--color-warning, #c9a227);
	border-radius: var(--border-radius, 4px);
	background: var(--color-background-hover, #fdf7e3);
}

.zp-bezwaar__body {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.zp-bezwaar__meta {
	margin: 0;
	color: var(--color-text-maxcontrast, #6b6b6b);
}

.zp-bezwaar__label {
	font-weight: 600;
	font-size: 13px;
}

.zp-bezwaar__textarea {
	padding: 8px 10px;
	border: 1px solid var(--color-border-dark, #aaa);
	border-radius: var(--border-radius, 4px);
	font-family: inherit;
	resize: vertical;
	min-height: 120px;
}

.zp-bezwaar__field-error {
	margin: 0;
	color: var(--color-error, #c4341f);
	font-size: 13px;
}

.zp-bezwaar__actions {
	display: flex;
	align-items: center;
	gap: 12px;
}

.zp-bezwaar__status {
	margin: 0;
	color: var(--color-success, #46ba61);
	font-size: 13px;
}
</style>
