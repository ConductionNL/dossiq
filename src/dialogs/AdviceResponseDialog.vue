<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The consulted department gives its advice on a case (board DqZaak,
  "Advies uitbrengen").

  A conclusion, a summary, and the conditions the department's form asks it
  to check. Sending marks the advice received; the handler gets a notification
  with the summary. A refusal is shown in its own words and the dialog stays
  open.

  Opened with `{ type: "open-modal", target: "AdviceResponseDialog", props:
  { adviceId, caseId?, advisingBody?, reference?, conditions? } }`; an
  unresolved `@objectId` falls back to the route (the advice request page).
  Which conditions a department checks is its form's configuration, never a
  list in code.

  @spec openspec/specs/advice-management/spec.md
-->
<template>
	<CnDialog
		:name="t('dossiq', 'Give advice')"
		:eyebrow="eyebrow"
		:subtitle="subtitle"
		width="form"
		:noClose="busy"
		data-testid="advice-response-dialog"
		v-bind="noticeProps"
		@closing="$emit('close')">
		<form class="advice-response" @submit.prevent="submit">
			<fieldset class="advice-response__group" data-testid="advice-response-conclusion">
				<legend>{{ t('dossiq', 'Conclusion') }}</legend>
				<NcCheckboxRadioSwitch
					v-for="option in conclusions"
					:key="option.value"
					v-model="conclusion"
					type="radio"
					name="advice-conclusion"
					:value="option.value"
					:disabled="busy">
					{{ option.label }}
				</NcCheckboxRadioSwitch>
			</fieldset>

			<NcTextArea
				v-model="summary"
				data-testid="advice-response-summary"
				:label="t('dossiq', 'Summary')"
				:disabled="busy" />

			<fieldset
				v-if="checklist.length > 0"
				class="advice-response__group"
				data-testid="advice-response-conditions">
				<legend>{{ t('dossiq', 'Checklist of conditions') }}</legend>
				<NcCheckboxRadioSwitch
					v-for="condition in checklist"
					:key="condition"
					:modelValue="checked.includes(condition)"
					:disabled="busy"
					@update:modelValue="toggle(condition, $event)">
					{{ condition }}
				</NcCheckboxRadioSwitch>
			</fieldset>

			<p class="advice-response__hint">
				{{ t('dossiq', 'After sending: the advice is marked as given, and the handler gets a notification with the summary.') }}
			</p>

			<DialogNotice :notice="notice" />
		</form>

		<template #actions>
			<NcButton :disabled="busy" @click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				data-testid="advice-response-confirm"
				:disabled="!canSubmit"
				@click="submit">
				{{ t('dossiq', 'Send advice') }}
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
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import DialogNotice from '../components/dialog/DialogNotice.vue'
import { caseEyebrow, fetchCaseSummary } from '../services/caseSummary.js'
import { dialogNotice, dialogNoticeProps } from '../utils/dialogNotice.js'
import { resolveCaseId } from '../utils/resolveCaseId.js'

export default {
	name: 'AdviceResponseDialog',

	components: {
		CnDialog,
		DialogNotice,
		NcButton,
		NcCheckboxRadioSwitch,
		NcTextArea,
	},

	props: {
		// May arrive as the unresolved `@objectId` token; see targetAdviceId.
		adviceId: {
			type: String,
			default: '',
		},

		caseId: {
			type: String,
			default: '',
		},

		advisingBody: {
			type: String,
			default: '',
		},

		reference: {
			type: String,
			default: '',
		},

		conditions: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['close', 'responded'],

	data() {
		return {
			conclusion: '',
			summary: '',
			checked: [],
			busy: false,
			error: '',
			kase: null,
		}
	},

	computed: {
		targetAdviceId() {
			return resolveCaseId(this.adviceId, this.$route)
		},

		targetCaseId() {
			const given = String(this.caseId ?? '')
			return given !== '' && !given.startsWith('@') ? given : ''
		},

		eyebrow() {
			return caseEyebrow(t('dossiq', 'Advice'), this.kase)
		},

		subtitle() {
			if (this.advisingBody !== '' && this.reference !== '') {
				return t('dossiq', 'The form of the consulted department, here {department}, for {reference}.', { department: this.advisingBody, reference: this.reference })
			}
			return t('dossiq', 'The form of the consulted department.')
		},

		conclusions() {
			return [
				{ value: 'positive', label: t('dossiq', 'Positive') },
				{ value: 'positive-with-conditions', label: t('dossiq', 'Positive with conditions') },
				{ value: 'negative', label: t('dossiq', 'Negative') },
				{ value: 'clarification', label: t('dossiq', 'Ask for clarification') },
			]
		},

		checklist() {
			return this.conditions.filter((condition) => typeof condition === 'string' && condition.trim() !== '')
		},

		canSubmit() {
			return !this.busy && this.targetAdviceId !== '' && this.conclusion !== '' && this.summary.trim() !== ''
		},

		notice() {
			return this.error ? dialogNotice('error', t('dossiq', 'Advice not sent'), this.error) : null
		},

		noticeProps() {
			return dialogNoticeProps(this.notice)
		},
	},

	mounted() {
		if (this.targetCaseId !== '') {
			fetchCaseSummary(this.targetCaseId).then((kase) => {
				this.kase = kase
			})
		}
	},

	methods: {
		t,

		toggle(condition, on) {
			this.checked = on
				? [...new Set([...this.checked, condition])]
				: this.checked.filter((entry) => entry !== condition)
		},

		/**
		 * Send the advice: the request moves to received with the conclusion,
		 * the summary and the conditions that were checked.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/advice-management/spec.md
		 */
		async submit() {
			if (!this.canSubmit) {
				return
			}
			this.busy = true
			this.error = ''
			try {
				await axios.post(
					generateUrl(`/apps/dossiq/api/advice/${encodeURIComponent(this.targetAdviceId)}/transition`),
					{
						to: 'received',
						conclusion: this.conclusion,
						adviceText: this.summary.trim(),
						conditionsChecked: this.checked,
					},
				)
				emit('dossiq:advice:responded', { adviceId: this.targetAdviceId, caseId: this.targetCaseId, conclusion: this.conclusion })
				emit('cn:page:refresh', {})
				this.$emit('responded', this.conclusion)
				this.$emit('close')
			} catch (e) {
				this.error = String(e?.response?.data?.error || t('dossiq', 'The advice could not be sent.'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.advice-response {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.advice-response__group {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin: 0;
	padding: 0;
	border: 0;
}

.advice-response__group legend {
	margin-bottom: 4px;
	font-weight: 600;
}

.advice-response__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>
