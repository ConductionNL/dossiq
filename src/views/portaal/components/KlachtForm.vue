<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  -
  - Klacht (complaint) intake form for the "Mijn gemeente" portal
  - (zaakportaal-mijngemeente, REQ-POR-009). A standalone form, independent of
  - any case: the citizen picks a category, describes the complaint and submits
  - to POST /api/portaal/complaints, which creates a klacht PortaalVerzoek and
  - returns a citizen-facing reference. The category set mirrors the backend's
  - PortalRequestService::KLACHT_CATEGORIES; validation + body shaping live in
  - utils/portaalForms.js. The submitter identity is derived server-side.
-->
<template>
	<section class="zp-klacht" data-testid="portaal-klacht-form">
		<h3>{{ t('procest', 'File a complaint') }}</h3>

		<form class="zp-klacht__body" data-testid="portaal-klacht-fields" @submit.prevent="onSubmit">
			<div class="zp-klacht__field">
				<NcSelect v-model="categorie"
					:options="categoryOptions"
					:input-label="t('procest', 'Category')"
					:placeholder="t('procest', 'Choose a category')"
					data-testid="portaal-klacht-category" />
			</div>

			<label for="zp-klacht-omschrijving" class="zp-klacht__label">
				{{ t('procest', 'Description') }}
			</label>
			<textarea id="zp-klacht-omschrijving"
				v-model="omschrijving"
				rows="6"
				class="zp-klacht__textarea"
				data-testid="portaal-klacht-description"
				:placeholder="t('procest', 'Describe your complaint…')" />

			<label for="zp-klacht-medewerker" class="zp-klacht__label">
				{{ t('procest', 'Employee or department involved (optional)') }}
			</label>
			<input id="zp-klacht-medewerker"
				v-model="betrokkenMedewerker"
				type="text"
				class="zp-klacht__input"
				data-testid="portaal-klacht-employee">

			<p v-if="fieldError"
				class="zp-klacht__field-error"
				role="alert"
				data-testid="portaal-klacht-validation">
				{{ fieldError }}
			</p>

			<div class="zp-klacht__actions">
				<NcButton type="primary"
					native-type="submit"
					:disabled="submitting"
					data-testid="portaal-klacht-submit">
					{{ submitting ? t('procest', 'Submitting…') : t('procest', 'Submit complaint') }}
				</NcButton>
				<p v-if="successMessage"
					class="zp-klacht__status"
					role="status"
					data-testid="portaal-klacht-success">
					{{ successMessage }}
				</p>
			</div>
		</form>
	</section>
</template>

<script>
import { NcButton, NcSelect } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { validateKlacht, buildKlachtPayload, KLACHT_CATEGORIES } from '../../../utils/portaalForms.js'

export default {
	name: 'KlachtForm',
	components: {
		NcButton,
		NcSelect,
	},
	data() {
		return {
			categorie: '',
			omschrijving: '',
			betrokkenMedewerker: '',
			fieldError: '',
			submitting: false,
			successMessage: '',
		}
	},
	computed: {
		categoryOptions() {
			return KLACHT_CATEGORIES
		},
	},
	methods: {
		/**
		 * Validate and submit the klacht (REQ-POR-009).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/zaakportaal-mijngemeente/spec.md
		 */
		async onSubmit() {
			this.fieldError = ''
			this.successMessage = ''
			const { valid, errors } = validateKlacht({
				categorie: this.categorie,
				omschrijving: this.omschrijving,
			})
			if (!valid) {
				this.fieldError = this.t('procest', errors.categorie || errors.omschrijving)
				return
			}
			this.submitting = true
			try {
				const url = generateUrl('/apps/procest/api/portaal/complaints')
				const payload = buildKlachtPayload({
					categorie: this.categorie,
					omschrijving: this.omschrijving,
					betrokkenMedewerker: this.betrokkenMedewerker,
				})
				const { data } = await axios.post(url, payload)
				const ref = (data && data.referentie) || ''
				this.successMessage = ref
					? this.t('procest', 'Your complaint has been received. Reference: {ref}', { ref })
					: this.t('procest', 'Your complaint has been received.')
				this.categorie = ''
				this.omschrijving = ''
				this.betrokkenMedewerker = ''
				this.$emit('submitted', data)
			} catch (e) {
				this.fieldError = (e && e.response && e.response.data && e.response.data.error)
					? String(e.response.data.error)
					: this.t('procest', 'Could not submit your complaint. Please try again.')
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped>
.zp-klacht {
	margin-top: 24px;
}

.zp-klacht__body {
	display: flex;
	flex-direction: column;
	gap: 8px;
	max-width: 640px;
}

.zp-klacht__field {
	margin-bottom: 4px;
}

.zp-klacht__label {
	font-weight: 600;
	font-size: 13px;
}

.zp-klacht__textarea {
	padding: 8px 10px;
	border: 1px solid var(--color-border-dark, #aaa);
	border-radius: var(--border-radius, 4px);
	font-family: inherit;
	resize: vertical;
	min-height: 120px;
}

.zp-klacht__input {
	padding: 8px 10px;
	border: 1px solid var(--color-border-dark, #aaa);
	border-radius: var(--border-radius, 4px);
	font-family: inherit;
}

.zp-klacht__field-error {
	margin: 0;
	color: var(--color-error, #c4341f);
	font-size: 13px;
}

.zp-klacht__actions {
	display: flex;
	align-items: center;
	gap: 12px;
}

.zp-klacht__status {
	margin: 0;
	color: var(--color-success, #46ba61);
	font-size: 13px;
}
</style>
