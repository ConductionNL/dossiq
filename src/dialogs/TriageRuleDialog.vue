<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Add or change a triage rule: a name, what it marks documents as, and the
  conditions a document must all meet.

  Custodian and source system read the document's provenance on the case,
  sender domain its mail headers, file type and dates its record, and the text
  conditions the text OpenRegister already extracted. A document that lacks
  the fact is never matched, which the hint under the text conditions says.
  The server checks the rule again and names what is missing.

  @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
-->
<template>
	<NcDialog
		:name="rule ? t('dossiq', 'Change the rule') : t('dossiq', 'Add a rule')"
		data-testid="triage-rule-dialog"
		@closing="$emit('close')">
		<form class="triage-rule" @submit.prevent="save">
			<NcTextField
				v-model="name"
				data-testid="triage-rule-name"
				:label="t('dossiq', 'Name of the rule')" />

			<fieldset class="triage-rule__effect">
				<legend>{{ t('dossiq', 'A matching document is') }}</legend>
				<NcCheckboxRadioSwitch
					v-model="effect"
					value="out-of-scope"
					name="triage-rule-effect"
					type="radio"
					data-testid="triage-rule-effect-out">
					{{ t('dossiq', 'Not relevant') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					v-model="effect"
					value="in-scope"
					name="triage-rule-effect"
					type="radio"
					data-testid="triage-rule-effect-in">
					{{ t('dossiq', 'Relevant') }}
				</NcCheckboxRadioSwitch>
			</fieldset>

			<fieldset class="triage-rule__conditions">
				<legend>
					{{ t('dossiq', 'Conditions, all of which must hold') }}
				</legend>
				<NcTextField
					v-for="field in textFields"
					:key="field.key"
					v-model="conditions[field.key]"
					:data-testid="'triage-rule-' + field.key"
					:label="field.label" />
				<div class="triage-rule__dates">
					<label for="triage-rule-date-from">{{
						t('dossiq', 'Dated from')
					}}</label>
					<input
						id="triage-rule-date-from"
						v-model="conditions.dateFrom"
						type="date"
						data-testid="triage-rule-dateFrom" />
					<label for="triage-rule-date-to">{{
						t('dossiq', 'Dated up to')
					}}</label>
					<input
						id="triage-rule-date-to"
						v-model="conditions.dateTo"
						type="date"
						data-testid="triage-rule-dateTo" />
				</div>
				<p class="triage-rule__hint">
					{{
						t(
							'dossiq',
							'A document without extracted text never matches a text condition, and a mail whose headers were not read never matches a sender domain.',
						)
					}}
				</p>
			</fieldset>

			<p
				v-if="missing"
				class="triage-rule__missing"
				data-testid="triage-rule-missing">
				{{ missing }}
			</p>
			<p
				v-if="error"
				class="triage-rule__error"
				role="alert"
				data-testid="triage-rule-error">
				{{ error }}
			</p>
		</form>
		<template #actions>
			<NcButton data-testid="triage-rule-cancel" @click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="saving || missing !== ''"
				data-testid="triage-rule-save"
				@click="save">
				{{ t('dossiq', 'Save the rule') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'

/**
 * The conditions a rule can hold, empty.
 *
 * @return {object} Every condition as ''.
 */
function emptyConditions() {
	return {
		custodian: '',
		sourceSystem: '',
		senderDomain: '',
		mimeType: '',
		textContains: '',
		textNotContains: '',
		dateFrom: '',
		dateTo: '',
	}
}

export default {
	name: 'TriageRuleDialog',

	components: { NcButton, NcCheckboxRadioSwitch, NcDialog, NcTextField },

	props: {
		/** The case uuid. */
		caseId: { type: String, required: true },
		/** The rule to change, or null to add one. */
		rule: { type: Object, default: null },
	},

	emits: ['close', 'saved'],

	data() {
		return {
			name: this.rule?.name ?? '',
			effect: this.rule?.effect ?? '',
			conditions: { ...emptyConditions(), ...(this.rule?.conditions ?? {}) },
			saving: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The conditions typed as text.
		 *
		 * @return {Array<object>} Key and label per condition.
		 */
		textFields() {
			return [
				{ key: 'custodian', label: t('dossiq', 'Custodian') },
				{ key: 'sourceSystem', label: t('dossiq', 'Source system') },
				{ key: 'senderDomain', label: t('dossiq', 'Sender domain') },
				{
					key: 'mimeType',
					label: t(
						'dossiq',
						'File type, such as message/* or text/calendar',
					),
				},
				{ key: 'textContains', label: t('dossiq', 'The text contains') },
				{
					key: 'textNotContains',
					label: t('dossiq', 'The text does not contain'),
				},
			]
		},

		/**
		 * The filled-in conditions only.
		 *
		 * @return {object} The conditions to send.
		 */
		filled() {
			return Object.fromEntries(
				Object.entries(this.conditions)
					.map(([key, value]) => [key, String(value ?? '').trim()])
					.filter(([, value]) => value !== ''),
			)
		},

		/**
		 * What the rule still needs before it can be saved, or ''.
		 *
		 * @return {string} The sentence.
		 *
		 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
		 */
		missing() {
			if (this.name.trim() === '') {
				return t('dossiq', 'Give the rule a name.')
			}
			if (this.effect === '') {
				return t(
					'dossiq',
					'Choose whether the rule marks documents in scope or out of scope.',
				)
			}
			if (Object.keys(this.filled).length === 0) {
				return t(
					'dossiq',
					'Give the rule at least one condition, or it would mark every document.',
				)
			}
			return ''
		},
	},

	methods: {
		t,

		/**
		 * Store the rule: a new one, or the change to this one.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
		 */
		async save() {
			if (this.missing !== '' || this.saving) {
				return
			}
			this.saving = true
			this.error = ''
			const base = `/apps/dossiq/api/cases/${encodeURIComponent(this.caseId)}/review/triage/rules`
			const body = {
				name: this.name.trim(),
				effect: this.effect,
				conditions: this.filled,
			}
			try {
				const { data } = this.rule?.id
					? await axios.put(
							generateUrl(
								`${base}/${encodeURIComponent(this.rule.id)}`,
							),
							body,
						)
					: await axios.post(generateUrl(base), body)
				this.$emit('saved', data)
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| t(
						'dossiq',
						'The triage rules cannot be stored or applied, so nothing was marked.',
					)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.triage-rule {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.triage-rule__conditions {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.triage-rule__dates {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
}

.triage-rule__hint,
.triage-rule__missing {
	color: var(--color-text-maxcontrast);
}

.triage-rule__error {
	color: var(--color-error-text);
}
</style>
