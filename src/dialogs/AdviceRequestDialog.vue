<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Ask a colleague or an outside body for advice on this case (board
  DqZaakBesluiten, "Advies vragen").

  Internal advice names a person in this Nextcloud; external advice names the
  organisation, typed in. The answer date starts two weeks from today. The
  server stores the request, gives an internal adviser the task to answer it
  and writes the history line; a refusal is shown in its own words and the
  dialog stays open.

  Opened with `{ type: "open-modal", target: "AdviceRequestDialog", props:
  { caseId: "@objectId" } }`; an unresolved token falls back to the route.

  @spec openspec/specs/advice-management/spec.md
-->
<template>
	<CnDialog
		:name="t('dossiq', 'Ask for advice')"
		:eyebrow="eyebrow"
		width="form"
		:noClose="busy"
		data-testid="advice-request-dialog"
		v-bind="noticeProps"
		@closing="$emit('close')">
		<form class="advice-request" @submit.prevent="submit">
			<CnSegmentedControl
				v-model="adviceKind"
				:options="kindOptions"
				:ariaLabel="t('dossiq', 'Kind of advice')"
				data-testid="advice-request-kind" />

			<div class="advice-request__field">
				<NcSelect
					v-if="adviceKind === 'internal'"
					v-model="adviser"
					data-testid="advice-request-adviser"
					:inputLabel="t('dossiq', 'Adviser')"
					:options="found"
					:loading="searching"
					:filterBy="() => true"
					label="label"
					:disabled="busy"
					@search="onSearch" />
				<NcTextField
					v-else
					v-model="organisation"
					data-testid="advice-request-organisation"
					:label="t('dossiq', 'Adviser')"
					:disabled="busy" />
				<p class="advice-request__hint">
					{{ t('dossiq', 'For external advice you type the organisation.') }}
				</p>
			</div>

			<NcTextField
				v-model="subject"
				data-testid="advice-request-subject"
				:label="t('dossiq', 'Subject')"
				:disabled="busy" />

			<div class="advice-request__field">
				<NcDateTimePicker
					v-model="deadline"
					data-testid="advice-request-deadline"
					type="date"
					:min="today"
					:label="t('dossiq', 'Answer at the latest')" />
				<p class="advice-request__hint">
					{{ t('dossiq', 'Filled in at two weeks from today.') }}
				</p>
			</div>

			<NcTextArea
				v-model="question"
				data-testid="advice-request-question"
				:label="t('dossiq', 'Specific questions')"
				:disabled="busy" />

			<DialogNotice :notice="notice" />
		</form>

		<template #actions>
			<NcButton :disabled="busy" @click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				data-testid="advice-request-confirm"
				:disabled="!canSubmit"
				@click="submit">
				{{ t('dossiq', 'Ask for advice') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog, CnSegmentedControl } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTimePicker from '@nextcloud/vue/components/NcDateTimePicker'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import DialogNotice from '../components/dialog/DialogNotice.vue'
import { caseEyebrow, fetchCaseSummary } from '../services/caseSummary.js'
import { searchUsers } from '../services/userSearch.js'
import { dialogNotice, dialogNoticeProps } from '../utils/dialogNotice.js'
import { resolveCaseId } from '../utils/resolveCaseId.js'

/** Days from today the answer date starts at. */
const DEFAULT_ANSWER_DAYS = 14

/**
 * A date as YYYY-MM-DD in the reader's own day.
 *
 * @param {Date} date The date.
 * @return {string} The calendar date.
 */
function calendarDate(date) {
	const pad = (value) => String(value).padStart(2, '0')
	return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

export default {
	name: 'AdviceRequestDialog',

	components: {
		CnDialog,
		CnSegmentedControl,
		DialogNotice,
		NcButton,
		NcDateTimePicker,
		NcSelect,
		NcTextArea,
		NcTextField,
	},

	props: {
		caseId: {
			type: String,
			default: '',
		},

		kind: {
			type: String,
			default: 'internal',
		},
	},

	emits: ['close', 'requested'],

	data() {
		const today = new Date()
		today.setHours(0, 0, 0, 0)
		const deadline = new Date(today)
		deadline.setDate(deadline.getDate() + DEFAULT_ANSWER_DAYS)

		return {
			adviceKind: this.kind === 'external' ? 'external' : 'internal',
			adviser: null,
			organisation: '',
			subject: '',
			question: '',
			today,
			deadline,
			found: [],
			searching: false,
			busy: false,
			error: '',
			kase: null,
		}
	},

	computed: {
		targetCaseId() {
			return resolveCaseId(this.caseId, this.$route)
		},

		eyebrow() {
			return caseEyebrow(t('dossiq', 'Advice'), this.kase)
		},

		kindOptions() {
			return [
				{ value: 'internal', label: t('dossiq', 'Internal') },
				{ value: 'external', label: t('dossiq', 'External') },
			]
		},

		adviserId() {
			return this.adviceKind === 'internal'
				? String(this.adviser?.id ?? '')
				: this.organisation.trim()
		},

		canSubmit() {
			return !this.busy && this.targetCaseId !== '' && this.adviserId !== '' && this.deadline instanceof Date
		},

		notice() {
			return this.error ? dialogNotice('error', t('dossiq', 'Advice not requested'), this.error) : null
		},

		noticeProps() {
			return dialogNoticeProps(this.notice)
		},
	},

	mounted() {
		fetchCaseSummary(this.targetCaseId).then((kase) => {
			this.kase = kase
		})
	},

	methods: {
		t,

		async onSearch(term) {
			this.searching = true
			this.found = await searchUsers(term)
			this.searching = false
		},

		/**
		 * Post the request; the server stores it, tasks an internal adviser
		 * and writes the history line.
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
				const { data } = await axios.post(
					generateUrl(`/apps/dossiq/api/cases/${encodeURIComponent(this.targetCaseId)}/advice-requests`),
					{
						kind: this.adviceKind,
						advisor: this.adviserId,
						subject: this.subject.trim(),
						deadline: calendarDate(this.deadline),
						question: this.question.trim(),
					},
				)
				emit('dossiq:advice:requested', { caseId: this.targetCaseId, advice: data })
				emit('cn:page:refresh', {})
				this.$emit('requested', data)
				this.$emit('close')
			} catch (e) {
				this.error = String(e?.response?.data?.error || t('dossiq', 'The advice request could not be saved.'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.advice-request {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.advice-request__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.advice-request__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>
